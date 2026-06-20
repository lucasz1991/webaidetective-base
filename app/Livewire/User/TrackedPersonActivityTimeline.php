<?php

namespace App\Livewire\User;

use App\Models\InstagramPost;
use App\Models\InstagramPostComment;
use App\Models\InstagramPostLike;
use App\Models\InstagramProfileListScanItem;
use App\Models\TrackedPerson;
use App\Models\TrackedPersonInstagramSnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;

class TrackedPersonActivityTimeline extends Component
{
    private const PER_PAGE = 40;

    private const FILTERS = [
        'all',
        'profile',
        'followers',
        'following',
        'posts',
        'likes',
        'comments',
    ];

    public int $trackedPersonId;

    public string $typeFilter = 'all';

    public int $page = 1;

    public function mount(int $trackedPersonId): void
    {
        $this->trackedPersonId = $trackedPersonId;
    }

    public function placeholder(): string
    {
        return <<<'HTML'
        <section class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="animate-pulse space-y-3">
                <div class="h-4 w-48 rounded bg-slate-200"></div>
                <div class="h-24 rounded-2xl bg-slate-100"></div>
                <div class="h-24 rounded-2xl bg-slate-100"></div>
            </div>
        </section>
        HTML;
    }

    public function setTypeFilter(string $filter): void
    {
        if (! in_array($filter, self::FILTERS, true)) {
            return;
        }

        $this->typeFilter = $filter;
        $this->page = 1;
    }

    public function loadMore(): void
    {
        $this->page++;
    }

    public function render()
    {
        $trackedPerson = $this->resolvedTrackedPerson();
        $activities = $trackedPerson ? $this->activities($trackedPerson) : collect();
        $filterOptions = $this->filterOptions($activities);
        $filteredActivities = $this->filteredActivities($activities);
        $visibleActivities = $filteredActivities->take($this->page * self::PER_PAGE)->values();

        return view('livewire.user.tracked-person-activity-timeline', [
            'trackedPerson' => $trackedPerson,
            'activities' => $visibleActivities,
            'filterOptions' => $filterOptions,
            'totalActivityCount' => $activities->count(),
            'hasMoreActivities' => $filteredActivities->count() > $visibleActivities->count(),
        ]);
    }

    private function resolvedTrackedPerson(): ?TrackedPerson
    {
        $user = Auth::user();

        if (! $user) {
            return null;
        }

        return $user->trackedPeople()
            ->whereKey($this->trackedPersonId)
            ->with('currentInstagramProfile')
            ->first();
    }

    private function activities(TrackedPerson $trackedPerson): Collection
    {
        return collect()
            ->merge($this->snapshotChangeActivities($trackedPerson))
            ->merge($this->profileImageActivities($trackedPerson))
            ->merge($this->relationshipActivities($trackedPerson))
            ->merge($this->postActivities($trackedPerson))
            ->merge($this->likeActivities($trackedPerson))
            ->merge($this->commentActivities($trackedPerson))
            ->filter(fn (array $activity): bool => $activity['date'] instanceof Carbon)
            ->unique('id')
            ->sortByDesc(fn (array $activity): int => $activity['date']->getTimestamp())
            ->values();
    }

