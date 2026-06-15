<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\AiConnectionLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AssistantAudioOutputStreamController extends Controller
{
    private const OPENROUTER_AUDIO_SPEECH_URL = 'https://openrouter.ai/api/v1/audio/speech';

    public function __invoke(Request $request)
    {
        $connectionLogger = app(AiConnectionLogger::class);
        $connectionId = $connectionLogger->connectionId();
        $startedAt = microtime(true);

        $connectionLogger->write('info', 'OpenRouter TTS endpoint called.', [
            'connection_id' => $connectionId,
            'user_id' => $request->user()?->id,
            'request_path' => $request->path(),
            'request_host' => $request->getHost(),
            'origin' => (string) $request->header('Origin'),
        ]);

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
            'voice' => ['nullable', 'string', 'max:80'],
            'format' => ['nullable', 'in:mp3,opus,wav,pcm'],
            'speed' => ['nullable', 'numeric', 'min:0.25', 'max:4'],
        ]);

        try {
            $apiKey = $this->setting('api_key');
            $model = $this->setting('audio_output_model');
            $apiUrl = $this->openRouterAudioOutputApiUrl();
            $voice = trim((string) ($validated['voice'] ?? $this->setting('audio_output_voice')));
            $format = (string) ($validated['format'] ?? $this->setting('audio_output_format', 'mp3'));
        } catch (\Throwable $exception) {
            $connectionLogger->write('error', 'OpenRouter TTS configuration could not be loaded.', [
                'connection_id' => $connectionId,
                'duration_ms' => $connectionLogger->durationMs($startedAt),
                'exception' => $exception::class,
                'error' => $connectionLogger->excerpt($exception->getMessage()),
            ]);

            return response()->json([
                'message' => 'Die Audio-Konfiguration konnte nicht geladen werden.',
                'detail' => $exception->getMessage(),
            ], 503);
        }

        $speed = (float) ($validated['speed'] ?? 1);
        $voice = $voice !== '' ? $voice : $this->defaultVoiceForModel($model);
        $format = $this->providerAudioFormat($model, $format);

        if ($apiKey === '' || $model === '' || $apiUrl === '') {
            $connectionLogger->write('warning', 'OpenRouter TTS configuration is incomplete.', [
                'connection_id' => $connectionId,
                'api_key_present' => $apiKey !== '',
                'model_present' => $model !== '',
                'api_url_present' => $apiUrl !== '',
            ]);

            return response()->json([
                'message' => 'OpenRouter-Audioausgabe ist nicht konfiguriert. Bitte API-Key, OpenRouter-TTS-Modell und OpenRouter-Audio-Endpoint prüfen.',
            ], 422);
        }

        if (! $this->isOpenRouterSpeechUrl($apiUrl)) {
            $connectionLogger->write('warning', 'OpenRouter TTS URL is invalid.', [
                'connection_id' => $connectionId,
                'api_url' => $apiUrl,
            ]);

            return response()->json([
                'message' => 'Der konfigurierte Audio-Endpoint ist kein OpenRouter Speech-Endpoint.',
                'configured_url' => $apiUrl,
                'expected_default' => self::OPENROUTER_AUDIO_SPEECH_URL,
            ], 422);
        }

        $providerContentType = match ($format) {
            'opus' => 'audio/opus',
            'wav' => 'audio/wav',
            'pcm' => 'audio/pcm',
            default => 'audio/mpeg',
        };
        $responseContentType = $format === 'pcm' ? 'audio/wav' : $providerContentType;
        $connectionLogger->write('info', 'OpenRouter TTS request started.', [
            'connection_id' => $connectionId,
            'user_id' => $request->user()?->id,
            'api_url' => $apiUrl,
            'model' => $model,
            'voice' => $voice !== '' ? $voice : 'alloy',
            'format' => $format,
            'speed' => $speed,
            'input_characters' => mb_strlen(trim((string) $validated['text'])),
        ]);

        try {
            $providerResponse = Http::withToken($apiKey)
                ->accept($providerContentType)
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
            $connectionLogger->write('error', 'OpenRouter TTS transport failed.', [
                'connection_id' => $connectionId,
                'duration_ms' => $connectionLogger->durationMs($startedAt),
                'exception' => $exception::class,
                'error' => $connectionLogger->excerpt($exception->getMessage()),
            ]);

            Log::warning('Assistant OpenRouter TTS request failed.', [
                'error' => $exception->getMessage(),
                'api_url' => $apiUrl,
                'model' => $model,
            ]);

            return response()->json([
                'message' => 'Die OpenRouter-Audioausgabe konnte nicht gestartet werden: '.$exception->getMessage(),
            ], 502);
        }

        $connectionLogger->write(
            $providerResponse->successful() ? 'info' : 'warning',
            'OpenRouter TTS response received.',
            [
                'connection_id' => $connectionId,
                'duration_ms' => $connectionLogger->durationMs($startedAt),
                'status' => $providerResponse->status(),
                'content_type' => (string) $providerResponse->header('Content-Type'),
                'content_length' => (string) $providerResponse->header('Content-Length'),
                'location' => (string) $providerResponse->header('Location'),
                'request_id' => (string) ($providerResponse->header('X-Request-ID')
                    ?: $providerResponse->header('X-OpenRouter-Request-ID')),
                'cf_ray' => (string) $providerResponse->header('CF-Ray'),
            ],
        );

        $errorBody = null;

        if (! $providerResponse->successful()) {
            $errorBody = (string) $providerResponse->toPsrResponse()->getBody();
            $connectionLogger->write('error', 'OpenRouter TTS request rejected.', [
                'connection_id' => $connectionId,
                'duration_ms' => $connectionLogger->durationMs($startedAt),
                'status' => $providerResponse->status(),
                'response_excerpt' => $connectionLogger->excerpt($errorBody),
            ]);
        }

        if ($providerResponse->redirect()) {
            return response()->json([
                'message' => 'Der OpenRouter-Audio-Endpoint leitet weiter. Bitte die finale OpenRouter-TTS-URL direkt konfigurieren.',
                'location' => (string) $providerResponse->header('Location'),
            ], 422);
        }

        if (! $providerResponse->successful()) {
            return response()->json([
                'message' => 'OpenRouter Audio/TTS antwortet mit HTTP '.$providerResponse->status().'.',
                'detail' => $this->providerErrorMessage(
                    (string) $errorBody,
                    $providerResponse->status(),
                    $model,
                ),
            ], 502);
        }

        $body = $providerResponse->toPsrResponse()->getBody();

        if ($format === 'pcm') {
            $pcm = (string) $body;
            $audio = $this->pcmToWav($pcm);

            $connectionLogger->write('info', 'OpenRouter TTS PCM response converted to WAV.', [
                'connection_id' => $connectionId,
                'duration_ms' => $connectionLogger->durationMs($startedAt),
                'pcm_bytes' => strlen($pcm),
                'wav_bytes' => strlen($audio),
            ]);

            return response($audio, 200, [
                'Content-Type' => $responseContentType,
                'Content-Length' => (string) strlen($audio),
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
            ]);
        }

        return response()->stream(function () use ($body, $connectionId, $connectionLogger, $startedAt): void {
            $bytes = 0;

            try {
                while (! $body->eof()) {
                    $chunk = $body->read(8192);
                    $bytes += strlen($chunk);
                    echo $chunk;

                    if (function_exists('ob_flush')) {
                        @ob_flush();
                    }

                    flush();
                }
            } finally {
                $connectionLogger->write('info', 'OpenRouter TTS stream completed.', [
                    'connection_id' => $connectionId,
                    'duration_ms' => $connectionLogger->durationMs($startedAt),
                    'streamed_bytes' => $bytes,
                ]);
            }
        }, 200, [
            'Content-Type' => $responseContentType,
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function openRouterAudioOutputApiUrl(): string
    {
        $explicit = $this->setting('audio_output_api_url');

        if ($explicit !== '') {
            return $explicit;
        }

        $chatApiUrl = $this->setting('api_url');

        if (
            $chatApiUrl !== ''
            && $this->isOpenRouterUrl($chatApiUrl)
            && Str::contains($chatApiUrl, '/chat/completions')
        ) {
            return Str::replace('/chat/completions', '/audio/speech', $chatApiUrl);
        }

        return self::OPENROUTER_AUDIO_SPEECH_URL;
    }

    private function isOpenRouterUrl(string $url): bool
    {
        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));

        return $host === 'openrouter.ai' || Str::endsWith($host, '.openrouter.ai');
    }

    private function isOpenRouterSpeechUrl(string $url): bool
    {
        $path = '/'.trim((string) parse_url($url, PHP_URL_PATH), '/');

        return $this->isOpenRouterUrl($url) && $path === '/api/v1/audio/speech';
    }

    private function providerErrorMessage(string $body, int $status = 0, string $model = ''): string
    {
        $payload = json_decode($body, true);
        $message = is_array($payload) ? data_get($payload, 'error.message') : null;

        if ($status === 404 || (is_string($message) && str_contains(Str::lower($message), 'provider returned 404'))) {
            return 'Das konfigurierte TTS-Modell "'.$model.'" wurde am OpenRouter Speech-Endpoint nicht gefunden '
                .'oder unterstützt keine Speech-Ausgabe. Verwende ein aktuelles Speech-Modell, zum Beispiel '
                .'"x-ai/grok-voice-tts-1.0" mit einer Stimme wie "Eve".';
        }

        if (is_string($message) && str_contains($message, 'specify "prompt" or "messages"')) {
            return 'Der konfigurierte Audio-Endpoint ist kein TTS-Endpoint und erwartet Chat-Nachrichten. '
                .'Verwende https://openrouter.ai/api/v1/audio/speech und ein Speech/TTS-Modell.';
        }

        if (is_string($message) && $message !== '') {
            return mb_substr($message, 0, 1000);
        }

        return mb_substr($body, 0, 1000);
    }

    private function defaultVoiceForModel(string $model): string
    {
        return Str::startsWith(Str::lower($model), 'x-ai/') ? 'Eve' : 'alloy';
    }

    private function providerAudioFormat(string $model, string $format): string
    {
        $normalizedModel = Str::lower($model);

        if (Str::startsWith($normalizedModel, 'google/') && Str::contains($normalizedModel, 'tts')) {
            return 'pcm';
        }

        return $format;
    }

    private function pcmToWav(string $pcm, int $sampleRate = 24000, int $channels = 1, int $bitsPerSample = 16): string
    {
        $dataLength = strlen($pcm);
        $byteRate = $sampleRate * $channels * intdiv($bitsPerSample, 8);
        $blockAlign = $channels * intdiv($bitsPerSample, 8);

        return 'RIFF'
            .pack('V', 36 + $dataLength)
            .'WAVEfmt '
            .pack('VvvVVvv', 16, 1, $channels, $sampleRate, $byteRate, $blockAlign, $bitsPerSample)
            .'data'
            .pack('V', $dataLength)
            .$pcm;
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
