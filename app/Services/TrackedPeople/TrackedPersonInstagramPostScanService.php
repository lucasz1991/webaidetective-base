<?php

namespace App\Services\TrackedPeople;

use App\Models\InstagramPost;
use App\Models\InstagramPostComment;
use App\Models\InstagramPostLike;
use App\Models\InstagramPostMetric;
use App\Models\InstagramPostScan;
use App\Models\InstagramProfile;
use App\Models\TrackedPerson;
use App\Models\TrackedPersonInstagramSnapshot;
use App\Services\Billing\ScanCreditService;
use App\Services\Social\InstagramPostMediaStorage;
use App\Services\Social\InstagramScraper;
use App\Services\Support\DatabaseKeepAlive;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class TrackedPersonInstagramPostScanService
{
    public function __construct(
        private readonly InstagramScraper $scraper,
        private readonly TrackedPersonInstagramScanCoordinator $scanCoordinator,
        private readonly InstagramProfileRelationshipStore $profileRelationshipStore,
        private readonly ScanCreditService $scanCreditService,
        private readonly InstagramPostMediaStorage $postMediaStorage,
        private readonly InstagramScanEventStore $scanEvents,
    ) {}

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

        $profile = $this->profileRelationshipStore->syncTrackedPersonProfile($trackedPerson);

        if (! $profile) {
            throw new \RuntimeException('Das Instagram-Profil konnte fuer den Beitragsscan nicht gespeichert werden.');
        }

        $scanControl = $this->scanCoordinator->begin(
            $trackedPerson->id,
            'Instagram-Beitragsscan',
            [
                'scan_type' => 'posts',
                'target_username' => $username,
                'instagram_profile_id' => $profile->id,
                'user_id' => $trackedPerson->user_id,
            ],
        );
        $this->activeScanControl = $scanControl;

        try {
            $scan = $this->scanProfileWithLock($profile, (int) $trackedPerson->user_id, $trackedPerson, $snapshot, $progress);
            $this->scanCoordinator->completeFromResult(
                $trackedPerson->id,
                (int) $scanControl['generation'],
                $scan,
                'Instagram-Beitragsscan abgeschlossen.',
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

    public function scanProfile(
        InstagramProfile $profile,
        int $userId,
        ?callable $progress = null,
    ): InstagramPostScan {
        $username = $this->scraper->normalizeInstagramUsername($profile->username);

        if ($username === null || $userId <= 0) {
            throw new \RuntimeException('Fuer dieses Profil ist kein gueltiger Instagram-Name hinterlegt.');
        }

        $scanContextId = -2000000000 - (int) $profile->id;
        $scanControl = $this->scanCoordinator->begin(
            $scanContextId,
            'Instagram-Profil-Beitragsscan @'.$username,
            [
                'scan_type' => 'profile_posts',
                'scan_context_key' => 'instagram-profile:'.$profile->id,
                'target_username' => $username,
                'instagram_profile_id' => $profile->id,
                'user_id' => $userId,
            ],
        );
        $this->activeScanControl = $scanControl;

        try {
            $scan = $this->scanProfileWithLock($profile, $userId, null, null, $progress);
            $this->scanCoordinator->completeFromResult(
                $scanContextId,
                (int) $scanControl['generation'],
                $scan,
                'Instagram-Profil-Beitragsscan abgeschlossen.',
            );

            return $scan;
        } catch (\Throwable $exception) {
            $this->scanCoordinator->failForRetry(
                $scanContextId,
                (int) $scanControl['generation'],
                $exception->getMessage(),
            );

            throw $exception;
        } finally {
            $this->scanCoordinator->finish($scanContextId, (int) $scanControl['generation']);
            $this->activeScanControl = null;
        }
    }

    private function scanProfileWithLock(
        InstagramProfile $profile,
        int $userId,
        ?TrackedPerson $trackedPerson,
        ?TrackedPersonInstagramSnapshot $snapshot,
        ?callable $progress,
    ): InstagramPostScan {
        $username = $this->scraper->normalizeInstagramUsername($profile->username);

        if ($username === null || $userId <= 0) {
            throw new \RuntimeException('Fuer dieses Profil ist kein gueltiger Instagram-Name hinterlegt.');
        }

        $lock = Cache::lock('instagram-profile-post-scan:'.$username, 3600);

        if (! $lock->get()) {
            throw new \RuntimeException('Fuer dieses Profil laeuft bereits ein Instagram-Beitragsscan.');
        }

        try {
            $progressScan = $this->startProgressScan($profile, $userId, $trackedPerson, $snapshot, $username);
            $scanProgress = $this->createScanEventProgressCallback(
                $progress,
                $progressScan,
                $username,
                $trackedPerson?->id,
                $userId,
            );

            $payload = $this->scraper->scrape(
                $username,
                'posts',
                $scanProgress,
                $this->withActiveScanControl([
                    'postScanUseDedicatedTabs' => true,
                    'postScanOpenLikesDialogEnabled' => true,
                    'postScanOpenCommentsUiEnabled' => true,
                ]),
            );

            return $this->storeScan($trackedPerson, $profile, $snapshot, $payload, $userId, $progressScan);
        } catch (\Throwable $exception) {
            if (isset($progressScan) && $progressScan instanceof InstagramPostScan) {
                $this->markProgressScanFailed($progressScan, $username, $trackedPerson?->id, $userId, $exception);
            }

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    private function storeScan(
        ?TrackedPerson $trackedPerson,
        InstagramProfile $profile,
        ?TrackedPersonInstagramSnapshot $snapshot,
        array $payload,
        int $userId,
        ?InstagramPostScan $existingScan = null,
    ): InstagramPostScan {
        $this->assertActiveScanCurrent();
        DatabaseKeepAlive::ping(0);

        $postPayload = is_array($payload['postsScan'] ?? null) ? $payload['postsScan'] : [];
        $posts = $this->normalizePosts($postPayload['items'] ?? []);
        $rawPayload = $this->withoutPostEngagementDetails($payload);
        $scannedAt = now('UTC');
        $actorProfileIds = $this->ensureEngagementActorProfiles($posts);

        $stored = DatabaseKeepAlive::transaction(function () use (
            $trackedPerson,
            $profile,
            $snapshot,
            $payload,
            $rawPayload,
            $postPayload,
            $posts,
            $scannedAt,
            $userId,
            $actorProfileIds,
            $existingScan,
        ): array {
            $scanData = [
                'instagram_profile_id' => $profile->id,
                'tracked_person_id' => $trackedPerson?->id,
                'snapshot_id' => $snapshot?->id,
                'user_id' => $userId,
                'instagram_username' => $this->normalizeUsername($profile->username),
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
                'raw_payload' => $rawPayload,
                'scanned_at' => $scannedAt,
            ];

            $scan = $existingScan ?: new InstagramPostScan;
            $scan->forceFill($this->withOptionalPostScanUsername($scanData))->save();
            $counts = ['new' => 0, 'updated' => 0, 'unchanged' => 0];
            $mediaQueue = [];

            foreach ($posts as $postData) {
                $mediaItems = $postData['media_items'] ?? [];
                $likes = $postData['likes'] ?? [];
                $comments = $postData['comments'] ?? [];
                $likesComplete = (bool) ($postData['likes_complete'] ?? false);
                $commentsComplete = (bool) ($postData['comments_complete'] ?? false);
                unset(
                    $postData['media_items'],
                    $postData['likes'],
                    $postData['comments'],
                    $postData['likes_complete'],
                    $postData['comments_complete'],
                );
                $post = InstagramPost::withTrashed()
                    ->where('shortcode', $postData['shortcode'])
                    ->first();

                if (! $post) {
                    $post = InstagramPost::create([
                        ...$postData,
                        'instagram_profile_id' => $profile->id,
                        'first_seen_scan_id' => $scan->id,
                        'last_seen_scan_id' => $scan->id,
                        'first_seen_at' => $scannedAt,
                        'last_seen_at' => $scannedAt,
                        'last_scanned_at' => $scannedAt,
                    ]);
                    $counts['new']++;
                } else {
                    $updateData = $this->withoutEmptyReplacementValues($postData);
                    $changed = collect([
                        'likes_count',
                        'comments_count',
                        'caption',
                        'published_at',
                        'media_type',
                        'media_pk',
                        'media_count',
                    ])->contains(function (string $field) use ($post, $updateData): bool {
                        return array_key_exists($field, $updateData)
                            && $this->valuesDiffer($post->{$field}, $updateData[$field]);
                    });

                    $post->restore();
                    $post->forceFill([
                        ...$updateData,
                        'instagram_profile_id' => $profile->id,
                        'last_seen_scan_id' => $scan->id,
                        'first_seen_scan_id' => $post->first_seen_scan_id ?: $scan->id,
                        'first_seen_at' => $post->first_seen_at ?: $scannedAt,
                        'last_seen_at' => $scannedAt,
                        'last_scanned_at' => $scannedAt,
                    ])->save();
                    $counts[$changed ? 'updated' : 'unchanged']++;
                }

                InstagramPostMetric::updateOrCreate(
                    [
                        'instagram_post_id' => $post->id,
                        'instagram_post_scan_id' => $scan->id,
                    ],
                    [
                        'likes_count' => $postData['likes_count'] ?? null,
                        'comments_count' => $postData['comments_count'] ?? null,
                        'observed_at' => $scannedAt,
                    ],
                );
                $this->storePostLikes(
                    $post,
                    $scan,
                    $likes,
                    $likesComplete,
                    $actorProfileIds,
                    $scannedAt,
                );
                $this->storePostComments(
                    $post,
                    $scan,
                    $comments,
                    $commentsComplete,
                    $actorProfileIds,
                    $scannedAt,
                );

                if ($mediaItems !== []) {
                    $mediaQueue[$post->id] = $mediaItems;
                }
            }

            $scan->forceFill([
                'new_count' => $counts['new'],
                'updated_count' => $counts['updated'],
                'unchanged_count' => $counts['unchanged'],
            ])->save();

            return [
                'scan' => $scan,
                'media_queue' => $mediaQueue,
            ];
        });
        /** @var InstagramPostScan $scan */
        $scan = $stored['scan'];

        foreach ($stored['media_queue'] as $postId => $mediaItems) {
            try {
                $post = InstagramPost::with('instagramProfile')->find($postId);

                if ($post) {
                    $this->postMediaStorage->storeForPost($post, $mediaItems);
                }
            } catch (\Throwable $exception) {
                Log::warning('Lokale Speicherung der Instagram-Beitragsmedien ist fehlgeschlagen.', [
                    'instagram_post_id' => $postId,
                    'instagram_post_scan_id' => $scan->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if ($trackedPerson) {
            $trackedPerson->forceFill([
                'last_instagram_status_level' => $scan->status_level,
                'last_instagram_status_message' => $scan->status_message,
                'last_instagram_analyzed_at' => $scan->scanned_at,
            ])->save();
        }
        $profile->forceFill([
            'last_status_level' => $scan->status_level,
            'last_status_message' => $scan->status_message,
            'last_scanned_at' => $scan->scanned_at,
        ])->save();

        $this->scanCreditService->charge(
            $userId,
            $scan,
            $payload,
            'Instagram-Beitragsscan @'.$profile->username,
        );

        $this->scanEvents->finished(
            'instagram_post_scan',
            $scan,
            $profile->username,
            $trackedPerson?->id,
            $userId,
            $scan->status_message ?: 'Instagram-Beitragsscan abgeschlossen.',
            [
                'phase' => 'posts',
                'statusLevel' => $scan->status_level,
                'percent' => 100,
                'loaded' => $scan->observed_count,
            ],
        );

        return $scan->fresh();
    }

    private function startProgressScan(
        InstagramProfile $profile,
        int $userId,
        ?TrackedPerson $trackedPerson,
        ?TrackedPersonInstagramSnapshot $snapshot,
        string $username,
    ): InstagramPostScan {
        $now = now('UTC');
        $scan = InstagramPostScan::create($this->withOptionalPostScanUsername([
            'instagram_profile_id' => $profile->id,
            'tracked_person_id' => $trackedPerson?->id,
            'snapshot_id' => $snapshot?->id,
            'user_id' => $userId,
            'instagram_username' => $username,
            'status_level' => 'partial',
            'status_message' => 'Instagram-Beitragsscan laeuft; Fortschritt wird gespeichert.',
            'attempted' => true,
            'available' => false,
            'complete' => false,
            'rate_limited' => false,
            'gracefully_stopped' => false,
            'observed_count' => 0,
            'new_count' => 0,
            'updated_count' => 0,
            'unchanged_count' => 0,
            'raw_payload' => [
                'ok' => false,
                'progressStatus' => 'in_progress',
                'startedAt' => $now->toIso8601String(),
                'instagramUsername' => $username,
            ],
            'scanned_at' => $now,
        ]));

        $this->scanEvents->started(
            'instagram_post_scan',
            $scan,
            $username,
            $trackedPerson?->id,
            $userId,
            'Instagram-Beitragsscan @'.$username.' wurde gestartet.',
            [
                'phase' => 'posts',
                'percent' => 0,
            ],
        );

        return $scan;
    }

    private function createScanEventProgressCallback(
        ?callable $progress,
        InstagramPostScan $scan,
        string $username,
        ?int $trackedPersonId,
        int $userId,
    ): callable {
        return function (array $state) use ($progress, $scan, $username, $trackedPersonId, $userId): void {
            try {
                $this->scanEvents->progress(
                    'instagram_post_scan',
                    $scan->fresh() ?: $scan,
                    $username,
                    $trackedPersonId,
                    $userId,
                    [
                        'phase' => $state['phase'] ?? 'posts',
                        ...$state,
                    ],
                );
            } catch (\Throwable) {
                // Event-Logging darf den Beitragsscan nicht abbrechen.
            }

            if ($progress) {
                $progress($state);
            }
        };
    }

    private function markProgressScanFailed(
        InstagramPostScan $scan,
        string $username,
        ?int $trackedPersonId,
        int $userId,
        \Throwable $exception,
    ): void {
        $payload = is_array($scan->raw_payload) ? $scan->raw_payload : [];
        $message = 'Instagram-Beitragsscan fehlgeschlagen: '.$exception->getMessage();

        $scan->forceFill([
            'status_level' => 'error',
            'status_message' => $message,
            'raw_payload' => [
                ...$payload,
                'ok' => false,
                'progressStatus' => 'failed',
                'error' => $exception->getMessage(),
                'failedAt' => now('UTC')->toIso8601String(),
            ],
            'scanned_at' => now('UTC'),
        ])->save();

        $this->scanEvents->failed(
            'instagram_post_scan',
            $scan,
            $username,
            $trackedPersonId,
            $userId,
            $message,
            [
                'phase' => 'posts',
                'statusLevel' => 'error',
            ],
        );
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

            $rawPost = $post;
            unset(
                $rawPost['likes'],
                $rawPost['comments'],
                $rawPost['likesComplete'],
                $rawPost['commentsComplete'],
            );

            $normalized[$shortcode] = [
                'shortcode' => $shortcode,
                'media_pk' => $this->nullableString($post['mediaPk'] ?? null),
                'media_type' => in_array(($post['mediaType'] ?? null), ['post', 'reel', 'tv'], true)
                    ? $post['mediaType']
                    : 'post',
                'post_url' => trim((string) ($post['postUrl'] ?? 'https://www.instagram.com/p/'.$shortcode.'/')),
                'thumbnail_url' => $this->nullableString($post['thumbnailUrl'] ?? null),
                'media_count' => count($this->normalizeMediaItems($post)),
                'caption' => $this->nullableString($post['caption'] ?? null),
                'likes_count' => $this->nullableInteger($post['likesCount'] ?? null),
                'comments_count' => $this->nullableInteger($post['commentsCount'] ?? null),
                'published_at' => $this->parseTimestamp($post['publishedAt'] ?? null),
                'raw_post' => $rawPost,
                'media_items' => $this->normalizeMediaItems($post),
                'likes' => $this->normalizePostLikes($post['likes'] ?? []),
                'comments' => $this->normalizePostComments($post['comments'] ?? []),
                'likes_complete' => (bool) ($post['likesComplete'] ?? false),
                'comments_complete' => (bool) ($post['commentsComplete'] ?? false),
            ];
        }

        return array_values($normalized);
    }

    private function withoutPostEngagementDetails(array $payload): array
    {
        $items = $payload['postsScan']['items'] ?? null;

        if (! is_array($items)) {
            return $payload;
        }

        $payload['postsScan']['items'] = array_map(function (mixed $post): mixed {
            if (! is_array($post)) {
                return $post;
            }

            unset($post['likes'], $post['comments']);

            return $post;
        }, $items);

        return $payload;
    }

    private function normalizePostLikes(mixed $likes): array
    {
        if (! is_array($likes)) {
            return [];
        }

        $normalized = [];

        foreach ($likes as $like) {
            if (! is_array($like)) {
                continue;
            }

            $instagramUserId = $this->nullableString($like['instagramUserId'] ?? null);
            $username = $this->normalizeUsername($like['username'] ?? null);
            $likerKey = $instagramUserId ? 'id:'.$instagramUserId : ($username ? 'username:'.$username : null);

            if (! $likerKey) {
                continue;
            }

            $normalized[$likerKey] = [
                'liker_key' => $likerKey,
                'instagram_user_id' => $instagramUserId,
                'username' => $username,
                'full_name' => $this->nullableString($like['fullName'] ?? null),
                'profile_image_url' => $this->nullableString($like['profileImageUrl'] ?? null),
                'is_verified' => is_bool($like['isVerified'] ?? null) ? $like['isVerified'] : null,
                'raw_like' => is_array($like['rawLike'] ?? null) ? $like['rawLike'] : $like,
            ];
        }

        return array_values($normalized);
    }

    private function normalizePostComments(mixed $comments): array
    {
        if (! is_array($comments)) {
            return [];
        }

        $normalized = [];
        $seenContentKeys = [];

        foreach ($comments as $comment) {
            if (! is_array($comment)) {
                continue;
            }

            $instagramCommentId = $this->nullableString($comment['instagramCommentId'] ?? null);
            $text = $this->normalizeCommentText($comment['text'] ?? null);

            if (! $instagramCommentId || ! $text || $this->looksLikeNonCommentText($text)) {
                continue;
            }

            $username = $this->normalizeUsername($comment['username'] ?? null);
            $publishedAt = $this->parseTimestamp($comment['publishedAt'] ?? null);
            $contentKey = implode('|', [
                $username ?: '',
                $publishedAt?->toIso8601String() ?: '',
                mb_strtolower(preg_replace('/\s+/u', ' ', $text) ?: $text),
            ]);

            if (isset($seenContentKeys[$contentKey])) {
                continue;
            }

            $seenContentKeys[$contentKey] = true;

            $normalized[$instagramCommentId] = [
                'instagram_comment_id' => $instagramCommentId,
                'parent_instagram_comment_id' => $this->nullableString($comment['parentInstagramCommentId'] ?? null),
                'instagram_user_id' => $this->nullableString($comment['instagramUserId'] ?? null),
                'username' => $username,
                'full_name' => $this->nullableString($comment['fullName'] ?? null),
                'profile_image_url' => $this->nullableString($comment['profileImageUrl'] ?? null),
                'comment_text' => $text,
                'likes_count' => $this->nullableInteger($comment['likesCount'] ?? null),
                'is_verified' => is_bool($comment['isVerified'] ?? null) ? $comment['isVerified'] : null,
                'published_at' => $publishedAt,
                'raw_comment' => is_array($comment['rawComment'] ?? null) ? $comment['rawComment'] : $comment,
            ];
        }

        return array_values($normalized);
    }

    private function ensureEngagementActorProfiles(array $posts): array
    {
        $actorsByUsername = [];

        foreach ($posts as $post) {
            foreach ([...($post['likes'] ?? []), ...($post['comments'] ?? [])] as $actor) {
                if (! is_array($actor)) {
                    continue;
                }

                $username = $this->normalizeUsername($actor['username'] ?? null);

                if (! $username) {
                    continue;
                }

                $actorsByUsername[$username] = [
                    'display_name' => $this->nullableString($actor['full_name'] ?? null),
                    'full_name' => $this->nullableString($actor['full_name'] ?? null),
                    'profile_image_url' => $this->nullableString($actor['profile_image_url'] ?? null),
                    'profile_url' => 'https://www.instagram.com/'.$username.'/',
                    'raw_profile' => array_filter([
                        'source' => 'post_engagement',
                        'instagram_user_id' => $this->nullableString($actor['instagram_user_id'] ?? null),
                        'is_verified' => is_bool($actor['is_verified'] ?? null) ? $actor['is_verified'] : null,
                        'raw_like' => is_array($actor['raw_like'] ?? null) ? $actor['raw_like'] : null,
                        'raw_comment' => is_array($actor['raw_comment'] ?? null) ? $actor['raw_comment'] : null,
                    ], static fn ($value): bool => $value !== null),
                ];
            }
        }

        if ($actorsByUsername === []) {
            return [];
        }

        $profileIds = [];

        foreach ($actorsByUsername as $username => $attributes) {
            $profile = InstagramProfile::withTrashed()
                ->where('username', $username)
                ->first();

            $payload = array_filter([
                'display_name' => $attributes['display_name'] ?? null,
                'full_name' => $attributes['full_name'] ?? null,
                'profile_url' => $attributes['profile_url'] ?? 'https://www.instagram.com/'.$username.'/',
                'profile_image_url' => $attributes['profile_image_url'] ?? null,
                'raw_profile' => $attributes['raw_profile'] ?? null,
            ], static fn ($value): bool => $value !== null && $value !== []);

            if ($profile) {
                if ($profile->trashed()) {
                    $profile->restore();
                }

                if ($payload !== []) {
                    $profile->forceFill($payload)->save();
                }
            } else {
                $profile = InstagramProfile::create([
                    'username' => $username,
                    'profile_url' => 'https://www.instagram.com/'.$username.'/',
                    ...$payload,
                ]);
            }

            if ($profile) {
                $profileIds[$username] = $profile->id;
            }
        }

        if ($profileIds !== []) {
            return $profileIds;
        }

        return InstagramProfile::query()
            ->whereIn('username', array_keys($actorsByUsername))
            ->pluck('id', 'username')
            ->all();
    }

    private function withOptionalPostScanUsername(array $payload): array
    {
        if (! Schema::hasColumn('instagram_post_scans', 'instagram_username')) {
            unset($payload['instagram_username']);
        }

        return $payload;
    }

    private function storePostLikes(
        InstagramPost $post,
        InstagramPostScan $scan,
        array $likes,
        bool $complete,
        array $actorProfileIds,
        Carbon $scannedAt,
    ): void {
        $observedKeys = [];

        foreach ($likes as $like) {
            $observedKeys[] = $like['liker_key'];
            $existing = InstagramPostLike::query()
                ->where('instagram_post_id', $post->id)
                ->where('liker_key', $like['liker_key'])
                ->first();

            InstagramPostLike::updateOrCreate(
                [
                    'instagram_post_id' => $post->id,
                    'liker_key' => $like['liker_key'],
                ],
                [
                    ...$like,
                    'instagram_profile_id' => $actorProfileIds[$like['username'] ?? ''] ?? null,
                    'first_seen_scan_id' => $existing?->first_seen_scan_id ?: $scan->id,
                    'last_seen_scan_id' => $scan->id,
                    'is_active' => true,
                    'first_seen_at' => $existing?->first_seen_at ?: $scannedAt,
                    'last_seen_at' => $scannedAt,
                    'removed_at' => null,
                ],
            );
        }

        if ($complete) {
            InstagramPostLike::query()
                ->where('instagram_post_id', $post->id)
                ->where('is_active', true)
                ->when($observedKeys !== [], fn ($query) => $query->whereNotIn('liker_key', $observedKeys))
                ->update([
                    'is_active' => false,
                    'removed_at' => $scannedAt,
                ]);
        }
    }

    private function storePostComments(
        InstagramPost $post,
        InstagramPostScan $scan,
        array $comments,
        bool $complete,
        array $actorProfileIds,
        Carbon $scannedAt,
    ): void {
        $observedIds = [];

        foreach ($comments as $comment) {
            $observedIds[] = $comment['instagram_comment_id'];
            $existing = InstagramPostComment::query()
                ->where('instagram_post_id', $post->id)
                ->where('instagram_comment_id', $comment['instagram_comment_id'])
                ->first();
            $commentData = $comment;

            if (($commentData['likes_count'] ?? null) === null && $existing?->likes_count !== null) {
                unset($commentData['likes_count']);
            }

            InstagramPostComment::updateOrCreate(
                [
                    'instagram_post_id' => $post->id,
                    'instagram_comment_id' => $comment['instagram_comment_id'],
                ],
                [
                    ...$commentData,
                    'parent_comment_id' => null,
                    'instagram_profile_id' => $actorProfileIds[$comment['username'] ?? ''] ?? null,
                    'first_seen_scan_id' => $existing?->first_seen_scan_id ?: $scan->id,
                    'last_seen_scan_id' => $scan->id,
                    'is_active' => true,
                    'first_seen_at' => $existing?->first_seen_at ?: $scannedAt,
                    'last_seen_at' => $scannedAt,
                    'removed_at' => null,
                ],
            );
        }

        $this->linkPostCommentReplies($post, $comments);

        if ($complete) {
            InstagramPostComment::query()
                ->where('instagram_post_id', $post->id)
                ->where('is_active', true)
                ->when($observedIds !== [], fn ($query) => $query->whereNotIn('instagram_comment_id', $observedIds))
                ->update([
                    'is_active' => false,
                    'removed_at' => $scannedAt,
                ]);
        }
    }

    private function linkPostCommentReplies(InstagramPost $post, array $comments): void
    {
        $parentInstagramIds = collect($comments)
            ->pluck('parent_instagram_comment_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($parentInstagramIds === []) {
            return;
        }

        $parentIds = InstagramPostComment::query()
            ->where('instagram_post_id', $post->id)
            ->whereIn('instagram_comment_id', $parentInstagramIds)
            ->pluck('id', 'instagram_comment_id');

        foreach ($comments as $comment) {
            $parentInstagramId = $comment['parent_instagram_comment_id'] ?? null;
            $parentId = $parentInstagramId ? $parentIds->get($parentInstagramId) : null;

            if (! $parentId) {
                continue;
            }

            InstagramPostComment::query()
                ->where('instagram_post_id', $post->id)
                ->where('instagram_comment_id', $comment['instagram_comment_id'])
                ->update(['parent_comment_id' => $parentId]);
        }
    }

    private function normalizeMediaItems(array $post): array
    {
        $mediaItems = is_array($post['media'] ?? null) ? $post['media'] : [];

        if ($mediaItems === [] && filled($post['thumbnailUrl'] ?? null)) {
            $mediaItems = [[
                'position' => 0,
                'mediaType' => 'image',
                'sourceUrl' => $post['thumbnailUrl'],
                'previewUrl' => $post['thumbnailUrl'],
            ]];
        }

        return collect($mediaItems)
            ->filter(fn (mixed $media): bool => is_array($media))
            ->map(function (array $media, int $index): array {
                return [
                    'position' => max(0, (int) ($media['position'] ?? $index)),
                    'media_type' => ($media['mediaType'] ?? null) === 'video' ? 'video' : 'image',
                    'source_url' => $this->nullableString($media['sourceUrl'] ?? null),
                    'preview_url' => $this->nullableString($media['previewUrl'] ?? null),
                    'width' => $this->nullableInteger($media['width'] ?? null),
                    'height' => $this->nullableInteger($media['height'] ?? null),
                    'duration_seconds' => is_numeric($media['durationSeconds'] ?? null)
                        ? max(0, (float) $media['durationSeconds'])
                        : null,
                ];
            })
            ->filter(fn (array $media): bool => filled($media['source_url']) || filled($media['preview_url']))
            ->unique('position')
            ->sortBy('position')
            ->values()
            ->all();
    }

    private function withoutEmptyReplacementValues(array $postData): array
    {
        foreach ([
            'media_pk',
            'thumbnail_url',
            'caption',
            'likes_count',
            'comments_count',
            'published_at',
        ] as $field) {
            if (($postData[$field] ?? null) === null) {
                unset($postData[$field]);
            }
        }

        if (($postData['media_count'] ?? 0) === 0) {
            unset($postData['media_count']);
        }

        return $postData;
    }

    private function valuesDiffer(mixed $before, mixed $after): bool
    {
        if ($before instanceof \DateTimeInterface && $after instanceof \DateTimeInterface) {
            return $before->getTimestamp() !== $after->getTimestamp();
        }

        return $before !== $after;
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->timezone(config('app.timezone'));
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

    private function normalizeUsername(mixed $value): ?string
    {
        $username = $this->nullableString($value);

        return $username ? strtolower(ltrim($username, '@')) : null;
    }

    private function normalizeCommentText(mixed $value): ?string
    {
        $text = $this->nullableString($value);

        if (! $text) {
            return null;
        }

        $text = preg_replace('/\s+/u', ' ', $text) ?: $text;
        $length = mb_strlen($text);

        if ($length > 0 && $length % 2 === 0) {
            $midpoint = (int) ($length / 2);
            $left = trim(mb_substr($text, 0, $midpoint));
            $right = trim(mb_substr($text, $midpoint));

            if ($left !== '' && mb_strtolower($left) === mb_strtolower($right)) {
                $text = $left;
            }
        }

        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) >= 2 && count($words) % 2 === 0) {
            $midpoint = (int) (count($words) / 2);
            $left = implode(' ', array_slice($words, 0, $midpoint));
            $right = implode(' ', array_slice($words, $midpoint));

            if (mb_strtolower($left) === mb_strtolower($right)) {
                $text = $left;
            }
        }

        return $this->nullableString($text);
    }

    private function looksLikeNonCommentText(string $text): bool
    {
        $normalized = mb_strtolower(trim($text));

        return (bool) preg_match('/^(antworten|reply|kommentar|comment|translation|uebersetzung|übersetzung|mehr anzeigen|show more|view replies|weitere antworten)(\b|$)/iu', $normalized)
            || (bool) preg_match('/^(gef[aä]llt|likes?)\b/iu', $normalized)
            || (bool) preg_match('/^(?:\d+\s*)?(?:antworten|replies|comments?|kommentare)$/iu', $normalized);
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
