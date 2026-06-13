<?php

namespace App\Livewire\Tools;

use App\Jobs\RunTrackedPersonInstagramToolScan;
use App\Models\InstagramProfileListScan;
use App\Models\Setting;
use App\Models\TrackedPerson;
use App\Models\TrackedPersonInstagramPublicProfileScan;
use App\Models\TrackedPersonInstagramSuggestionScan;
use App\Services\Ai\InvestigationAssistantScanStatusStore;
use App\Services\Ai\InvestigationAssistantToolService;
use App\Services\TrackedPeople\TrackedPersonInstagramScanCoordinator;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Psr\Http\Message\StreamInterface;

class Chatbot extends Component
{
    use WithFileUploads;

    private const DISPLAY_HISTORY_KEY = 'investigation_assistant_display_history';

    private const TRANSCRIPT_KEY = 'investigation_assistant_transcript';

    private const TOOL_EVENTS_KEY = 'investigation_assistant_tool_events';

    private const SCAN_ACTIVITIES_KEY = 'investigation_assistant_scan_activities';

    public string $message = '';

    public array $chatHistory = [];

    public array $toolEvents = [];

    public array $scanActivities = [];

    public array $uploads = [];

    public array $pageContext = [];

    public bool $isLoading = false;

    public $status;

    public $assistantName;

    public $apiUrl;

    public $aiModel;

    public $modelTitle;

    public $refererUrl;

    public $trainContent;

    protected $listeners = [
        'sendMessage' => 'sendMessage',
        'assistantQuickAction' => 'quickAction',
    ];

    public function mount(): void
    {
        $this->chatHistory = Session::get(self::DISPLAY_HISTORY_KEY, []);
        $this->toolEvents = [];
        $this->scanActivities = Session::get(self::SCAN_ACTIVITIES_KEY, []);
        Session::forget(self::TOOL_EVENTS_KEY);
        $this->status = (bool) Setting::getValue('ai_assistant', 'status');
        $this->assistantName = Setting::getValue('ai_assistant', 'assistant_name') ?: 'Investigation Copilot';
        $this->apiUrl = Setting::getValue('ai_assistant', 'api_url');
        $this->aiModel = Setting::getValue('ai_assistant', 'ai_model');
        $this->modelTitle = Setting::getValue('ai_assistant', 'model_title') ?: config('app.name');
        $this->refererUrl = Setting::getValue('ai_assistant', 'referer_url') ?: config('app.url');
        $this->trainContent = Setting::getValue('ai_assistant', 'train_content');
        $this->pageContext = $this->initialPageContext();
    }

    public function updatePageContext(array $context): void
    {
        $this->pageContext = $this->normalizePageContext([
            ...$this->pageContext,
            ...$context,
        ]);
    }

    public function quickAction(string $prompt): void
    {
        $this->message = $prompt;
        $this->sendMessage();
    }

    public function sendChatOption(int $messageIndex, int $optionIndex): void
    {
        $message = $this->chatHistory[$messageIndex] ?? null;
        $option = is_array($message) ? data_get($message, "options.{$optionIndex}") : null;

        if (
            ! is_array($message)
            || ($message['role'] ?? null) !== 'assistant'
            || (array_key_exists('selected_option_index', $message) && $message['selected_option_index'] !== null)
            || ! is_array($option)
            || blank($option['prompt'] ?? null)
        ) {
            return;
        }

        $this->chatHistory[$messageIndex]['selected_option_index'] = $optionIndex;
        Session::put(self::DISPLAY_HISTORY_KEY, $this->chatHistory);
        $this->sendMessage((string) $option['prompt']);
    }

    public function sendMessage(?string $clientMessage = null): void
    {
        if ($clientMessage !== null) {
            $this->message = $clientMessage;
        }

        $userMessage = trim($this->message);
        $attachmentContext = $this->buildAttachmentContext();

        if ($userMessage === '' && $attachmentContext === '') {
            return;
        }

        $this->message = '';
        $this->isLoading = true;
        $displayMessage = $userMessage !== ''
            ? $this->displayMessageForUserPrompt($userMessage)
            : 'Dateien zur Analyse hinzugefuegt.';

        if ($attachmentContext !== '') {
            $displayMessage .= "\n\n".'Anhang-Kontext wurde der AI mitgegeben.';
        }

        $this->appendDisplayMessage('user', $displayMessage);

        try {
            $assistantResponse = $this->runAssistantConversation(trim($userMessage."\n\n".$attachmentContext));
            $assistantMessage = $assistantResponse['message'] ?? '';
            $this->appendDisplayMessage(
                'assistant',
                $assistantMessage ?: 'Ich habe dazu gerade keine belastbare Antwort erhalten.',
                'neutral',
                $assistantResponse['chat_options'] ?? null,
            );

            if (is_array($assistantResponse['ui_action'] ?? null)) {
                $this->dispatch('assistant-ui-action', action: $assistantResponse['ui_action']);
            }
        } catch (\Throwable $exception) {
            Log::warning('Investigation Assistant fehlgeschlagen.', [
                'error' => $exception->getMessage(),
            ]);

            $this->appendDisplayMessage(
                'assistant',
                'Der AI-Assistent konnte die Anfrage nicht abschliessen: '.$exception->getMessage(),
                'error',
            );
        } finally {
            $this->uploads = [];
            $this->isLoading = false;
        }
    }

