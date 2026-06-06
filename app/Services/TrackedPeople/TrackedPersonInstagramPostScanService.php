<?php

namespace App\Services\TrackedPeople;

use App\Models\InstagramPost;
use App\Models\InstagramProfile;
use App\Models\InstagramPostScan;
use App\Models\TrackedPerson;
use App\Models\TrackedPersonInstagramSnapshot;
use App\Services\Billing\ScanCreditService;
use App\Services\Social\InstagramScraper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TrackedPersonInstagramPostScanService
{
    public function __construct(
        private readonly InstagramScraper $scraper,
        private readonly TrackedPersonInstagramScanCoordinator $scanCoordinator,
        private readonly InstagramProfileRelationshipStore $profileRelationshipStore,
        private readonly ScanCreditService $scanCreditService,
    ) {
    }

    private ?array $activeScanControl = null;

    public function scan(
        TrackedPerson $trackedPerson,
        ?TrackedPersonInstagramSnapshot $snapshot = null,
        ?callable $progress = null,
    ): InstagramPostScan {
        $username = $this->scraper->normalizeInstagramUsername($trackedPerson->instagram_username);

        if ($username === null) {
            throw new \RuntimeException('Fuer diese Person ist kein Instagram-Name hinterlegt.');
        }

        $scanControl = $this->scanCoordinator->begin($trackedPerson->id, 'Instagram-Beitragsscan');
        $lock = Cache::lock('tracked-person-instagram-post-scan:'.$trackedPerson->id, 3600);

        if (! $lock->get()) {
            $this->scanCoordinator->finish($trackedPerson->id, (int) $scanControl['generation']);
            throw new \RuntimeException('Fuer diese Person laeuft bereits ein Instagram-Beitragsscan.');
        }

        $this->activeScanControl = $scanControl;

        try {
            $profile = $this->profileRelationshipStore->syncTrackedPersonProfile($trackedPerson);

            if (! $profile) {
                throw new \RuntimeException('Das Instagram-Profil konnte fuer den Beitragsscan nicht gespeichert werden.');
            }

            $payload = $this->scraper->scrape(
                $username,
                'posts',
                $progress,
                $this->withActiveScanControl([
                    'postScanMaxItems' => 100,
                    'postScanMaxScrollRounds' => 40,
                ]),
            );

            return $this->storeScan($trackedPerson, $profile, $snapshot, $payload);
        } finally {
            $lock->release();
            $this->scanCoordinator->finish($trackedPerson->id, (int) $scanControl['generation']);
            $this->activeScanControl = null;
        }
    }

    private function storeScan(
        TrackedPerson $trackedPerson,
        InstagramProfile $profile,
        ?TrackedPersonInstagramSnapshot $snapshot,
        array $payload,
    ): InstagramPostScan {
        $this->assertActiveScanCurrent();

        $postPayload = is_array($payload['postsScan'] ?? null) ? $payload['postsScan'] : [];
        $posts = $this->normalizePosts($postPayload['items'] ?? []);
        $scannedAt = now('UTC');

        $scan = DB::transaction(function () use (
            $trackedPerson,
            $profile,
            $snapshot,
            $payload,
            $postPayload,
            $posts,
            $scannedAt,
        ): InstagramPostScan {
            $scan = InstagramPostScan::create([
                'instagram_profile_id' => $profile->id,
                'tracked_person_id' => $trackedPerson->id,
                'snapshot_id' => $snapshot?->id,
                'user_id' => $trackedPerson->user_id,
                'status_level' => (string) ($payload['statusLevel'] ?? $postPayload['statusLevel'] ?? 'unknown'),
                'status_message' => (string) ($payload['statusMessage'] ?? $postPayload['statusMessage'] ?? 'Instagram-Beitragsscan abgeschlossen.'),
                'attempted' => (bool) ($postPayload['attempted'] ?? true),
                'available' => (bool) ($postPayload['available'] ?? ($posts !== [])),
                'complete' => (bool) ($postPayload['complete'] ?? false),
                'rate_limited' => (bool) ($postPayload['rateLimited'] ?? false),
                'gracefully_stopped' => (bool) ($payload['gracefullyStopped'] ?? $postPayload['gracefullyStopped'] ?? false),
                'observed_count' => count($posts),
                'new_count' => 0,
                'updated_count' => 0,
                'unchanged_count' => 0,
                'raw_payload' => $payload,
                'scanned_at' => $scannedAt,
            ]);
            $counts = ['new' => 0, 'updated' => 0, 'unchanged' => 0];

            foreach ($posts as $postData) {
                $post = InstagramPost::withTrashed()
                    ->where('shortcode', $postData['shortcode'])
                    ->first();

                if (! $post) {
                    InstagramPost::create([
                        ...$postData,
                        'instagram_profile_id' => $profile->id,
                        'first_seen_scan_id' => $scan->id,
                        'last_seen_scan_id' => $scan->id,
                        'first_seen_at' => $scannedAt,
                        'last_seen_at' => $scannedAt,
                        'last_scanned_at' => $scannedAt,
                    ]);
                    $counts['new']++;

                    continue;
                }

                $changed = collect([
                    'likes_count',
                    'comments_count',
                    'caption',
                    'thumbnail_url',
                    'published_at',
                    'media_type',
                ])->contains(fn (string $field): bool => $this->valuesDiffer($post->{$field}, $postData[$field] ?? null));

                $post->restore();
                $post->forceFill([
                    ...$postData,
                    'instagram_profile_id' => $profile->id,
                    'last_seen_scan_id' => $scan->id,
                    'first_seen_scan_id' => $post->first_seen_scan_id ?: $scan->id,
                    'first_seen_at' => $post->first_seen_at ?: $scannedAt,
                    'last_seen_at' => $scannedAt,
                    'last_scanned_at' => $scannedAt,
                ])->save();
                $counts[$changed ? 'updated' : 'unchanged']++;
            }

            $scan->forceFill([
                'new_count' => $counts['new'],
                'updated_count' => $counts['updated'],
                'unchanged_count' => $counts['unchanged'],
            ])->save();

            return $scan;
        });

        $trackedPerson->forceFill([
            'last_instagram_status_level' => $scan->status_level,
            'last_instagram_status_message' => $scan->status_message,
            'last_instagram_analyzed_at' => $scan->scanned_at,
        ])->save();
        $profile->forceFill([
            'last_status_level' => $scan->status_level,
            'last_status_message' => $scan->status_message,
            'last_scanned_at' => $scan->scanned_at,
        ])->save();

        $this->scanCreditService->charge(
            (int) $trackedPerson->user_id,
            $scan,
            $payload,
            'Instagram-Beitragsscan @'.$trackedPerson->instagram_username,
        );

        return $scan->fresh();
    }

    private function normalizePosts(mixed $posts): array
    {
        if (! is_array($posts)) {
            return [];
        }

        $normalized = [];

        foreach ($posts as $post) {
            if (! is_array($post) || ! is_scalar($post['shortcode'] ?? null)) {
                continue;
            }

            $shortcode = trim((string) $post['shortcode']);

            if ($shortcode === '') {
                continue;
            }

            $normalized[$shortcode] = [
                'shortcode' => $shortcode,
                'media_type' => in_array(($post['mediaType'] ?? null), ['post', 'reel', 'tv'], true)
                    ? $post['mediaType']
                    : 'post',
                'post_url' => trim((string) ($post['postUrl'] ?? 'https://www.instagram.com/p/'.$shortcode.'/')),
                'thumbnail_url' => $this->nullableString($post['thumbnailUrl'] ?? null),
                'caption' => $this->nullableString($post['caption'] ?? null),
                'likes_count' => $this->nullableInteger($post['likesCount'] ?? null),
                'comments_count' => $this->nullableInteger($post['commentsCount'] ?? null),
                'published_at' => $this->parseTimestamp($post['publishedAt'] ?? null),
                'raw_post' => $post,
            ];
        }

        return array_values($normalized);
    }

    private function valuesDiffer(mixed $before, mixed $after): bool
    {
        if ($before instanceof Carbon) {
            $before = $before->toIso8601String();
        }

        if ($after instanceof Carbon) {
            $after = $after->toIso8601String();
        }

        return $before !== $after;
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function nullableInteger(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    private function withActiveScanControl(array $runtimeConfigOverrides): array
    {
        return $this->activeScanControl
            ? [...$runtimeConfigOverrides, '_scanControl' => $this->activeScanControl]
            : $runtimeConfigOverrides;
    }

    private function assertActiveScanCurrent(): void
    {
        if (! $this->activeScanControl) {
            return;
        }

        $this->scanCoordinator->assertCurrent(
            (int) $this->activeScanControl['trackedPersonId'],
            (int) $this->activeScanControl['generation'],
        );
    }
}
