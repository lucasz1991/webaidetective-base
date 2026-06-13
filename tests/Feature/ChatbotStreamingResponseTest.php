<?php

namespace Tests\Feature;

use App\Livewire\Tools\Chatbot;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use Tests\TestCase;

class ChatbotStreamingResponseTest extends TestCase
{
    public function test_streamed_text_deltas_are_forwarded_and_combined(): void
    {
        $component = $this->makeChatbotParser();
        $deltas = [];
        $stream = Utils::streamFor(implode("\n", [
            'data: {"choices":[{"delta":{"content":"Hallo "}}]}',
            '',
            'data: {"choices":[{"delta":{"content":"Welt"},"finish_reason":"stop"}]}',
            '',
            'data: [DONE]',
            '',
        ]));

        $result = $component->parseForTest(
            $stream,
            function (string $delta) use (&$deltas): void {
                $deltas[] = $delta;
            },
        );

        $this->assertSame(['Hallo ', 'Welt'], $deltas);
        $this->assertSame('Hallo Welt', data_get($result, 'choices.0.message.content'));
        $this->assertSame('stop', data_get($result, 'choices.0.finish_reason'));
    }

    public function test_streamed_tool_call_arguments_are_reassembled(): void
    {
        $component = $this->makeChatbotParser();
        $stream = Utils::streamFor(implode("\n", [
            'data: {"choices":[{"delta":{"tool_calls":[{"index":0,"id":"call_1","type":"function","function":{"name":"present_chat_options","arguments":"{\\"prompt\\":\\"Waehle\\","}}]}}]}',
            '',
            'data: {"choices":[{"delta":{"tool_calls":[{"index":0,"function":{"arguments":"\\"options\\":[]}"}}]},"finish_reason":"tool_calls"}]}',
            '',
            'data: [DONE]',
            '',
        ]));

        $result = $component->parseForTest($stream);

        $this->assertSame(
            'present_chat_options',
            data_get($result, 'choices.0.message.tool_calls.0.function.name'),
        );
        $this->assertSame(
            '{"prompt":"Waehle","options":[]}',
            data_get($result, 'choices.0.message.tool_calls.0.function.arguments'),
        );
        $this->assertSame('tool_calls', data_get($result, 'choices.0.finish_reason'));
    }

    private function makeChatbotParser(): Chatbot
    {
        return new class extends Chatbot
        {
            public function parseForTest(StreamInterface $body, ?callable $onTextDelta = null): array
            {
                return $this->parseAssistantEventStream($body, $onTextDelta);
            }
        };
    }
}
