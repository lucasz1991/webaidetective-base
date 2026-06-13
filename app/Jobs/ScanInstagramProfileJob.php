<?php

namespace App\Jobs;

use App\Livewire\User\NetworkMap;
use App\Models\InstagramProfile;
use App\Models\TrackedPerson;
use App\Models\TrackedPersonPublicProfile;
use App\Services\Ai\InvestigationAssistantScanStatusStore;
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

    public ?string $assistantScanToken = null;

    public function __construct(
        int $trackedPersonId,
        int $instagramProfileId,
        int $userId,
        ?string $assistantScanToken = null,
    ) {
        $this->trackedPersonId = $trackedPersonId;
        $this->instagramProfileId = $instagramProfileId;
        $this->userId = $userId;
        $this->assistantScanToken = $assistantScanToken;
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
            $this->failAssistantScan('Das zu scannende Instagram-Profil wurde nicht gefunden.');

            return;
        }

        $statusStore = app(InvestigationAssistantScanStatusStore::class);
        $progress = $this->assistantScanToken
            ? fn (array $state) => $statusStore->progress($this->assistantScanToken, $state)
            : null;

        if ($this->assistantScanToken) {
            $statusStore->progress($this->assistantScanToken, [
                'percent' => 1,
                'phase' => 'starting',
                'message' => 'Profil-Vollanalyse fuer @'.$profile->username.' wurde gestartet.',
            ]);
        }

        $profile->forceFill([
            'last_status_level' => 'partial',
            'last_status_message' => 'Profil-Vollanalyse laeuft im Hintergrund.',
        ])->save();

        try {
            $scanService->scan($profile, $this->userId, true, $progress);

            if ($this->assistantScanToken) {
                $freshProfile = $profile->fresh() ?: $profile;
                $statusStore->complete(
                    $this->assistantScanToken,
                    [
                        'tracked_person_id' => $trackedPerson?->id,
                        'instagram_profile_id' => (int) $freshProfile->id,
                        'instagram_username' => $freshProfile->username,
                        'status_level' => $freshProfile->last_status_level,
                        'status_message' => $freshProfile->last_status_message,
                        'scanned_at' => optional($freshProfile->last_scanned_at)?->toIso8601String(),
                    ],
                    'Profil-Vollanalyse fuer @'.$freshProfile->username.' wurde abgeschlossen.',
                );
            }

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
            $this->failAssistantScan('Profil-Vollanalyse fehlgeschlagen: '.$exception->getMessage());
            NetworkMap::forgetGraphCacheForUser($this->userId);
            if ($trackedPerson) {
                NetworkMap::forgetGraphCacheForUser($this->userId, $trackedPerson->id);
            }

            throw $exception;
        }
    }

    private function failAssistantScan(string $message): void
    {
        if (! $this->assistantScanToken) {
            return;
        }

        app(InvestigationAssistantScanStatusStore::class)->fail($this->assistantScanToken, $message);
    }
}