    public function clearChat(): void
    {
        Session::forget([
            self::DISPLAY_HISTORY_KEY,
            self::TRANSCRIPT_KEY,
            self::TOOL_EVENTS_KEY,
        ]);

        $this->chatHistory = [];
        $this->toolEvents = [];
        $this->uploads = [];
        $this->message = '';
    }

    public function dismissToolEvent(string $eventId): void
    {
        $this->toolEvents = collect($this->toolEvents)
            ->reject(fn (array $event): bool => ($event['id'] ?? null) === $eventId)
            ->values()
            ->all();
    }

    public function pollAssistantScans(): void
    {
        $user = Auth::user();

        if (! $user || $this->scanActivities === []) {
            return;
        }

        $statusStore = app(InvestigationAssistantScanStatusStore::class);
        $scanCoordinator = app(TrackedPersonInstagramScanCoordinator::class);
        $terminalScan = null;

        foreach ($this->scanActivities as $index => $activity) {
            $token = (string) ($activity['token'] ?? '');

            if ($token === '') {
                continue;
            }

            $status = $statusStore->get($token);

            if (! $status || (int) ($status['user_id'] ?? 0) !== (int) $user->id) {
                continue;
            }

            $scanState = $scanCoordinator->activeState((int) ($status['tracked_person_id'] ?? 0));
            $stopRequested = (bool) ($scanState['gracefulStopRequested'] ?? false)
                || (bool) ($status['stop_requested'] ?? false);
            $scanIsActive = $scanCoordinator->hasActiveScan((int) ($status['tracked_person_id'] ?? 0));

            if ($stopRequested && $scanIsActive) {
                $status = $statusStore->stopping(
                    $token,
                    'Stop wurde erkannt. Der aktuelle Zwischenstand wird gespeichert.',
                );
            } elseif (
                in_array($status['status'] ?? null, ['running', 'stopping'], true)
                && ! $scanIsActive
                && ($stopRequested || $this->assistantScanLooksInterrupted($status, 8))
            ) {
                $status = $statusStore->pause(
                    $token,
                    $stopRequested
                        ? 'Der Scan wurde gestoppt. Der bisherige Datenstand wurde gespeichert.'
                        : 'Der Scanprozess antwortet nicht mehr. Der bisher gespeicherte Datenstand kann fortgesetzt oder abgeschlossen werden.',
                    is_array($status['result'] ?? null) ? $status['result'] : [],
                );
            }

            $this->scanActivities[$index] = [
                ...$activity,
                ...$status,
            ];

            if (
                $terminalScan === null
                && in_array($status['status'] ?? null, ['completed', 'error', 'cancelled'], true)
                && ! (bool) ($activity['reacted'] ?? false)
            ) {
                $this->scanActivities[$index]['reacted'] = true;
                $terminalScan = $this->scanActivities[$index];
            }
        }

        $this->persistScanActivities();

        if (! $terminalScan) {
            return;
        }

        $successful = ($terminalScan['status'] ?? null) === 'completed';
        $this->appendTransientToolAlert(
            (string) ($terminalScan['label'] ?? 'Instagram-Scan'),
            (string) ($terminalScan['message'] ?? ($successful ? 'Scan abgeschlossen.' : 'Scan beendet.')),
            $successful,
        );
        $this->reactToCompletedScan($terminalScan);

        $terminalToken = (string) ($terminalScan['token'] ?? '');
        $this->scanActivities = collect($this->scanActivities)
            ->reject(fn (array $activity): bool => ($activity['token'] ?? null) === $terminalToken)
            ->values()
            ->all();
        $this->persistScanActivities();
    }

