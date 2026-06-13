<?php

namespace Tests\Feature;

use App\Livewire\Tools\Chatbot;
use App\Services\Ai\InvestigationAssistantToolService;
use ReflectionMethod;
use Tests\TestCase;

class InvestigationAssistantProfileScanSelectionTest extends TestCase
{
    public function test_profile_badge_selection_requires_an_explicit_scan_type(): void
    {
        $service = app(InvestigationAssistantToolService::class);
        $scanTool = collect($service->tools())
            ->firstWhere('function.name', 'dispatch_instagram_scan');
        $optionsTool = collect($service->tools())
            ->firstWhere('function.name', 'present_chat_options');

        $this->assertNotNull($scanTool);
        $this->assertNotNull($optionsTool);
        $this->assertContains('scan_type', data_get($scanTool, 'function.parameters.required', []));
        $this->assertStringContainsString('[SCAN_TARGET_SELECTED]', $service->systemPrompt());
        $this->assertStringContainsString('[SCAN_TYPE_CONFIRMED]', $service->systemPrompt());
        $this->assertStringContainsString('present_chat_options', $service->systemPrompt());
        $this->assertStringContainsString('Starte bis zur eindeutigen Auswahl kein Scan-Tool', $service->systemPrompt());
    }

    public function test_chat_options_are_returned_as_silent_structured_ui_data(): void
    {
        $result = app(InvestigationAssistantToolService::class)->execute(
            'present_chat_options',
            [
                'prompt' => 'Welcher Scan soll gestartet werden?',
                'options' => [
                    [
                        'label' => 'Mini-Scan',
                        'description' => 'Schnelle Basispruefung',
                        'prompt' => 'Starte den Mini-Scan.',
                    ],
                    [
                        'label' => 'Vollanalyse',
                        'description' => 'Umfassende Analyse',
                        'prompt' => 'Starte die Vollanalyse.',
                    ],
                ],
            ],
            new \stdClass,
        );

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['silent']);
        $this->assertCount(2, data_get($result, 'chat_options.options', []));
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

    public function test_confirmed_scan_command_is_rendered_as_a_short_user_message(): void
    {
        $method = new ReflectionMethod(Chatbot::class, 'displayMessageForUserPrompt');
        $method->setAccessible(true);

        $message = $method->invoke(
            new Chatbot,
            implode("\n", [
                '[SCAN_TYPE_CONFIRMED]',
                'Starte jetzt für @beispiel den Scan-Typ "mini".',
                'Ausgewählte Aktion: Mini-Scan.',
            ]),
        );

        $this->assertSame('Mini-Scan für @beispiel starten.', $message);
    }

    public function test_chat_profile_badges_trigger_scan_selection_instead_of_navigation(): void
    {
        $view = file_get_contents(resource_path('views/livewire/tools/chatbot.blade.php'));

        $this->assertStringContainsString('x-on:click="requestProfileScan(profile)"', $view);
        $this->assertStringContainsString('Scan auswählen', $view);
        $this->assertStringContainsString('requestProfileScanType(profile', $view);
        $this->assertStringContainsString('group-hover/profile:opacity-100', $view);
        $this->assertStringNotContainsString('x-on:click="navigateTo(profile.url)"', $view);
    }
}