    private function snapshotChangeActivities(TrackedPerson $trackedPerson): Collection
    {
        return $trackedPerson->instagramSnapshots()
            ->where('has_changes', true)
            ->latest('analyzed_at')
            ->limit($this->sourceLimit())
            ->get()
            ->flatMap(function (TrackedPersonInstagramSnapshot $snapshot): Collection {
                $changes = collect($snapshot->detected_changes ?? []);

                if ($changes->isEmpty()) {
                    return collect([
                        $this->activityRow(
                            'snapshot-'.$snapshot->id,
                            'profile',
                            'Profil aktualisiert',
                            $snapshot->status_message ?: 'Instagram-Profil wurde neu analysiert.',
                            $snapshot->analyzed_at,
                            [
                                'meta' => $snapshot->instagram_username ? '@'.$snapshot->instagram_username : null,
                                'tone' => 'slate',
                                'image_url' => $snapshot->profile_image_storage_url,
                            ],
                        ),
                    ]);
                }

                return $changes
                    ->map(function (array $change, int $index) use ($snapshot): array {
                        $field = (string) ($change['field'] ?? 'profile');
                        $type = $this->typeForSnapshotField($field);
                        $before = $this->formatSnapshotValue($change, $change['before'] ?? null);
                        $after = $this->formatSnapshotValue($change, $change['after'] ?? null);
                        $label = (string) ($change['label'] ?? $this->snapshotFieldLabel($field));
                        $isProfileImage = in_array($field, ['profile_image_hash', 'profile_image_path', 'profile_image_url'], true);

                        return $this->activityRow(
                            'snapshot-'.$snapshot->id.'-'.$field.'-'.$index,
                            $type,
                            $isProfileImage ? 'Profilbild geaendert' : $label.' geaendert',
                            $isProfileImage ? 'Es wurde ein neues Profilbild erkannt.' : $before.' -> '.$after,
                            $snapshot->analyzed_at,
                            [
                                'meta' => $snapshot->instagram_username ? '@'.$snapshot->instagram_username : null,
                                'tone' => $this->toneForType($type),
                                'image_url' => $isProfileImage ? $snapshot->profile_image_storage_url : null,
                            ],
                        );
                    });
            });
    }

    private function profileImageActivities(TrackedPerson $trackedPerson): Collection
    {
        return $trackedPerson->instagramSnapshots()
            ->where(function (Builder $query): void {
                $query->whereNotNull('profile_image_hash')
                    ->orWhereNotNull('profile_image_path')
                    ->orWhereNotNull('profile_image_url');
            })
            ->latest('analyzed_at')
            ->limit($this->sourceLimit())
            ->get()
            ->unique(fn (TrackedPersonInstagramSnapshot $snapshot): string => (string) (
                $snapshot->profile_image_hash
                ?: $snapshot->profile_image_path
                ?: $snapshot->profile_image_url
                ?: $snapshot->id
            ))
            ->map(fn (TrackedPersonInstagramSnapshot $snapshot): array => $this->activityRow(
                'profile-image-'.$snapshot->id,
                'profile',
                'Profilbild gespeichert',
                'Dieses Profilbild wurde in einem Scan gesichert.',
                $snapshot->analyzed_at,
                [
                    'meta' => $snapshot->instagram_username ? '@'.$snapshot->instagram_username : null,
                    'tone' => 'violet',
                    'image_url' => $snapshot->profile_image_storage_url,
                ],
            ))
            ->values();
    }

    private function relationshipActivities(TrackedPerson $trackedPerson): Collection
    {
        $profileId = $trackedPerson->current_instagram_profile_id;
        $userId = (int) Auth::id();

        return InstagramProfileListScanItem::query()
            ->whereIn('item_status', ['added', 'removed'])
            ->whereHas('listScan', function (Builder $query) use ($trackedPerson, $profileId, $userId): void {
                $query->where('user_id', $userId)
                    ->where(function (Builder $identityQuery) use ($trackedPerson, $profileId): void {
                        $identityQuery->where('tracked_person_id', $trackedPerson->id);

                        if ($profileId) {
                            $identityQuery->orWhere('instagram_profile_id', $profileId);
                        }
                    });
            })
            ->with(['listScan', 'relatedInstagramProfile'])
            ->latest('observed_at')
            ->limit($this->sourceLimit())
            ->get()
            ->map(function (InstagramProfileListScanItem $item): array {
                $listType = $item->list_type === 'following' ? 'following' : 'followers';
                $status = $item->item_status === 'removed' ? 'removed' : 'added';
                $displayName = $this->profileDisplayName(
                    $item->username_snapshot,
                    $item->display_name_snapshot,
                    $item->relatedInstagramProfile?->username,
                    $item->relatedInstagramProfile?->full_name,
                );

                return $this->activityRow(
                    'relationship-'.$item->id,
                    $listType,
                    $this->relationshipTitle($listType, $status),
                    $displayName,
                    $item->observed_at ?: $item->listScan?->scanned_at,
                    [
                        'meta' => $item->listScan?->scanned_at
                            ? 'Scan vom '.$item->listScan->scanned_at->timezone(config('app.timezone'))->format('d.m.Y H:i')
                            : null,
                        'tone' => $status === 'removed' ? 'rose' : 'emerald',
                        'url' => $item->profile_url_snapshot ?: $item->relatedInstagramProfile?->profile_url,
                        'image_url' => $item->relatedInstagramProfile?->profile_image_storage_url,
                    ],
                );
            });
    }