    public function resumeAssistantScan(string $token): void
    {
        $user = Auth::user();
        $statusStore = app(InvestigationAssistantScanStatusStore::class);
        $status = $statusStore->get($token);
        $scanType = strtolower(trim((string) ($status['scan_type'] ?? '')));
        $trackedPersonId = (int) ($status['tracked_person_id'] ?? 0);
        $supportedTypes = ['mini', 'full', 'followers', 'following', 'suggestions', 'suggestion_deepsearch', 'posts', 'public_connections'];

        if (
            ! $user
            || ! $status
            || (int) ($status['user_id'] ?? 0) !== (int) $user->id
            || ($status['status'] ?? null) !== 'paused'
            || ! in_array($scanType, $supportedTypes, true)
        ) {
            return;
        }

        $trackedPerson = TrackedPerson::query()
            ->whereKey($trackedPersonId)
            ->where('user_id', $user->id)
            ->first();

        if (! $trackedPerson || ! $trackedPerson->instagram_username) {
            return;
        }

        $newToken = (string) Str::uuid();
        $label = (string) ($status['label'] ?? 'Instagram-Scan');
        $tracking = $statusStore->start($newToken, [
            'user_id' => (int) $user->id,
            'tracked_person_id' => (int) $trackedPerson->id,
            'instagram_username' => $trackedPerson->instagram_username,
            'scan_type' => $scanType,
            'label' => $label,
            'message' => $label.' wird ab dem gespeicherten Datenstand fortgesetzt.',
            'resumed_from_token' => $token,
        ]);

        $statusStore->dismiss($token, 'Scan wurde in einem neuen Lauf fortgesetzt.');
        RunTrackedPersonInstagramToolScan::dispatch((int) $trackedPerson->id, $scanType, false, $newToken);

        $this->scanActivities = collect($this->scanActivities)
            ->reject(fn (array $activity): bool => ($activity['token'] ?? null) === $token)
            ->push([
                ...$tracking,
                'reacted' => false,
            ])
            ->take(-4)
            ->values()
            ->all();
        $this->persistScanActivities();
    }

    public function dismissAssistantScan(string $token): void
    {
        $user = Auth::user();
        $statusStore = app(InvestigationAssistantScanStatusStore::class);
        $status = $statusStore->get($token);

        if (
            ! $user
            || ! $status
            || (int) ($status['user_id'] ?? 0) !== (int) $user->id
            || ($status['status'] ?? null) !== 'paused'
        ) {
            return;
        }

        $this->dismissPersistedResumeState(
            (int) ($status['tracked_person_id'] ?? 0),
            (string) ($status['scan_type'] ?? ''),
            (int) $user->id,
        );
        $statusStore->dismiss($token, 'Scan wurde beendet. Die bisher gespeicherten Daten bleiben erhalten.');
        $this->scanActivities = collect($this->scanActivities)
            ->reject(fn (array $activity): bool => ($activity['token'] ?? null) === $token)
            ->values()
            ->all();
        $this->persistScanActivities();
        $this->appendTransientToolAlert(
            (string) ($status['label'] ?? 'Instagram-Scan'),
            'Scan beendet. Die bisher gespeicherten Daten bleiben erhalten.',
            true,
        );
    }

    public function render()
    {
        return view('livewire.tools.chatbot');
    }

