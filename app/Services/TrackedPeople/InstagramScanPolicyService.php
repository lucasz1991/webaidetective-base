<?php

namespace App\Services\TrackedPeople;

use App\Models\Setting;
use Illuminate\Support\Str;

class InstagramScanPolicyService
{
    private ?array $resolvedPolicies = null;

    public function all(): array
    {
        if ($this->resolvedPolicies !== null) {
            return $this->resolvedPolicies;
        }

        $stored = Setting::getValue('scan', 'policies');

        return $this->resolvedPolicies = self::mergeWithDefaults(
            config('scan-policies.defaults', []),
            is_array($stored) ? $stored : [],
        );
    }

    public function for(string $scanType): array
    {
        return $this->all()[$scanType] ?? [];
    }

    public function scanTypeForOperation(string $operationMode): string
    {
        $operationMode = Str::lower(trim($operationMode));

        return match ($operationMode) {
            'mini', 'mini-scan', 'public', 'public-profile' => 'mini',
            'posts', 'post-scan' => 'posts',
            'suggestions', 'profile-suggestions' => 'suggestions',
            'suggestion-connections' => 'suggestion_deep_search',
            'followers', 'following',
            'followers-search', 'search-followers',
            'following-search', 'search-following',
            'public-connections-batch' => 'lists',
            'public-profile-connections' => 'public_connections',
            default => 'profile',
        };
    }

    public function errorAttempts(string $operationMode): int
    {
        return max(1, min(10, (int) ($this->forOperation($operationMode)['error_attempts'] ?? 1)));
    }

    public function retryDelayMilliseconds(string $operationMode): int
    {
        $seconds = max(0, min(300, (int) ($this->forOperation($operationMode)['retry_delay_seconds'] ?? 0)));

        return $seconds * 1000;
    }

    public function runtimeOverrides(string $operationMode): array
    {
        $policies = $this->all();
        $global = $policies['global'] ?? [];
        $scanType = $this->scanTypeForOperation($operationMode);
        $policy = $policies[$scanType] ?? [];
        $runtime = [
            'scriptStallTimeoutMs' => max(
                60,
                (int) ($global['node_watchdog_timeout_seconds'] ?? 900),
            ) * 1000,
        ];

        if ($scanType === 'lists') {
            $runtime += [
                'followerListMaxItems' => max(0, (int) ($policy['max_items'] ?? 0)),
                'followingListMaxItems' => max(0, (int) ($policy['max_items'] ?? 0)),
                'relationshipListMaxScrollRounds' => max(20, (int) ($policy['max_scroll_rounds'] ?? 100000)),
            ];
        }

        if ($scanType === 'posts') {
            $runtime += [
                'postScanMaxItems' => max(1, (int) ($policy['max_items'] ?? 100)),
                'postScanMaxScrollRounds' => max(1, (int) ($policy['max_scroll_rounds'] ?? 40)),
                'postScanMaxLikesPerPost' => max(1, (int) ($policy['max_likes_per_post'] ?? 250)),
                'postScanMaxCommentsPerPost' => max(1, (int) ($policy['max_comments_per_post'] ?? 250)),
            ];
        }

        if (in_array($scanType, ['suggestions', 'suggestion_deep_search'], true)) {
            $suggestions = $policies['suggestions'] ?? [];
            $runtime += [
                'suggestionScanMaxItems' => max(1, (int) ($suggestions['max_items'] ?? 500)),
                'suggestionInlineMaxRounds' => max(1, (int) ($suggestions['inline_max_rounds'] ?? 60)),
                'suggestionDialogMaxRounds' => max(1, (int) ($suggestions['dialog_max_rounds'] ?? 100)),
            ];
        }

        if ($scanType === 'suggestion_deep_search') {
            $runtime += [
                'suggestionCandidateMaxItems' => max(1, (int) ($policy['candidate_max_items'] ?? 300)),
                'suggestionCandidateMaxAttempts' => max(1, min(10, (int) ($policy['candidate_error_attempts'] ?? 1))),
                'suggestionCandidateRetryDelayMs' => max(0, (int) ($policy['candidate_retry_delay_seconds'] ?? 3)) * 1000,
                'suggestionCandidateInlineMaxRounds' => max(1, (int) ($policy['candidate_inline_max_rounds'] ?? 40)),
                'suggestionCandidateDialogMaxRounds' => max(1, (int) ($policy['candidate_dialog_max_rounds'] ?? 70)),
                'suggestionPublicListSearchMaxScrollRounds' => max(1, (int) ($policy['public_list_max_scroll_rounds'] ?? 60)),
                'suggestionSkipPreviouslyChecked' => (bool) ($policy['skip_previously_checked'] ?? true),
                'suggestionNoMatchSkipAfter' => max(1, min(100, (int) ($policy['no_match_skip_after'] ?? 2))),
                'suggestionMaxScraperProfileSwitches' => max(0, min(10, (int) ($policy['max_scraper_profile_switches'] ?? 3))),
            ];
        }

        if ($scanType === 'public_connections') {
            $runtime += [
                'publicConnectionCandidateMaxAttempts' => max(1, min(10, (int) ($policy['candidate_max_attempts'] ?? 3))),
                'publicConnectionRetryDelayMs' => max(2, (int) ($policy['candidate_retry_delay_seconds'] ?? 6)) * 1000,
                'publicConnectionRetryMaxDelayMs' => max(2, (int) ($policy['candidate_retry_max_delay_seconds'] ?? 30)) * 1000,
                'publicConnectionCandidateMaxDurationMs' => max(60, (int) ($policy['candidate_max_duration_seconds'] ?? 1200)) * 1000,
                'publicConnectionDialogMissingMaxAttempts' => max(1, min(10, (int) ($policy['dialog_missing_max_attempts'] ?? 2))),
                'publicConnectionRateLimitAccountSwitchEnabled' => (bool) ($policy['rate_limit_account_switch_enabled'] ?? true),
            ];
        }

        return $runtime;
    }

    public function processStallTimeoutSeconds(): int
    {
        return max(60, min(86400, (int) ($this->for('global')['process_stall_timeout_seconds'] ?? 900)));
    }

    public static function mergeWithDefaults(array $defaults, array $stored): array
    {
        $merged = $defaults;

        foreach ($defaults as $group => $groupDefaults) {
            if (! is_array($groupDefaults)) {
                continue;
            }

            $storedGroup = is_array($stored[$group] ?? null) ? $stored[$group] : [];
            $merged[$group] = [
                ...$groupDefaults,
                ...array_intersect_key($storedGroup, $groupDefaults),
            ];
        }

        return $merged;
    }

    private function forOperation(string $operationMode): array
    {
        return $this->for($this->scanTypeForOperation($operationMode));
    }
}
