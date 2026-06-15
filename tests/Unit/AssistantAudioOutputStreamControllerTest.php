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
}
