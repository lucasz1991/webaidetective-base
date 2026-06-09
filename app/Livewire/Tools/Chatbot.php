<?php

namespace App\Livewire\Tools;

use App\Models\Setting;
use App\Services\Ai\InvestigationAssistantToolService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Livewire\Component;

class Chatbot extends Component
{
    private const DISPLAY_HISTORY_KEY = 'investigation_assistant_display_history';
    private const TRANSCRIPT_KEY = 'investigation_assistant_transcript';
    private const TOOL_EVENTS_KEY = 'investigation_assistant_tool_events';

    public string $message = '';
    public array $chatHistory = [];
    public array $toolEvents = [];
    public bool $isLoading = false;

    public $status;
    public $assistantName;
    public $apiUrl;
    public $apiKey;
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
        $this->apiKey = Setting::getValue('ai_assistant', 'api_key');
        $this->aiModel = Setting::getValue('ai_assistant', 'ai_model');
        $this->modelTitle = Setting::getValue('ai_assistant', 'model_title') ?: config('app.name');
        $this->refererUrl = Setting::getValue('ai_assistant', 'referer_url') ?: config('app.url');
        $this->trainContent = Setting::getValue('ai_assistant', 'train_content');
    }

    public function quickAction(string $prompt): void
    {
        $this->message = $prompt;
        $this->sendMessage();
    }

    public function sendMessage(): void
    {
        $userMessage = trim($this->message);

        if ($userMessage === '') {
            return;
        }

        $this->message = '';
        $this->isLoading = true;
        $this->appendDisplayMessage('user', $userMessage);

        try {
            $assistantMessage = $this->runAssistantConversation($userMessage);
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
        $this->message = '';
    }

    public function render()
    {
        return view('livewire.tools.chatbot', [
            'trackedPeople' => Auth::check()
                ? Auth::user()
                    ->trackedPeople()
                    ->orderByRaw('instagram_username IS NULL')
                    ->orderByDesc('last_instagram_analyzed_at')
                    ->limit(8)
                    ->get()
                : collect(),
        ]);
    }

    private function runAssistantConversation(string $userMessage): string
    {
        if (! $this->assistantIsConfigured()) {
            return 'Der AI-Assistent ist noch nicht vollstaendig konfiguriert. Bitte API-URL, API-Key und Modell im Adminbereich hinterlegen.';
        }

        /** @var InvestigationAssistantToolService $toolService */
        $toolService = app(InvestigationAssistantToolService::class);
        $transcript = $this->baseTranscript($toolService);
        $transcript[] = [
            'role' => 'user',
            'content' => $userMessage,
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
                        'Aktueller App-Kontext: '.json_encode(
                            $toolService->conversationContext(Auth::user()),
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                        ),
                    ]))),
                ],
            ];
        }

        return $this->trimTranscript($transcript);
    }

    private function requestAssistant(array $messages, array $tools): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->apiKey,
            'HTTP-Referer' => $this->refererUrl,
            'X-Title' => $this->modelTitle,
            'Content-Type' => 'application/json',
        ])
            ->timeout(90)
            ->retry(2, 750)
            ->post($this->apiUrl, [
                'model' => $this->aiModel,
                'messages' => $messages,
                'tools' => $tools,
                'tool_choice' => 'auto',
                'temperature' => 0.2,
            ]);

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

    private function assistantIsConfigured(): bool
    {
        return (bool) $this->status
            && is_string($this->apiUrl)
            && trim($this->apiUrl) !== ''
            && is_string($this->apiKey)
            && trim($this->apiKey) !== ''
            && is_string($this->aiModel)
            && trim($this->aiModel) !== '';
    }
}
