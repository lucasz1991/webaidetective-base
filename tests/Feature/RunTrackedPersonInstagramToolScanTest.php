<?php

namespace Tests\Feature;

use App\Jobs\RunTrackedPersonInstagramToolScan;
use ReflectionMethod;
use Tests\TestCase;

class RunTrackedPersonInstagramToolScanTest extends TestCase
{
    public function test_gracefully_stopped_result_is_resumable(): void
    {
        $job = new RunTrackedPersonInstagramToolScan(1, 'suggestions');
        $method = new ReflectionMethod($job, 'resultIsResumable');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($job, (object) [
            'status_level' => 'partial',
            'gracefully_stopped' => true,
            'raw_payload' => [],
        ]));
        $this->assertTrue($method->invoke($job, [
            'resolvedStatusLevel' => 'partial',
            'snapshot' => (object) [
                'status_level' => 'cancelled',
                'raw_payload' => ['gracefullyStopped' => true],
            ],
        ]));
        $this->assertFalse($method->invoke($job, (object) [
            'status_level' => 'success',
            'gracefully_stopped' => false,
            'raw_payload' => [],
        ]));
    }

    public function test_chat_and_profile_views_offer_resume_or_keep_saved_data(): void
    {
        $chatView = file_get_contents(resource_path('views/livewire/tools/chatbot.blade.php'));
        $profileView = file_get_contents(resource_path('views/livewire/user/tracked-person-scan-controls.blade.php'));

        $this->assertStringContainsString('resumePausedScan(scan)', $chatView);
        $this->assertStringContainsString('dismissAssistantScan(scan.token)', $chatView);
        $this->assertStringContainsString('Scan fortsetzen', $profileView);
        $this->assertStringContainsString('Beenden, Daten behalten', $profileView);
    }

    public function test_chat_hides_active_scans_with_an_expired_heartbeat(): void
    {
        $view = file_get_contents(resource_path('views/livewire/tools/chatbot.blade.php'));

        $this->assertStringContainsString('scan?.heartbeat_at || scan?.updated_at', $view);
        $this->assertStringContainsString('now - updatedAt <= maxAge', $view);
    }

    public function test_resume_button_waits_for_livewire_and_shows_starting_state(): void
    {
        $view = file_get_contents(resource_path('views/livewire/tools/chatbot.blade.php'));

        $this->assertStringContainsString('async resumePausedScan(scan)', $view);
        $this->assertStringContainsString('await $wire.resumeAssistantScan(token)', $view);
        $this->assertStringContainsString('Wird gestartet...', $view);
    }
}
