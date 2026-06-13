<?php

namespace App\Livewire\Tools;

use App\Models\Setting;
use App\Services\Ai\InvestigationAssistantToolService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class Chatbot extends Component
{
    use WithFileUploads;

    private const DISPLAY_HISTORY_KEY = 'investigation_assistant_display_history';

    private const TRANSCRIPT_KEY = 'investigation_assistant_transcript';

    private const TOOL_EVENTS_KEY = 'investigation_assistant_tool_events';

    public string $message = '';

    public array $chatHistory = [];

    public array $toolEvents = [];

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
        $this->toolEvents = Session::get(self::TOOL_EVENTS_KEY, []);
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

    public function sendMessage(): void
    {
        $userMessage = trim($this->message);
        $attachmentContext = $this->buildAttachmentContext();

        if ($userMessage === '' && $attachmentContext === '') {
            return;
        }

        $this->message = '';
        $this->isLoading = true;
        $displayMessage = $userMessage !== '' ? $userMessage : 'Dateien zur Analyse hinzugefuegt.';

        if ($attachmentContext !== '') {
            $displayMessage .= "\n\n".'Anhang-Kontext wurde der AI mitgegeben.';
        }

        $this->appendDisplayMessage('user', $displayMessage);

        try {
            $assistantMessage = $this->runAssistantConversation(trim($userMessage."\n\n".$attachmentContext));
            $this->appendDisplayMessage('assistant', $assistantMessage ?: 'Ich habe dazu gerade keine belastbare Antwort erhalten.');
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

    public function render()
    {
        return view('livewire.tools.chatbot');
    }

    private function runAssistantConversation(string $userMessage): string
    {
        if (! $this->assistantIsConfigured()) {
            return 'Der AI-Assistent ist noch nicht vollstaendig konfiguriert. Bitte API-URL, API-Key und Modell im Adminbereich hinterlegen.';
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

        for ($step = 0; $step < 5; $step++) {
            $assistantResponse = $this->requestAssistant($transcript, $toolService->tools());
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

                $transcript[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'] ?? ('tool-'.$step),
                    'name' => $toolName,
                    'content' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }
        }

        Session::put(self::TRANSCRIPT_KEY, $this->trimTranscript($transcript));

        return $this->sanitizeAssistantText($finalMessage);
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

    private function requestAssistant(array $messages, array $tools): array
    {
        $apiKey = $this->assistantApiKey();
        $apiUrl = trim((string) $this->apiUrl);

        $response = Http::acceptJson()
            ->asJson()
            ->withToken($apiKey)
            ->withHeaders(array_filter([
                'HTTP-Referer' => trim((string) $this->refererUrl),
                'X-Title' => trim((string) $this->modelTitle),
            ]))
            ->withoutRedirecting()
            ->timeout(90)
            ->retry(2, 750, throw: false)
            ->post($apiUrl, [
                'model' => $this->aiModel,
                'messages' => $messages,
                'tools' => $tools,
                'tool_choice' => 'auto',
                'temperature' => 0.2,
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
            throw new \RuntimeException('AI-API antwortet mit HTTP '.$response->status().': '.mb_substr($response->body(), 0, 500));
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new \RuntimeException('AI-API lieferte kein gueltiges JSON.');
        }

        return $payload;
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

    private function appendDisplayMessage(string $role, string $content, string $level = 'neutral'): void
    {
        $this->chatHistory[] = [
            'role' => $role,
            'content' => $this->sanitizeAssistantText($content),
            'level' => $level,
            'time' => now()->format('H:i'),
        ];

        $this->chatHistory = array_slice($this->chatHistory, -30);
        Session::put(self::DISPLAY_HISTORY_KEY, $this->chatHistory);
    }

    private function appendToolEvent(string $toolName, array $arguments, array $result): void
    {
        $this->toolEvents[] = [
            'tool' => $toolName,
            'arguments' => $arguments,
            'ok' => (bool) ($result['ok'] ?? false),
            'message' => (string) ($result['message'] ?? data_get($result, 'error.message', 'Tool ausgefuehrt.')),
            'time' => now()->format('H:i:s'),
        ];

        $this->toolEvents = array_slice($this->toolEvents, -12);
        Session::put(self::TOOL_EVENTS_KEY, $this->toolEvents);
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
        $text = preg_replace('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}]/u', '', $text) ?? $text;

        return trim($text);
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

    private function assistantIsConfigured(): bool
    {
        return (bool) $this->status
            && is_string($this->apiUrl)
            && trim($this->apiUrl) !== ''
            && $this->assistantApiKey() !== ''
            && is_string($this->aiModel)
            && trim($this->aiModel) !== '';
    }

    private function assistantApiKey(): string
    {
        $value = Setting::getValue('ai_assistant', 'api_key');

        if (! is_string($value) || trim($value) === '') {
            return '';
        }

        try {
            return trim(Crypt::decryptString($value));
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                'Der gespeicherte AI API-Key kann nicht mit dem APP_KEY der Base-Installation entschluesselt werden. Bitte den Key im Adminbereich erneut speichern.',
                previous: $exception,
            );
        }
    }
}
