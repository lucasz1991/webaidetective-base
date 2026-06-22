<?php

namespace App\Services\TrackedPeople;

use App\Models\InstagramProfile;
use App\Models\InstagramStoryItem;
use App\Models\InstagramStoryScan;
use App\Models\TrackedPerson;
use App\Models\TrackedPersonInstagramSnapshot;
use App\Services\Billing\ScanCreditService;
use App\Services\Social\InstagramScraper;
use App\Services\Social\InstagramStoryMediaStorage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TrackedPersonInstagramStoryScanService
{
    private ?array $activeScanControl = null;

    public function __construct(
        private readonly InstagramScraper $scraper,
        private readonly TrackedPersonInstagramScanCoordinator $scanCoordinator,
        private readonly InstagramProfileRelationshipStore $profileRelationshipStore,
        private readonly ScanCreditService $scanCreditService,
        private readonly InstagramStoryMediaStorage $mediaStorage,
        private readonly InstagramScanEventStore $scanEvents,
    ) {}

    public function scan(
        TrackedPerson $trackedPerson,
        string $scanType = 'stories',
        ?TrackedPersonInstagramSnapshot $snapshot = null,
        ?callable $progress = null,
    ): InstagramStoryScan {
        $scanType = $this->normalizeScanType($scanType);
        $username = $this->scraper->normalizeInstagramUsername($trackedPerson->instagram_username);

        if ($username === null) {
            throw new \RuntimeException('Fuer diese Person ist kein Instagram-Name hinterlegt.');
        }

        $profile = $this->profileRelationshipStore->syncTrackedPersonProfile($trackedPerson);

        if (! $profile) {
            throw new \RuntimeException('Das Instagram-Profil konnte fuer den Story-Scan nicht gespeichert werden.');
        }

        $label = $scanType === 'highlights' ? 'Instagram-Highlight-Scan' : 'Instagram-Story-Scan';
        $scanControl = $this->scanCoordinator->begin($trackedPerson->id, $label, [
            'scan_type' => $scanType,
            'target_username' => $username,
            'instagram_profile_id' => $profile->id,
            'user_id' => $trackedPerson->user_id,
        ]);
        $this->activeScanControl = $scanControl;

        try {
            $scan = $this->scanWithLock($trackedPerson, $profile, $snapshot, $scanType, $progress);
            $this->scanCoordinator->completeFromResult(
                $trackedPerson->id,
                (int) $scanControl['generation'],
                $scan,
                $label.' abgeschlossen.',
            );

            return $scan;
        } catch (\Throwable $exception) {
            $this->scanCoordinator->failForRetry(
                $trackedPerson->id,
                (int) $scanControl['generation'],
                $exception->getMessage(),
            );

            throw $exception;
        } finally {
            $this->scanCoordinator->finish($trackedPerson->id, (int) $scanControl['generation']);
            $this->activeScanControl = null;
        }
    }

    private function scanWithLock(
        TrackedPerson $trackedPerson,
        InstagramProfile $profile,
        ?TrackedPersonInstagramSnapshot $snapshot,
        string $scanType,
        ?callable $progress,
    ): InstagramStoryScan {
        $username = $this->scraper->normalizeInstagramUsername($profile->username);
        $lock = Cache::lock('instagram-profile-'.$scanType.'-scan:'.$username, 1800);

        if (! $lock->get()) {
            throw new \RuntimeException('Fuer dieses Profil laeuft bereits ein Instagram-'.($scanType === 'highlights' ? 'Highlight' : 'Story').'-Scan.');
        }

        try {
            $progressScan = $this->startProgressScan($trackedPerson, $profile, $snapshot, $scanType);
            $callback = function (array $state) use ($progress, $progressScan, $trackedPerson, $username, $scanType): void {
                $this->scanEvents->progress(
                    'instagram_'.$scanType.'_scan',
                    $progressScan,
                    $username,
                    $trackedPerson->id,
                    $trackedPerson->user_id,
                    $state,
                );

                if ($progress) {
                    $progress($state);
                }
            };
            $payload = $this->scraper->scrape(
                $username,
                $scanType,
                $callback,
                $this->activeScanControl ? ['_scanControl' => $this->activeScanControl] : [],
            );

            return $this->storeScan($trackedPerson, $profile, $snapshot, $scanType, $payload, $progressScan);
        } catch (\Throwable $exception) {
            if (isset($progressScan)) {
                $this->markFailed($progressScan, $trackedPerson, $exception);
            }

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    private function storeScan(
        TrackedPerson $trackedPerson,
        InstagramProfile $profile,
        ?TrackedPersonInstagramSnapshot $snapshot,
        string $scanType,
        array $payload,
        InstagramStoryScan $scan,
    ): InstagramStoryScan {
        $this->assertActiveScanCurrent();
        $resultKey = $scanType === 'highlights' ? 'highlightScan' : 'storyScan';
        $result = is_array($payload[$resultKey] ?? null) ? $payload[$resultKey] : [];
        $items = $this->normalizeItems($result['items'] ?? [], $scanType);
        $scannedAt = now('UTC');

        $scan->forceFill([
            'status_level' => (string) ($payload['statusLevel'] ?? $result['statusLevel'] ?? 'unknown'),
            'status_message' => (string) ($payload['statusMessage'] ?? $result['statusMessage'] ?? 'Instagram-Scan abgeschlossen.'),
            'attempted' => (bool) ($result['attempted'] ?? true),
            'available' => (bool) ($result['available'] ?? ($items !== [])),
            'complete' => (bool) ($result['complete'] ?? false),
            'gracefully_stopped' => (bool) ($payload['gracefullyStopped'] ?? $result['gracefullyStopped'] ?? false),
            'observed_count' => count($items),
            'raw_payload' => $payload,
            'scanned_at' => $scannedAt,
        ])->save();

        foreach ($items as $itemData) {
            $item = InstagramStoryItem::create([
                ...$itemData,
                'instagram_story_scan_id' => $scan->id,
                'instagram_profile_id' => $profile->id,
            ]);

            try {
                $this->mediaStorage->store($item->loadMissing('instagramProfile'));
            } catch (\Throwable $exception) {
                Log::warning('Instagram-Story-Medium konnte nicht lokal gespeichert werden.', [
                    'instagram_story_item_id' => $item->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $trackedPerson->forceFill([
            'last_instagram_status_level' => $scan->status_level,
            'last_instagram_status_message' => $scan->status_message,
            'last_instagram_analyzed_at' => $scannedAt,
        ])->save();
        $profile->forceFill([
            'last_status_level' => $scan->status_level,
            'last_status_message' => $scan->status_message,
            'last_scanned_at' => $scannedAt,
        ])->save();

        $this->scanCreditService->charge(
            (int) $trackedPerson->user_id,
            $scan,
            $payload,
            ($scanType === 'highlights' ? 'Instagram-Highlight-Scan @' : 'Instagram-Story-Scan @').$profile->username,
        );
        $this->scanEvents->finished(
            'instagram_'.$scanType.'_scan',
            $scan,
            $profile->username,
            $trackedPerson->id,
            $trackedPerson->user_id,
            $scan->status_message,
            [
                'phase' => $scanType,
                'statusLevel' => $scan->status_level,
                'loaded' => count($items),
                'percent' => 100,
            ],
        );

        return $scan->fresh(['items']);
    }

    private function startProgressScan(
        TrackedPerson $trackedPerson,
        InstagramProfile $profile,
        ?TrackedPersonInstagramSnapshot $snapshot,
        string $scanType,
    ): InstagramStoryScan {
        $scan = InstagramStoryScan::create([
            'instagram_profile_id' => $profile->id,
            'tracked_person_id' => $trackedPerson->id,
            'snapshot_id' => $snapshot?->id,
            'user_id' => $trackedPerson->user_id,
            'instagram_username' => $profile->username,
            'scan_type' => $scanType,
            'status_level' => 'partial',
            'status_message' => 'Instagram-'.($scanType === 'highlights' ? 'Highlight' : 'Story').'-Scan laeuft.',
            'attempted' => true,
            'available' => false,
            'complete' => false,
            'gracefully_stopped' => false,
            'observed_count' => 0,
            'raw_payload' => ['progressStatus' => 'in_progress'],
            'scanned_at' => now('UTC'),
        ]);
        $this->scanEvents->started(
            'instagram_'.$scanType.'_scan',
            $scan,
            $profile->username,
            $trackedPerson->id,
            $trackedPerson->user_id,
            $scan->status_message,
            ['phase' => $scanType],
        );

        return $scan;
    }

    private function markFailed(InstagramStoryScan $scan, TrackedPerson $trackedPerson, \Throwable $exception): void
    {
        $message = 'Instagram-'.($scan->scan_type === 'highlights' ? 'Highlight' : 'Story').'-Scan fehlgeschlagen: '.$exception->getMessage();
        $scan->forceFill([
            'status_level' => 'error',
            'status_message' => $message,
            'raw_payload' => [
                ...(is_array($scan->raw_payload) ? $scan->raw_payload : []),
                'progressStatus' => 'failed',
                'error' => $exception->getMessage(),
            ],
            'scanned_at' => now('UTC'),
        ])->save();
        $this->scanEvents->failed(
            'instagram_'.$scan->scan_type.'_scan',
            $scan,
            $scan->instagram_username,
            $trackedPerson->id,
            $trackedPerson->user_id,
            $message,
        );
    }

    private function normalizeItems(mixed $items, string $scanType): array
    {
        if (! is_array($items)) {
            return [];
        }

        return collect($items)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(function (array $item, int $index) use ($scanType): array {
                $highlightId = $this->nullableString($item['highlightId'] ?? null);
                $storyId = $this->nullableString($item['storyId'] ?? null);
                $sourceUrl = $this->nullableString($item['sourceUrl'] ?? $item['coverUrl'] ?? null);
                $storyUrl = $this->nullableString($item['storyUrl'] ?? $item['highlightUrl'] ?? null);
                $itemKey = $highlightId ?: $storyId ?: hash('sha256', implode('|', [
                    $storyUrl,
                    $sourceUrl,
                    (string) $index,
                ]));

                return [
                    'item_key' => $itemKey,
                    'source_type' => $scanType,
                    'highlight_id' => $highlightId,
                    'highlight_title' => $this->nullableString($item['title'] ?? $item['highlightTitle'] ?? null),
                    'position' => max(0, (int) ($item['position'] ?? $index)),
                    'media_type' => ($item['mediaType'] ?? null) === 'video' ? 'video' : 'image',
                    'story_url' => $storyUrl,
                    'source_url' => $sourceUrl,
                    'preview_url' => $this->nullableString($item['previewUrl'] ?? $item['coverUrl'] ?? $sourceUrl),
                    'width' => $this->nullableInteger($item['width'] ?? null),
                    'height' => $this->nullableInteger($item['height'] ?? null),
                    'duration_ms' => is_numeric($item['durationSeconds'] ?? null)
                        ? max(0, (int) round((float) $item['durationSeconds'] * 1000))
                        : null,
                    'text' => $this->nullableString($item['text'] ?? null),
                    'published_at' => $this->parseTimestamp($item['publishedAt'] ?? null),
                    'raw_item' => $item,
                ];
            })
            ->unique('item_key')
            ->values()
            ->all();
    }

    private function normalizeScanType(string $scanType): string
    {
        return strtolower(trim($scanType)) === 'highlights' ? 'highlights' : 'stories';
    }

    private function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function nullableInteger(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        try {
            return $this->nullableString($value) ? Carbon::parse((string) $value)->timezone('UTC') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function assertActiveScanCurrent(): void
    {
        if ($this->activeScanControl) {
            $this->scanCoordinator->assertCurrent(
                (int) $this->activeScanControl['trackedPersonId'],
                (int) $this->activeScanControl['generation'],
            );
        }
    }
}
