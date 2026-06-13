<?php

namespace Tests\Feature;

use Tests\TestCase;

class ChatbotModalViewTest extends TestCase
{
    public function test_chatbot_modal_uses_colored_toolbar_and_immediate_loading_state(): void
    {
        $view = file_get_contents(resource_path('views/livewire/tools/chatbot.blade.php'));

        $this->assertStringContainsString('submitting: false', $view);
        $this->assertStringContainsString('pendingLabel:', $view);
        $this->assertStringContainsString('$wire.sendMessage(outgoingMessage)', $view);
        $this->assertStringContainsString('x-show="busy()"', $view);
        $this->assertStringContainsString('wire:stream="assistant-response-stream"', $view);
        $this->assertStringContainsString('Array.isArray(item.options)', $view);
        $this->assertStringContainsString('quick(option.prompt)', $view);
        $this->assertStringContainsString('Copilot analysiert deine Anfrage', $view);
        $this->assertStringContainsString('from-sky-600 via-cyan-600 to-emerald-600', $view);
        $this->assertStringContainsString('Scans priorisieren', $view);
        $this->assertStringContainsString('Netzwerk-Strategie', $view);
        $this->assertStringContainsString('Monitoring bewerten', $view);
        $this->assertStringContainsString('Kontakte finden', $view);
        $this->assertStringNotContainsString('bg-slate-950 px-3 py-2 text-white', $view);
    }
}
