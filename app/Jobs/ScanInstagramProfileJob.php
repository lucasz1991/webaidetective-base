<?php

namespace App\Jobs;

use App\Livewire\User\NetworkMap;
use App\Models\InstagramProfile;
use App\Models\TrackedPerson;
use App\Models\TrackedPersonPublicProfile;
use App\Services\TrackedPeople\InstagramProfileRelationshipStore;
use App\Services\TrackedPeople\InstagramProfileScanService;
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

    public int $trackedPersonId;

    public int $instagramProfileId;

    public int $userId;

    public ?int $publicProfileId = null;

    public function __construct(int $trackedPersonId, int $instagramProfileId, int $userId)
    {
        $this->trackedPersonId = $trackedPersonId;
        $this->instagramProfileId = $instagramProfileId;
        $this->userId = $userId;
    }

    public function handle(
        InstagramProfileScanService $scanService,
        InstagramProfileRelationshipStore $profileRelationshipStore,
    ): void {
        $trackedPerson = TrackedPerson::query()
            ->whereKey($this->trackedPersonId)
            ->where('user_id', $this->userId)
            ->first();

        $instagramProfileId = isset($this->instagramProfileId) ? (int) $this->instagramProfileId : 0;
        $profile = $instagramProfileId > 0
            ? InstagramProfile::query()->whereKey($instagramProfileId)->first()
            : null;

        if (! $profile && isset($this->publicProfileId)) {
            $publicProfile = TrackedPersonPublicProfile::query()
                ->with('instagramProfile')
                ->whereKey((int) $this->publicProfileId)
                ->where('tracked_person_id', $this->trackedPersonId)
                ->where('user_id', $this->userId)
                ->first();
            $profile = $publicProfile?->instagramProfile ?: ($publicProfile ? $profileRelationshipStore->syncPublicProfile($publicProfile) : null);
        }

        if (! $profile) {
            Log::warning('Network-map profile scan skipped because the target was not found.', [
                'tracked_person_id' => $this->trackedPersonId,
                'instagram_profile_id' => $instagramProfileId ?: null,
                'legacy_public_profile_id' => isset($this->publicProfileId) ? (int) $this->publicProfileId : null,
                'user_id' => $this->userId,
            ]);

            return;
        }

        $profile->forceFill([
            'last_status_level' => 'partial',
            'last_status_message' => 'Profil-Vollanalyse laeuft im Hintergrund.',
        ])->save();

        try {
            $scanService->scan($profile, $this->userId, true);

            NetworkMap::forgetGraphCacheForUser($this->userId);
            if ($trackedPerson) {
                NetworkMap::forgetGraphCacheForUser($this->userId, $trackedPerson->id);
            }
        } catch (\Throwable $exception) {
            Log::warning('Network-map profile scan failed.', [
                'tracked_person_id' => $this->trackedPersonId,
                'instagram_profile_id' => $profile->id,
                'legacy_public_profile_id' => isset($this->publicProfileId) ? (int) $this->publicProfileId : null,
                'user_id' => $this->userId,
                'error' => $exception->getMessage(),
            ]);

            $profile->forceFill([
                'last_status_level' => 'error',
                'last_status_message' => 'Profil-Vollanalyse fehlgeschlagen: '.$exception->getMessage(),
            ])->save();
            NetworkMap::forgetGraphCacheForUser($this->userId);
            if ($trackedPerson) {
                NetworkMap::forgetGraphCacheForUser($this->userId, $trackedPerson->id);
            }

            throw $exception;
        }
    }
}