    private function postActivities(TrackedPerson $trackedPerson): Collection
    {
        $profileId = $trackedPerson->current_instagram_profile_id;

        if (! $profileId) {
            return collect();
        }

        return InstagramPost::query()
            ->where('instagram_profile_id', $profileId)
            ->latest('first_seen_at')
            ->latest('published_at')
            ->limit($this->sourceLimit())
            ->get()
            ->map(fn (InstagramPost $post): array => $this->activityRow(
                'post-'.$post->id,
                'posts',
                'Beitrag entdeckt',
                $post->caption ? Str::limit($post->caption, 140) : 'Ein Instagram-Beitrag wurde gespeichert.',
                $post->first_seen_at ?: $post->published_at ?: $post->last_seen_at,
                [
                    'meta' => trim(collect([
                        $post->published_at ? 'veroeffentlicht '.$post->published_at->timezone(config('app.timezone'))->format('d.m.Y') : null,
                        $post->shortcode ? '#'.$post->shortcode : null,
                    ])->filter()->join(' - ')),
                    'tone' => 'sky',
                    'url' => $post->post_url,
                    'image_url' => $post->thumbnail_storage_url,
                ],
            ));
    }

    private function likeActivities(TrackedPerson $trackedPerson): Collection
    {
        $profileId = $trackedPerson->current_instagram_profile_id;

        if (! $profileId) {
            return collect();
        }

        $likes = InstagramPostLike::query()
            ->where(function (Builder $query) use ($profileId): void {
                $query
                    ->where('instagram_profile_id', $profileId)
                    ->orWhereHas('post', fn (Builder $postQuery) => $postQuery->where('instagram_profile_id', $profileId));
            })
            ->with('post.instagramProfile')
            ->where(function (Builder $query): void {
                $query->whereNotNull('first_seen_at')
                    ->orWhereNotNull('removed_at');
            })
            ->latest('updated_at')
            ->limit($this->sourceLimit())
            ->get();

        return $likes->flatMap(function (InstagramPostLike $like) use ($trackedPerson, $profileId): Collection {
            $rows = collect();
            $isActorActivity = $this->isEngagementOnOtherProfilePost($like->instagram_profile_id, $like->post, $profileId);
            $displayName = $isActorActivity
                ? $this->trackedPersonInstagramLabel($trackedPerson)
                : $this->profileDisplayName($like->username, $like->full_name);

            if ($like->first_seen_at) {
                $rows->push($this->activityRow(
                    'like-'.$like->id.'-seen',
                    'likes',
                    $isActorActivity ? 'Like bei anderem Profil erfasst' : 'Like auf eigenem Post erfasst',
                    $displayName,
                    $like->first_seen_at,
                    [
                        'meta' => $this->postMeta($like->post, $isActorActivity),
                        'tone' => 'pink',
                        'url' => $like->post?->post_url,
                        'image_url' => $like->profile_image_url ?: $like->post?->thumbnail_storage_url,
                    ],
                ));
            }

            if ($like->removed_at) {
                $rows->push($this->activityRow(
                    'like-'.$like->id.'-removed',
                    'likes',
                    $isActorActivity ? 'Like bei anderem Profil entfernt' : 'Like auf eigenem Post entfernt',
                    $displayName,
                    $like->removed_at,
                    [
                        'meta' => $this->postMeta($like->post, $isActorActivity),
                        'tone' => 'rose',
                        'url' => $like->post?->post_url,
                        'image_url' => $like->profile_image_url ?: $like->post?->thumbnail_storage_url,
                    ],
                ));
            }

            return $rows;
        });
    }

