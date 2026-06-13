<?php

namespace Tests\Feature;

use App\Services\Ai\InvestigationAssistantToolService;
use Tests\TestCase;

class InvestigationAssistantProfileScanSelectionTest extends TestCase
{
    public function test_profile_badge_selection_requires_an_explicit_scan_type(): void
    {
        $service = app(InvestigationAssistantToolService::class);
        $scanTool = collect($service->tools())
            ->firstWhere('function.name', 'dispatch_instagram_scan');

        $this->assertNotNull($scanTool);
        $this->assertContains('scan_type', data_get($scanTool, 'function.parameters.required', []));
        $this->assertStringContainsString('[SCAN_TARGET_SELECTED]', $service->systemPrompt());
        $this->assertStringContainsString('Starte bis zur eindeutigen Auswahl kein Scan-Tool', $service->systemPrompt());
    }

    public function test_dispatch_does_not_fall_back_to_a_default_scan_type(): void
    {
        $result = app(InvestigationAssistantToolService::class)->execute(
            'dispatch_instagram_scan',
            ['instagram_username' => 'beispielprofil'],
            new \stdClass,
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('MISSING_SCAN_TYPE', data_get($result, 'error.code'));
    }

    public function test_chat_profile_badges_trigger_scan_selection_instead_of_navigation(): void
    {
        $view = file_get_contents(resource_path('views/livewire/tools/chatbot.blade.php'));

        $this->assertStringContainsString('x-on:click="requestProfileScan(profile)"', $view);
        $this->assertStringContainsString('Scan auswählen', $view);
        $this->assertStringNotContainsString('x-on:click="navigateTo(profile.url)"', $view);
    }
}