    private function runAssistantConversation(string $userMessage): array
    {
        $apiKey = trim((string) (Setting::getValue('ai_assistant', 'api_key') ?? ''));

        if (! $this->assistantIsConfigured($apiKey)) {
            return [
                'message' => 'Der AI-Assistent ist noch nicht vollstaendig konfiguriert. Bitte API-URL, API-Key und Modell im Adminbereich hinterlegen.',
                'ui_action' => null,
                'chat_options' => null,
            ];
        }

        /** @var InvestigationAssistantToolService $toolService */
        $toolService = app(InvestigationAssistantToolService::class);
        $transcript = $this->baseTranscript($toolService);
        $currentContext = $toolService->contextualPageContext(Auth::user(), $this->pageContext);
        $transcript[] = [
            'role' => 'user',
            'content' => trim(implode("\n\n", [
                'Aktueller Seiten- und Arbeitskontext (automatisch ermittelt):',
                json_encode($currentContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'Aktuelle Nutzernachricht:',
                $userMessage,
            ])),
        ];

        $finalMessage = '';
        $uiAction = null;
        $chatOptions = null;

        for ($step = 0; $step < 5; $step++) {
            $this->stream('assistant-response-stream', '', true);
            $assistantResponse = $this->requestAssistant(
                $transcript,
                $toolService->tools(),
                $apiKey,
                function (string $chunk): void {
                    $chunk = $this->sanitizeAssistantChunk($chunk);

                    if ($chunk !== '') {
                        $this->stream('assistant-response-stream', e($chunk));
                    }
                },
            );
            $message = data_get($assistantResponse, 'choices.0.message', []);
            $toolCalls = $this->normalizeToolCalls($message['tool_calls'] ?? []);
            $content = trim((string) ($message['content'] ?? ''));

            if ($toolCalls === []) {
                $finalMessage = $content;
                $transcript[] = [
                    'role' => 'assistant',
                    'content' => $content,
                ];
                break;
            }

            $transcript[] = [
                'role' => 'assistant',
                'content' => $content !== '' ? $content : null,
                'tool_calls' => $toolCalls,
            ];

            foreach ($toolCalls as $toolCall) {
                $toolName = (string) data_get($toolCall, 'function.name');
                $arguments = $this->decodeToolArguments((string) data_get($toolCall, 'function.arguments', '{}'));
                $result = $toolService->execute($toolName, $arguments, Auth::user());
                $this->appendToolEvent($toolName, $arguments, $result);

                if (($result['ok'] ?? false) && is_array($result['ui_action'] ?? null)) {
                    $uiAction = $result['ui_action'];
                }

                if (($result['ok'] ?? false) && is_array($result['chat_options'] ?? null)) {
                    $chatOptions = $this->normalizeChatOptions($result['chat_options']);
                }

                $transcript[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'] ?? ('tool-'.$step),
                    'name' => $toolName,
                    'content' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }
        }

        Session::put(self::TRANSCRIPT_KEY, $this->trimTranscript($transcript));

        return [
            'message' => $this->sanitizeAssistantText($finalMessage),
            'ui_action' => $uiAction,
            'chat_options' => $chatOptions,
        ];
    }

    private function buildAttachmentContext(): string
    {
        if ($this->uploads === []) {
            return '';
        }

        $files = collect($this->uploads)
            ->filter(fn ($file): bool => $file instanceof TemporaryUploadedFile)
            ->take(4)
            ->values();

        if ($files->isEmpty()) {
            return '';
        }

        $blocks = [];
        $remainingCharacters = 24000;

        foreach ($files as $file) {
            $name = $file->getClientOriginalName();
            $extension = strtolower((string) $file->getClientOriginalExtension());
            $mime = (string) $file->getMimeType();
            $size = (int) $file->getSize();
            $text = '';

            if ($this->attachmentIsReadableText($extension, $mime)) {
                $raw = @file_get_contents($file->getRealPath());
                $text = is_string($raw) ? $this->normalizeAttachmentText($raw) : '';
            }

            if ($text !== '' && $remainingCharacters > 0) {
                $snippet = mb_substr($text, 0, $remainingCharacters);
                $remainingCharacters -= mb_strlen($snippet);
                $blocks[] = "Datei: {$name}\nMIME: {$mime}\nGroesse: {$size} Bytes\nInhalt:\n{$snippet}";
            } else {
                $blocks[] = "Datei: {$name}\nMIME: {$mime}\nGroesse: {$size} Bytes\nHinweis: Kein direkt lesbarer Text extrahiert. Nutze Dateiname und Nutzernachricht als Kontext.";
            }
        }

        return "Vom Nutzer hinzugefuegte Dateien als Kontext:\n\n".implode("\n\n---\n\n", $blocks);
    }

    private function attachmentIsReadableText(string $extension, string $mime): bool
    {
        if (str_starts_with($mime, 'text/')) {
            return true;
        }

        return in_array($extension, ['txt', 'csv', 'json', 'md', 'log', 'xml', 'html', 'yaml', 'yml'], true)
            || in_array($mime, ['application/json', 'application/xml', 'application/x-yaml'], true);
    }

    private function normalizeAttachmentText(string $text): string
    {
        $text = preg_replace('/\x00+/', '', $text) ?? $text;
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace("/\R{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function baseTranscript(InvestigationAssistantToolService $toolService): array
    {
        $transcript = Session::get(self::TRANSCRIPT_KEY, []);

        if (! is_array($transcript) || $transcript === []) {
            $transcript = [
                [
                    'role' => 'system',
                    'content' => trim(implode("\n\n", array_filter([
                        $toolService->systemPrompt(),
                        is_string($this->trainContent) ? trim($this->trainContent) : '',
                        'Beziehe dich bei Woertern wie "hier", "dieses Profil" oder "die Networkmap" immer auf den automatisch mitgesendeten aktuellen Seiten- und Arbeitskontext.',
                    ]))),
                ],
            ];
        }

        return $this->trimTranscript($transcript);
    }

    private function requestAssistant(
        array $messages,
        array $tools,
        string $apiKey,
        ?callable $onTextDelta = null,
    ): array {
        $apiUrl = trim((string) $this->apiUrl);

        $response = Http::acceptJson()
            ->asJson()
            ->withToken($apiKey)
            ->withHeaders(array_filter([
                'HTTP-Referer' => trim((string) $this->refererUrl),
                'X-Title' => trim((string) $this->modelTitle),
            ]))
            ->withoutRedirecting()
            ->connectTimeout(15)
            ->timeout(90)
            ->withOptions([
                'stream' => true,
                'http_errors' => false,
            ])
            ->post($apiUrl, [
                'model' => $this->aiModel,
                'messages' => $messages,
                'tools' => $tools,
                'tool_choice' => 'auto',
                'temperature' => 0.2,
                'stream' => true,
            ]);

        if ($response->redirect()) {
            $location = (string) $response->header('Location');

            throw new \RuntimeException(
                "Die AI-API-URL leitet weiter (HTTP {$response->status()})"
                .($location !== '' ? " nach {$location}" : '')
                .'. Bitte die finale Chat-Completions-URL direkt eintragen.',
            );
        }

        if ($response->status() === 401) {
            throw new \RuntimeException(
                'AI-API antwortet mit HTTP 401. Der Authorization-Header wurde gesendet, '
                .'aber vom konfigurierten Endpoint nicht akzeptiert. Bitte API-URL, Anbieter und API-Key im Adminbereich pruefen.',
            );
        }

        if (! $response->successful()) {
            throw new \RuntimeException(
                'AI-API antwortet mit HTTP '.$response->status().': '
                .mb_substr((string) $response->toPsrResponse()->getBody(), 0, 500),
            );
        }

        $body = $response->toPsrResponse()->getBody();
        $contentType = Str::lower((string) $response->header('Content-Type'));

        if (str_contains($contentType, 'text/event-stream')) {
            return $this->parseAssistantEventStream($body, $onTextDelta);
        }

        $payload = json_decode((string) $body, true);

        if (! is_array($payload)) {
            throw new \RuntimeException('AI-API lieferte kein gueltiges JSON.');
        }

        $content = data_get($payload, 'choices.0.message.content');
        $toolCalls = data_get($payload, 'choices.0.message.tool_calls', []);

        if (is_callable($onTextDelta) && is_string($content) && $content !== '' && $toolCalls === []) {
            $onTextDelta($content);
        }

        return $payload;
    }

    protected function parseAssistantEventStream(StreamInterface $body, ?callable $onTextDelta = null): array
    {
        $content = '';
        $toolCalls = [];
        $finishReason = null;

        while (! $body->eof()) {
            $line = trim(Utils::readLine($body));

            if ($line === '' || str_starts_with($line, ':') || ! str_starts_with($line, 'data:')) {
                continue;
            }

            $data = trim(substr($line, 5));

            if ($data === '[DONE]') {
                break;
            }

            $event = json_decode($data, true);

            if (! is_array($event)) {
                continue;
            }

            $providerError = data_get($event, 'error.message');

            if (is_string($providerError) && $providerError !== '') {
                throw new \RuntimeException('AI-API Streamingfehler: '.$providerError);
            }

            $choice = data_get($event, 'choices.0', []);

            if (! is_array($choice)) {
                continue;
            }

            $delta = $choice['delta'] ?? $choice['message'] ?? [];
            $textDelta = is_array($delta) ? ($delta['content'] ?? '') : '';

            if (is_string($textDelta) && $textDelta !== '') {
                $content .= $textDelta;

                if (is_callable($onTextDelta)) {
                    $onTextDelta($textDelta);
                }
            }

            foreach (is_array($delta) ? ($delta['tool_calls'] ?? []) : [] as $toolCallDelta) {
                if (! is_array($toolCallDelta)) {
                    continue;
                }

                $index = max(0, (int) ($toolCallDelta['index'] ?? 0));
                $toolCalls[$index] ??= [
                    'id' => '',
                    'type' => 'function',
                    'function' => [
                        'name' => '',
                        'arguments' => '',
                    ],
                ];

                $idDelta = (string) ($toolCallDelta['id'] ?? '');
                $typeDelta = (string) ($toolCallDelta['type'] ?? '');
                $nameDelta = (string) data_get($toolCallDelta, 'function.name', '');
                $argumentsDelta = (string) data_get($toolCallDelta, 'function.arguments', '');

                if ($idDelta !== '') {
                    $toolCalls[$index]['id'] = $idDelta;
                }

                if ($typeDelta !== '') {
                    $toolCalls[$index]['type'] = $typeDelta;
                }

                if ($nameDelta !== '') {
                    $currentName = (string) $toolCalls[$index]['function']['name'];

                    if ($currentName === '' || str_starts_with($nameDelta, $currentName)) {
                        $toolCalls[$index]['function']['name'] = $nameDelta;
                    } elseif (! str_ends_with($currentName, $nameDelta)) {
                        $toolCalls[$index]['function']['name'] .= $nameDelta;
                    }
                }

                if ($argumentsDelta !== '') {
                    $toolCalls[$index]['function']['arguments'] .= $argumentsDelta;
                }
            }

            if (is_string($choice['finish_reason'] ?? null)) {
                $finishReason = $choice['finish_reason'];
            }
        }

        $message = [
            'role' => 'assistant',
            'content' => $content,
        ];

        if ($toolCalls !== []) {
            ksort($toolCalls);
            $message['tool_calls'] = array_values($toolCalls);
        }

        return [
            'choices' => [[
                'message' => $message,
                'finish_reason' => $finishReason,
            ]],
        ];
    }

    private function normalizeToolCalls(mixed $toolCalls): array
    {
        if (! is_array($toolCalls)) {
            return [];
        }

        return collect($toolCalls)
            ->filter(fn ($toolCall): bool => is_array($toolCall) && data_get($toolCall, 'function.name') !== null)
            ->map(function (array $toolCall, int $index): array {
                if (! is_string($toolCall['id'] ?? null) || trim((string) $toolCall['id']) === '') {
                    $toolCall['id'] = 'tool-call-'.$index.'-'.substr(sha1(json_encode($toolCall)), 0, 10);
                }

                return $toolCall;
            })
            ->values()
            ->all();
    }

    private function decodeToolArguments(string $arguments): array
    {
        try {
            $decoded = json_decode($arguments !== '' ? $arguments : '{}', true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function appendDisplayMessage(
        string $role,
        string $content,
        string $level = 'neutral',
        ?array $chatOptions = null,
    ): void {
        $content = $this->sanitizeAssistantText($content);
        $profileReferences = $role === 'assistant'
            ? app(InvestigationAssistantToolService::class)->profileReferences(Auth::user(), $content)
            : [];
        $chatOptions = $role === 'assistant' ? $this->normalizeChatOptions($chatOptions) : null;

        $this->chatHistory[] = [
            'role' => $role,
            'content' => $content,
            'level' => $level,
            'time' => now()->format('H:i'),
            'profiles' => $profileReferences,
            'options_prompt' => $chatOptions['prompt'] ?? null,
            'options' => $chatOptions['options'] ?? [],
            'selected_option_index' => null,
        ];

        $this->chatHistory = array_slice($this->chatHistory, -30);
        Session::put(self::DISPLAY_HISTORY_KEY, $this->chatHistory);
    }

    private function appendToolEvent(string $toolName, array $arguments, array $result): void
    {
        if (! ($result['silent'] ?? false)) {
            $this->appendTransientToolAlert(
                $toolName,
                (string) ($result['message'] ?? data_get($result, 'error.message', 'Tool ausgefuehrt.')),
                (bool) ($result['ok'] ?? false),
                $arguments,
            );
        }

        $tracking = $result['scan_tracking'] ?? null;

        if (is_array($tracking) && filled($tracking['token'] ?? null)) {
            $token = (string) $tracking['token'];
            $this->scanActivities = collect($this->scanActivities)
                ->reject(fn (array $activity): bool => ($activity['token'] ?? null) === $token)
                ->push([
                    ...$tracking,
                    'reacted' => false,
                ])
                ->take(-4)
                ->values()
                ->all();
            $this->persistScanActivities();
        }
    }

    private function appendTransientToolAlert(
        string $toolName,
        string $message,
        bool $successful,
        array $arguments = [],
    ): void {
        $this->toolEvents[] = [
            'id' => (string) Str::uuid(),
            'tool' => $toolName,
            'arguments' => $arguments,
            'ok' => $successful,
            'message' => $message,
            'time' => now()->format('H:i:s'),
        ];

        $this->toolEvents = array_slice($this->toolEvents, -4);
    }

    private function reactToCompletedScan(array $scan): void
    {
        $this->isLoading = true;

        try {
            $assistantResponse = $this->runAssistantConversation(trim(implode("\n", [
                'Ein von dir gestarteter Hintergrundscan wurde beendet.',
                'Lies jetzt mit den verfuegbaren Analyse-Tools die neuesten gespeicherten Ergebnisse dieses Profils.',
                'Starte dabei keinen weiteren Scan.',
                'Erklaere dem Nutzer kurz die wichtigsten neuen Erkenntnisse, Veraenderungen und den sinnvollsten naechsten Schritt.',
                'Scanstatus:',
                json_encode($scan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ])));

            $assistantMessage = $assistantResponse['message'] ?? '';
            $this->appendDisplayMessage(
                'assistant',
                $assistantMessage ?: 'Der Scan ist abgeschlossen. Die neuen Ergebnisse konnten jedoch nicht automatisch zusammengefasst werden.',
                'neutral',
                $assistantResponse['chat_options'] ?? null,
            );

            if (is_array($assistantResponse['ui_action'] ?? null)) {
                $this->dispatch('assistant-ui-action', action: $assistantResponse['ui_action']);
            }
        } catch (\Throwable $exception) {
            Log::warning('Automatische AI-Auswertung nach Scanabschluss fehlgeschlagen.', [
                'scan_token' => $scan['token'] ?? null,
                'error' => $exception->getMessage(),
            ]);

            $this->appendDisplayMessage(
                'assistant',
                'Der Scan wurde beendet, aber die automatische Auswertung ist fehlgeschlagen: '.$exception->getMessage(),
                'error',
            );
        } finally {
            $this->isLoading = false;
        }
    }

    private function persistScanActivities(): void
    {
        Session::put(self::SCAN_ACTIVITIES_KEY, array_slice($this->scanActivities, -4));
    }

    private function assistantScanLooksInterrupted(array $status, int $staleAfterSeconds = 30): bool
    {
        $trackedPersonId = (int) ($status['tracked_person_id'] ?? 0);
        $updatedAt = $status['updated_at'] ?? null;

        if ($trackedPersonId <= 0 || ! is_string($updatedAt)) {
            return false;
        }

        try {
            if (Carbon::parse($updatedAt)->diffInSeconds(now()) < $staleAfterSeconds) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    private function dismissPersistedResumeState(int $trackedPersonId, string $scanType, int $userId): void
    {
        $scan = match ($scanType) {
            'followers', 'following' => InstagramProfileListScan::query()
                ->where('tracked_person_id', $trackedPersonId)
                ->where('user_id', $userId)
                ->where('list_type', $scanType)
                ->latest('scanned_at')
                ->first(),
            'suggestions', 'suggestion_deepsearch' => TrackedPersonInstagramSuggestionScan::query()
                ->where('tracked_person_id', $trackedPersonId)
                ->where('user_id', $userId)
                ->latest('analyzed_at')
                ->limit(10)
                ->get()
                ->first(function (TrackedPersonInstagramSuggestionScan $scan) use ($scanType): bool {
                    $isDeepSearch = data_get($scan->raw_payload, 'operationMode') === 'suggestion-connections';

                    return $scanType === 'suggestion_deepsearch' ? $isDeepSearch : ! $isDeepSearch;
                }),
            'public_connections' => TrackedPersonInstagramPublicProfileScan::query()
                ->where('tracked_person_id', $trackedPersonId)
                ->where('user_id', $userId)
                ->latest('analyzed_at')
                ->first(),
            default => null,
        };

        if (! $scan) {
            return;
        }

        $payload = is_array($scan->raw_payload) ? $scan->raw_payload : [];
        $scan->forceFill([
            'raw_payload' => [
                ...$payload,
                'isResumable' => false,
                'resumeDismissedAt' => now('UTC')->toIso8601String(),
            ],
        ])->save();
    }

    private function trimTranscript(array $transcript): array
    {
        if (count($transcript) <= 22) {
            return $transcript;
        }

        $system = array_values(array_filter($transcript, fn ($message): bool => ($message['role'] ?? null) === 'system'));
        $tail = array_slice(array_values(array_filter($transcript, fn ($message): bool => ($message['role'] ?? null) !== 'system')), -21);

        return array_merge(array_slice($system, 0, 1), $tail);
    }

    private function sanitizeAssistantText(string $text): string
    {
        $text = $this->sanitizeAssistantChunk($text);

        return trim($text);
    }

    private function sanitizeAssistantChunk(string $text): string
    {
        return preg_replace('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}]/u', '', $text) ?? $text;
    }

    private function normalizeChatOptions(mixed $chatOptions): ?array
    {
        if (! is_array($chatOptions)) {
            return null;
        }

        $prompt = mb_substr(trim((string) ($chatOptions['prompt'] ?? '')), 0, 220);
        $options = collect($chatOptions['options'] ?? [])
            ->filter(fn ($option): bool => is_array($option))
            ->map(function (array $option): ?array {
                $label = mb_substr(trim((string) ($option['label'] ?? '')), 0, 80);
                $selectionPrompt = mb_substr(trim((string) ($option['prompt'] ?? '')), 0, 500);

                if ($label === '' || $selectionPrompt === '') {
                    return null;
                }

                return [
                    'label' => $label,
                    'description' => mb_substr(trim((string) ($option['description'] ?? '')), 0, 160),
                    'prompt' => $selectionPrompt,
                ];
            })
            ->filter()
            ->take(6)
            ->values()
            ->all();

        if (count($options) < 2) {
            return null;
        }

        return [
            'prompt' => $prompt,
            'options' => $options,
        ];
    }

    private function displayMessageForUserPrompt(string $prompt): string
    {
        if (str_starts_with($prompt, '[SCAN_TYPE_CONFIRMED]')) {
            preg_match('/f[uü]r @([a-zA-Z0-9._]{1,30})/u', $prompt, $usernameMatch);
            preg_match('/Ausgew[aä]hlte Aktion:\s*([^\r\n.]+)/u', $prompt, $actionMatch);

            $username = $usernameMatch[1] ?? null;
            $action = trim((string) ($actionMatch[1] ?? 'Scan'));

            return $username
                ? $action.' für @'.$username.' starten.'
                : $action.' starten.';
        }

        if (! str_starts_with($prompt, '[SCAN_TARGET_SELECTED]')) {
            return $prompt;
        }

        if (preg_match('/Profilvorschlag\s+@([a-zA-Z0-9._]{1,30})/u', $prompt, $matches)) {
            return 'Scan für @'.$matches[1].' auswählen.';
        }

        return 'Profilvorschlag als Scan-Ziel auswählen.';
    }

    private function initialPageContext(): array
    {
        $route = request()->route();
        $parameters = is_object($route) && method_exists($route, 'parameters')
            ? $route->parameters()
            : [];

        return $this->normalizePageContext([
            'route_name' => is_object($route) && method_exists($route, 'getName') ? $route->getName() : null,
            'path' => request()->path(),
            'tracked_person_id' => $parameters['trackedPersonId'] ?? null,
            'instagram_profile_id' => $parameters['instagramProfileId'] ?? null,
            'network_map_open' => request()->routeIs('network'),
            'network_map_fullscreen' => false,
        ]);
    }

    private function normalizePageContext(array $context): array
    {
        $stringValue = static function (mixed $value, int $limit = 255): ?string {
            if (! is_scalar($value)) {
                return null;
            }

            $value = trim((string) $value);

            return $value !== '' ? mb_substr($value, 0, $limit) : null;
        };
        $positiveInteger = static function (mixed $value): ?int {
            $value = filter_var($value, FILTER_VALIDATE_INT);

            return is_int($value) && $value > 0 ? $value : null;
        };

        $normalized = [
            'route_name' => $stringValue($context['route_name'] ?? null, 120),
            'path' => $stringValue($context['path'] ?? null, 500),
            'page_title' => $stringValue($context['page_title'] ?? null, 200),
            'tracked_person_id' => $positiveInteger($context['tracked_person_id'] ?? null),
            'instagram_profile_id' => $positiveInteger($context['instagram_profile_id'] ?? null),
            'network_map_open' => (bool) ($context['network_map_open'] ?? false),
            'network_map_fullscreen' => (bool) ($context['network_map_fullscreen'] ?? false),
            'network_map_id' => $stringValue($context['network_map_id'] ?? null, 120),
            'network_focus_tracked_person_id' => $positiveInteger($context['network_focus_tracked_person_id'] ?? null),
            'selected_node_id' => $stringValue($context['selected_node_id'] ?? null, 255),
            'selected_node_type' => $stringValue($context['selected_node_type'] ?? null, 80),
            'selected_profile_username' => $stringValue($context['selected_profile_username'] ?? null, 255),
            'selected_profile_name' => $stringValue($context['selected_profile_name'] ?? null, 255),
            'selected_profile_open' => (bool) ($context['selected_profile_open'] ?? false),
        ];

        if ($normalized['tracked_person_id'] === null && preg_match('/^person-(\d+)$/', (string) $normalized['selected_node_id'], $matches)) {
            $normalized['tracked_person_id'] = (int) $matches[1];
        }

        return array_filter($normalized, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function assistantIsConfigured(string $apiKey): bool
    {
        return (bool) $this->status
            && is_string($this->apiUrl)
            && trim($this->apiUrl) !== ''
            && $apiKey !== ''
            && is_string($this->aiModel)
            && trim($this->aiModel) !== '';
    }
}