    private function commentActivities(TrackedPerson $trackedPerson): Collection
    {
        $profileId = $trackedPerson->current_instagram_profile_id;

        if (! $profileId) {
            return collect();
        }

        $comments = InstagramPostComment::query()
            ->where(function (Builder $query) use ($profileId): void {
                $query
                    ->where('instagram_profile_id', $profileId)
                    ->orWhereHas('post', fn (Builder $postQuery) => $postQuery->where('instagram_profile_id', $profileId));
            })
            ->with('post.instagramProfile')
            ->where(function (Builder $query): void {
                $query->whereNotNull('first_seen_at')
                    ->orWhereNotNull('published_at')
                    ->orWhereNotNull('removed_at');
            })
            ->latest('updated_at')
            ->limit($this->sourceLimit())
            ->get();

        return $comments->flatMap(function (InstagramPostComment $comment) use ($trackedPerson, $profileId): Collection {
            $rows = collect();
            $isActorActivity = $this->isEngagementOnOtherProfilePost($comment->instagram_profile_id, $comment->post, $profileId);
            $displayName = $isActorActivity
                ? $this->trackedPersonInstagramLabel($trackedPerson)
                : $this->profileDisplayName($comment->username, $comment->full_name);
            $text = $comment->comment_text ? Str::limit($comment->comment_text, 160) : 'Kommentar ohne gespeicherten Text.';

            if ($comment->first_seen_at || $comment->published_at) {
                $rows->push($this->activityRow(
                    'comment-'.$comment->id.'-seen',
                    'comments',
                    $isActorActivity ? 'Kommentar bei anderem Profil erfasst' : 'Kommentar auf eigenem Post erfasst',
                    $displayName.': '.$text,
                    $comment->first_seen_at ?: $comment->published_at,
                    [
                        'meta' => $this->postMeta($comment->post, $isActorActivity),
                        'tone' => 'amber',
                        'url' => $comment->post?->post_url,
                        'image_url' => $comment->profile_image_url ?: $comment->post?->thumbnail_storage_url,
                    ],
                ));
            }

            if ($comment->removed_at) {
                $rows->push($this->activityRow(
                    'comment-'.$comment->id.'-removed',
                    'comments',
                    $isActorActivity ? 'Kommentar bei anderem Profil entfernt' : 'Kommentar auf eigenem Post entfernt',
                    $displayName.': '.$text,
                    $comment->removed_at,
                    [
                        'meta' => $this->postMeta($comment->post, $isActorActivity),
                        'tone' => 'rose',
                        'url' => $comment->post?->post_url,
                        'image_url' => $comment->profile_image_url ?: $comment->post?->thumbnail_storage_url,
                    ],
                ));
            }

            return $rows;
        });
    }

    private function filteredActivities(Collection $activities): Collection
    {
        if ($this->typeFilter === 'all') {
            return $activities;
        }

        return $activities
            ->where('type', $this->typeFilter)
            ->values();
    }

    private function sourceLimit(): int
    {
        return max(240, $this->page * self::PER_PAGE * 4);
    }

    private function filterOptions(Collection $activities): array
    {
        $labels = [
            'all' => 'Alle',
            'profile' => 'Profil',
            'followers' => 'Follower',
            'following' => 'Gefolgt',
            'posts' => 'Posts',
            'likes' => 'Likes',
            'comments' => 'Kommentare',
        ];

        return collect(self::FILTERS)
            ->map(fn (string $filter): array => [
                'key' => $filter,
                'label' => $labels[$filter],
                'count' => $filter === 'all'
                    ? $activities->count()
                    : $activities->where('type', $filter)->count(),
            ])
            ->all();
    }

    private function activityRow(
        string $id,
        string $type,
        string $title,
        ?string $summary,
        mixed $date,
        array $extra = [],
    ): array {
        return [
            'id' => $id,
            'type' => $type,
            'type_label' => $this->typeLabel($type),
            'title' => $title,
            'summary' => filled($summary) ? (string) $summary : null,
            'date' => $this->activityDate($date),
            'meta' => filled($extra['meta'] ?? null) ? (string) $extra['meta'] : null,
            'tone' => (string) ($extra['tone'] ?? $this->toneForType($type)),
            'url' => filled($extra['url'] ?? null) ? (string) $extra['url'] : null,
            'image_url' => filled($extra['image_url'] ?? null) ? (string) $extra['image_url'] : null,
        ];
    }

