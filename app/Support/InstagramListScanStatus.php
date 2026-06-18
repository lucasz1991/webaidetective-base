<?php

namespace App\Support;

use App\Models\InstagramProfileListScan;
use Illuminate\Support\Carbon;

class InstagramListScanStatus
{
    private const LIST_TYPES = ['followers', 'following'];

    private const LIST_LABELS = [
        'followers' => 'Followerliste',
        'following' => 'Gefolgt-Liste',
    ];

    private static array $profileCache = [];

    public static function forProfile(int $profileId, int $userId): array
    {
        if ($profileId <= 0) {
            return self::defaultStatuses();
        }

        return self::forProfileIds([$profileId], $userId)[$profileId] ?? self::defaultStatuses();
    }

    public static function forProfileIds(iterable $profileIds, int $userId): array
    {
        $ids = collect($profileIds)
            ->map(fn ($profileId): int => (int) $profileId)
            ->filter(fn (int $profileId): bool => $profileId > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $missingIds = [];

        foreach ($ids as $profileId) {
            $cacheKey = self::cacheKey($userId, $profileId);

            if (! array_key_exists($cacheKey, self::$profileCache)) {
                self::$profileCache[$cacheKey] = self::defaultStatuses();
                $missingIds[] = $profileId;
            }
        }

        if ($missingIds !== []) {
            $query = InstagramProfileListScan::query()
                ->whereIn('instagram_profile_id', $missingIds)
                ->whereIn('list_type', self::LIST_TYPES)
                ->orderByDesc('scanned_at')
                ->orderByDesc('id');

            if ($userId > 0) {
                $query->where('user_id', $userId);
            }

            $seen = [];

            $query->get([
                'id',
                'instagram_profile_id',
                'list_type',
                'status_level',
                'status_message',
                'attempted',
                'available',
                'complete',
                'rate_limited',
                'gracefully_stopped',
                'observed_count',
                'active_count',
                'scanned_at',
            ])->each(function (InstagramProfileListScan $scan) use (&$seen, $userId): void {
                $profileId = (int) $scan->instagram_profile_id;
                $listType = $scan->list_type === 'following' ? 'following' : 'followers';
                $cacheKey = self::cacheKey($userId, $profileId);

                if (isset($seen[$cacheKey][$listType])) {
                    return;
                }

                self::$profileCache[$cacheKey][$listType] = self::fromScan($scan, $listType);
                $seen[$cacheKey][$listType] = true;
            });
        }

        return $ids
            ->mapWithKeys(function (int $profileId) use ($userId): array {
                return [$profileId => self::$profileCache[self::cacheKey($userId, $profileId)] ?? self::defaultStatuses()];
            })
            ->all();
    }

    public static function defaultStatuses(): array
    {
        return [
            'followers' => self::defaultStatus('followers'),
            'following' => self::defaultStatus('following'),
        ];
    }

    public static function normalizeState(mixed $state): string
    {
        $state = strtolower(trim((string) $state));

        return match ($state) {
            'complete', 'completed', 'success', 'green', 'scanned' => 'complete',
            'partial', 'warning', 'amber', 'yellow', 'rate_limited', 'cancelled', 'error', 'failed', 'attempted' => 'partial',
            default => 'none',
        };
    }

    private static function fromScan(InstagramProfileListScan $scan, string $listType): array
    {
        $level = strtolower((string) $scan->status_level);
        $state = ($scan->complete || $level === 'success')
            ? 'complete'
            : 'partial';
        $label = self::LIST_LABELS[$listType] ?? 'Liste';
        $date = self::formatDate($scan->scanned_at);

        $title = match ($state) {
            'complete' => $label.' vollstaendig gescannt',
            default => $label.' teilweise gescannt',
        };

        if ($date !== null) {
            $title .= ' am '.$date;
        }

        return [
            'state' => $state,
            'status_level' => $scan->status_level,
            'complete' => (bool) $scan->complete,
            'rate_limited' => (bool) $scan->rate_limited,
            'gracefully_stopped' => (bool) $scan->gracefully_stopped,
            'observed_count' => (int) $scan->observed_count,
            'active_count' => (int) $scan->active_count,
            'scanned_at' => $scan->scanned_at?->toIso8601String(),
            'title' => $title,
        ];
    }

    private static function defaultStatus(string $listType): array
    {
        $label = self::LIST_LABELS[$listType] ?? 'Liste';

        return [
            'state' => 'none',
            'status_level' => null,
            'complete' => false,
            'rate_limited' => false,
            'gracefully_stopped' => false,
            'observed_count' => 0,
            'active_count' => 0,
            'scanned_at' => null,
            'title' => $label.' noch nicht gescannt',
        ];
    }

    private static function cacheKey(int $userId, int $profileId): string
    {
        return $userId.':'.$profileId;
    }

    private static function formatDate(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value)
                ->timezone(config('app.timezone'))
                ->format('d.m.Y H:i');
        } catch (\Throwable) {
            return null;
        }
    }
}
