<?php

namespace Tests\Feature;

use App\Services\Ai\InvestigationAssistantScanStatusStore;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvestigationAssistantScanStatusStoreTest extends TestCase
{
    public function test_it_tracks_an_assistant_scan_from_queue_to_completion(): void
    {
        $token = (string) Str::uuid();
        $store = app(InvestigationAssistantScanStatusStore::class);

        $queued = $store->start($token, [
            'user_id' => 42,
            'tracked_person_id' => 7,
            'label' => 'Instagram-Vollanalyse',
        ]);
        $running = $store->progress($token, [
            'phase' => 'followers',
            'percent' => 48,
            'message' => 'Followerliste wird geladen.',
            'loaded' => 120,
            'expected' => 250,
        ]);
        $completed = $store->complete($token, [
            'snapshot_id' => 99,
        ]);

        $this->assertSame('queued', $queued['status']);
        $this->assertSame('running', $running['status']);
        $this->assertSame(48, $running['percent']);
        $this->assertSame(120, $running['loaded']);
        $this->assertSame('completed', $completed['status']);
        $this->assertSame(100, $completed['percent']);
        $this->assertSame(99, data_get($store->get($token), 'result.snapshot_id'));
    }

    public function test_it_marks_an_interrupted_scan_as_resumable_until_it_is_dismissed(): void
    {
        $token = (string) Str::uuid();
        $store = app(InvestigationAssistantScanStatusStore::class);
        $store->start($token, [
            'user_id' => 42,
            'tracked_person_id' => 7,
            'scan_type' => 'followers',
            'label' => 'Followerlisten-Scan',
        ]);

        $paused = $store->pause($token, 'Scan wurde unterbrochen.', [
            'observed_count' => 120,
        ]);
        $dismissed = $store->dismiss($token, 'Datenstand wurde behalten.');

        $this->assertSame('paused', $paused['status']);
        $this->assertTrue($paused['resumable']);
        $this->assertSame(120, data_get($paused, 'result.observed_count'));
        $this->assertSame('dismissed', $dismissed['status']);
        $this->assertFalse($dismissed['resumable']);
    }

    public function test_it_tracks_the_saving_phase_after_a_stop_request(): void
    {
        $token = (string) Str::uuid();
        $store = app(InvestigationAssistantScanStatusStore::class);
        $store->start($token, [
            'user_id' => 42,
            'tracked_person_id' => 7,
        ]);

        $stopping = $store->stopping($token, 'Zwischenstand wird gespeichert.');

        $this->assertSame('stopping', $stopping['status']);
        $this->assertSame('saving', $stopping['phase']);
        $this->assertTrue($stopping['stop_requested']);
    }
}