    private function activityDate(mixed $date): ?Carbon
    {
        if ($date instanceof Carbon) {
            return $date->copy()->timezone(config('app.timezone'));
        }

        if (! filled($date)) {
            return null;
        }

        return Carbon::parse($date)->timezone(config('app.timezone'));
    }

    private function typeForSnapshotField(string $field): string
    {
        return match ($field) {
            'followers_count' => 'followers',
            'following_count' => 'following',
            'posts_count' => 'posts',
            default => 'profile',
        };
    }

    private function snapshotFieldLabel(string $field): string
    {
        return match ($field) {
            'instagram_username' => 'Benutzername',
            'full_name' => 'Profilname',
            'biography' => 'Biografie',
            'posts_count' => 'Post-Anzahl',
            'followers_count' => 'Followerzahl',
            'following_count' => 'Gefolgt-Zahl',
            'profile_image_hash', 'profile_image_path', 'profile_image_url' => 'Profilbild',
            'profile_visibility' => 'Profilstatus',
            default => Str::headline(str_replace('_', ' ', $field)),
        };
    }

    private function formatSnapshotValue(array $change, mixed $value): string
    {
        if (($change['field'] ?? null) === 'profile_visibility') {
            return match ($value) {
                'public' => 'Oeffentlich',
                'private' => 'Privat',
                'unknown' => 'Unbekannt',
                default => filled($value) ? (string) $value : '-',
            };
        }

        if (is_array($value)) {
            return json_encode($value) ?: '-';
        }

        return filled($value) ? (string) $value : '-';
    }

    private function profileDisplayName(
        ?string $username = null,
        ?string $fullName = null,
        ?string $fallbackUsername = null,
        ?string $fallbackName = null,
    ): string {
        $handle = filled($username)
            ? '@'.ltrim((string) $username, '@')
            : (filled($fallbackUsername) ? '@'.ltrim((string) $fallbackUsername, '@') : null);
        $name = filled($fullName) ? (string) $fullName : (filled($fallbackName) ? (string) $fallbackName : null);

        return collect([$handle, $name])
            ->filter()
            ->join(' - ') ?: 'Unbekanntes Instagram-Profil';
    }

    private function trackedPersonInstagramLabel(TrackedPerson $trackedPerson): string
    {
        return $this->profileDisplayName(
            $trackedPerson->currentInstagramProfile?->username ?: $trackedPerson->instagram_username,
            $trackedPerson->currentInstagramProfile?->display_name
                ?: $trackedPerson->currentInstagramProfile?->full_name
                ?: $trackedPerson->display_name,
        );
    }

    private function isEngagementOnOtherProfilePost(?int $actorProfileId, ?InstagramPost $post, int $targetProfileId): bool
    {
        if ((int) $actorProfileId !== $targetProfileId) {
            return false;
        }

        return ! $post || (int) $post->instagram_profile_id !== $targetProfileId;
    }

    private function relationshipTitle(string $listType, string $status): string
    {
        return match ([$listType, $status]) {
            ['followers', 'removed'] => 'Follower entfernt',
            ['following', 'added'] => 'Gefolgt hinzugefuegt',
            ['following', 'removed'] => 'Gefolgt entfernt',
            default => 'Follower hinzugefuegt',
        };
    }

    private function postMeta(?InstagramPost $post, bool $includeOwner = false): ?string
    {
        if (! $post) {
            return null;
        }

        return collect([
            $includeOwner && $post->instagramProfile
                ? 'bei '.$post->instagramProfile->display_handle
                : null,
            $post->shortcode ? 'Post #'.$post->shortcode : 'Post',
            $post->published_at ? $post->published_at->timezone(config('app.timezone'))->format('d.m.Y') : null,
        ])->filter()->join(' - ');
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'profile' => 'Profil',
            'followers' => 'Follower',
            'following' => 'Gefolgt',
            'posts' => 'Post',
            'likes' => 'Like',
            'comments' => 'Kommentar',
            default => 'Aktivitaet',
        };
    }

    private function toneForType(string $type): string
    {
        return match ($type) {
            'followers' => 'emerald',
            'following' => 'indigo',
            'posts' => 'sky',
            'likes' => 'pink',
            'comments' => 'amber',
            'profile' => 'violet',
            default => 'slate',
        };
    }
}
