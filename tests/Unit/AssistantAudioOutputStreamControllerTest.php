<?php

namespace Tests\Unit;

use App\Http\Controllers\Ai\AssistantAudioOutputStreamController;
use ReflectionMethod;
use Tests\TestCase;

class AssistantAudioOutputStreamControllerTest extends TestCase
{
    public function test_only_the_openrouter_speech_endpoint_is_accepted(): void
    {
        $controller = new AssistantAudioOutputStreamController;
        $method = new ReflectionMethod($controller, 'isOpenRouterSpeechUrl');

        $this->assertTrue($method->invoke($controller, 'https://openrouter.ai/api/v1/audio/speech'));
        $this->assertTrue($method->invoke($controller, 'https://openrouter.ai/api/v1/audio/speech/'));
        $this->assertFalse($method->invoke($controller, 'https://openrouter.ai/api/v1/chat/completions'));
        $this->assertFalse($method->invoke($controller, 'https://example.com/api/v1/audio/speech'));
    }

    public function test_chat_endpoint_errors_are_explained_as_tts_configuration_errors(): void
    {
        $controller = new AssistantAudioOutputStreamController;
        $method = new ReflectionMethod($controller, 'providerErrorMessage');
        $body = json_encode([
            'error' => [
                'message' => 'Input required: specify "prompt" or "messages"',
                'code' => 400,
            ],
        ]);

        $message = $method->invoke($controller, $body);

        $this->assertStringContainsString('kein TTS', $message);
        $this->assertStringContainsString('/api/v1/audio/speech', $message);
    }

    public function test_provider_404_is_explained_as_an_invalid_tts_model(): void
    {
        $controller = new AssistantAudioOutputStreamController;
        $method = new ReflectionMethod($controller, 'providerErrorMessage');

        $message = $method->invoke(
            $controller,
            '{"error":{"message":"Provider returned 404"}}',
            404,
            'openai/gpt-5.2-chat',
        );

        $this->assertStringContainsString('openai/gpt-5.2-chat', $message);
        $this->assertStringContainsString('x-ai/grok-voice-tts-1.0', $message);
    }

    public function test_xai_models_use_a_supported_default_voice(): void
    {
        $controller = new AssistantAudioOutputStreamController;
        $method = new ReflectionMethod($controller, 'defaultVoiceForModel');

        $this->assertSame('Eve', $method->invoke($controller, 'x-ai/grok-voice-tts-1.0'));
        $this->assertSame('Kore', $method->invoke($controller, 'google/gemini-3.1-flash-tts-preview'));
        $this->assertSame('alloy', $method->invoke($controller, 'openai/tts-1'));
    }

    public function test_openai_voice_is_replaced_for_gemini_tts(): void
    {
        $controller = new AssistantAudioOutputStreamController;
        $method = new ReflectionMethod($controller, 'providerVoice');

        $this->assertSame('Kore', $method->invoke($controller, 'google/gemini-3.1-flash-tts-preview', 'alloy'));
        $this->assertSame('Puck', $method->invoke($controller, 'google/gemini-3.1-flash-tts-preview', 'Puck'));
        $this->assertSame('Eve', $method->invoke($controller, 'x-ai/grok-voice-tts-1.0', 'Eve'));
    }

    public function test_gemini_tts_is_forced_to_pcm(): void
    {
        $controller = new AssistantAudioOutputStreamController;
        $method = new ReflectionMethod($controller, 'providerAudioFormat');

        $this->assertSame('pcm', $method->invoke($controller, 'google/gemini-3.1-flash-tts-preview', 'mp3'));
        $this->assertSame('mp3', $method->invoke($controller, 'x-ai/grok-voice-tts-1.0', 'mp3'));
    }

    public function test_pcm_is_wrapped_in_a_valid_wav_header(): void
    {
        $controller = new AssistantAudioOutputStreamController;
        $method = new ReflectionMethod($controller, 'pcmToWav');
        $wav = $method->invoke($controller, "\x00\x01\x02\x03");

        $this->assertSame('RIFF', substr($wav, 0, 4));
        $this->assertSame('WAVE', substr($wav, 8, 4));
        $this->assertSame('data', substr($wav, 36, 4));
        $this->assertSame(48, strlen($wav));
    }
}
