<?php

namespace App\Jobs;

use App\Models\TrackedPerson;
use App\Models\TrackedPersonPublicProfile;
use App\Services\TrackedPeople\TrackedPersonInstagramPublicProfileScanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ScanInstagramProfileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;

    public int $tries = 1;

    public function __construct(
        public readonly int $trackedPersonId,
        public readonly int $publicProfileId,
        public readonly int $userId,
    ) {
    }

    public function handle(TrackedPersonInstagramPublicProfileScanService $scanService): void
    {
        $trackedPerson = TrackedPerson::query()
            ->whereKey($this->trackedPersonId)
            ->where('user_id', $this->userId)
            ->first();

        $publicProfile = TrackedPersonPublicProfile::query()
            ->whereKey($this->publicProfileId)
            ->where('tracked_person_id', $this->trackedPersonId)
            ->where('user_id', $this->userId)
            ->first();

        if (! $trackedPerson || ! $publicProfile) {
            Log::warning('Network-map profile scan skipped because the target was not found.', [
                'tracked_person_id' => $this->trackedPersonId,
                'public_profile_id' => $this->publicProfileId,
                'user_id' => $this->userId,
            ]);

            return;
        }

        $publicProfile->forceFill([
            'is_public' => true,
        ])->save();

        $trackedPerson->forceFill([
            'last_instagram_status_level' => 'partial',
            'last_instagram_status_message' => 'Public-Profile-Verbindungsscan laeuft im Hintergrund.',
        ])->save();

        try {
            $scanService->scan($trackedPerson, null, $publicProfile->id);

            $trackedPerson->forceFill([
                'last_instagram_status_level' => 'success',
                'last_instagram_status_message' => 'Public-Profile-Verbindungsscan aus der Network Map abgeschlossen.',
            ])->save();
        } catch (\Throwable $exception) {
            Log::warning('Network-map profile scan failed.', [
                'tracked_person_id' => $this->trackedPersonId,
                'public_profile_id' => $this->publicProfileId,
                'user_id' => $this->userId,
                'error' => $exception->getMessage(),
            ]);

            $trackedPerson->forceFill([
                'last_instagram_status_level' => 'error',
                'last_instagram_status_message' => 'Public-Profile-Verbindungsscan fehlgeschlagen: '.$exception->getMessage(),
            ])->save();

            throw $exception;
        }
    }
}
