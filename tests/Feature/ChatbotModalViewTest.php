<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
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
        $this->assertStringContainsString('wire:stream="assistant-status-stream"', $view);
        $this->assertStringContainsString('Copilot denkt nach', $view);
        $this->assertStringContainsString('streamBufferedAssistantText', file_get_contents(app_path('Livewire/Tools/Chatbot.php')));
        $this->assertStringContainsString('Array.isArray(item.options)', $view);
        $this->assertStringContainsString('quick(option.prompt)', $view);
        $this->assertStringNotContainsString('Scan-Typ "${scanType}"', $view);
        $this->assertStringContainsString('from-sky-600 via-cyan-600 to-emerald-600', $view);
        $this->assertStringContainsString('Scans priorisieren', $view);
        $this->assertStringContainsString('Netzwerk-Strategie', $view);
        $this->assertStringContainsString('Monitoring bewerten', $view);
        $this->assertStringContainsString('Kontakte finden', $view);
        $this->assertStringNotContainsString('bg-slate-950 px-3 py-2 text-white', $view);
    }

    public function test_chatbot_alpine_data_attribute_is_not_truncated_by_html_quotes(): void
    {
        $view = file_get_contents(resource_path('views/livewire/tools/chatbot.blade.php'));
        $document = new DOMDocument;

        $previousState = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<!doctype html><html><body>'.$view.'</body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previousState);

        $root = (new DOMXPath($document))
            ->query('//div[contains(concat(" ", normalize-space(@class), " "), " investigation-copilot ")]')
            ?->item(0);

        $this->assertNotNull($root);
        $this->assertStringContainsString('requestProfileScanType(profile, scanType, scanLabel)', $root->getAttribute('x-data'));
        $this->assertStringContainsString('toggleVoice()', $root->getAttribute('x-data'));
    }
}
