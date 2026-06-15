<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AssistantAudioOutputStreamController extends Controller
{
    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
            'voice' => ['nullable', 'string', 'max:80'],
            'format' => ['nullable', 'in:mp3,opus,wav,pcm'],
            'speed' => ['nullable', 'numeric', 'min:0.25', 'max:4'],
        ]);

        $apiKey = $this->setting('api_key');
        $model = $this->setting('audio_output_model', 'tts-1');
        $apiUrl = $this->audioOutputApiUrl();
        $voice = trim((string) ($validated['voice'] ?? $this->setting('audio_output_voice', 'alloy')));
        $format = (string) ($validated['format'] ?? $this->setting('audio_output_format', 'mp3'));
        $speed = (float) ($validated['speed'] ?? 1);

        if ($apiKey === '' || $model === '' || $apiUrl === '') {
            return response()->json([
                'message' => 'Audio-Ausgabe ist nicht konfiguriert. Bitte API-Key, TTS-Modell und Audio-Endpoint prüfen.',
            ], 422);
        }

        $contentType = match ($format) {
            'opus' => 'audio/opus',
            'wav' => 'audio/wav',
            'pcm' => 'audio/pcm',
            default => 'audio/mpeg',
        };

        try {
            $providerResponse = Http::withToken($apiKey)
                ->accept($contentType)
                ->asJson()
                ->withHeaders(array_filter([
                    'HTTP-Referer' => $this->setting('referer_url', config('app.url')),
                    'X-Title' => $this->setting('model_title', config('app.name')),
                ]))
                ->withoutRedirecting()
                ->connectTimeout(15)
                ->timeout(120)
                ->withOptions([
                    'stream' => true,
                    'http_errors' => false,
                ])
                ->post($apiUrl, array_filter([
                    'model' => $model,
                    'input' => trim((string) $validated['text']),
                    'voice' => $voice !== '' ? $voice : 'alloy',
                    'response_format' => $format,
                    'speed' => $speed,
                ], static fn ($value): bool => $value !== null && $value !== ''));
        } catch (\Throwable $exception) {
            Log::warning('Assistant TTS request failed.', [
                'error' => $exception->getMessage(),
                'api_url' => $apiUrl,
                'model' => $model,
            ]);

            return response()->json([
                'message' => 'Die Audio-Ausgabe konnte nicht gestartet werden: '.$exception->getMessage(),
            ], 502);
        }

        if ($providerResponse->redirect()) {
            return response()->json([
                'message' => 'Der Audio-Endpoint leitet weiter. Bitte die finale TTS-URL direkt konfigurieren.',
                'location' => (string) $providerResponse->header('Location'),
            ], 422);
        }

        if (! $providerResponse->successful()) {
            $body = (string) $providerResponse->toPsrResponse()->getBody();

            return response()->json([
                'message' => 'Die TTS-API antwortet mit HTTP '.$providerResponse->status().'.',
                'detail' => mb_substr($body, 0, 1000),
            ], 502);
        }

        $body = $providerResponse->toPsrResponse()->getBody();

        return response()->stream(function () use ($body): void {
            while (! $body->eof()) {
                echo $body->read(8192);

                if (function_exists('ob_flush')) {
                    @ob_flush();
                }

                flush();
            }
        }, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function audioOutputApiUrl(): string
    {
        $explicit = $this->setting('audio_output_api_url');

        if ($explicit !== '') {
            return $explicit;
        }

        $chatApiUrl = $this->setting('api_url');

        if ($chatApiUrl !== '' && Str::contains($chatApiUrl, '/chat/completions')) {
            return Str::replace('/chat/completions', '/audio/speech', $chatApiUrl);
        }

        return 'https://api.openai.com/v1/audio/speech';
    }

    private function setting(string $key, ?string $default = ''): string
    {
        $value = Setting::getValue('ai_assistant', $key);

        if (is_array($value)) {
            $value = collect($value)->flatten()->first();
        }

        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : (string) $default;
    }
}
