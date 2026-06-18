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
            'scriptWatchdogEnabled' => (bool) ($global['script_watchdog_enabled'] ?? true),
            'scriptStallTimeoutMs' => max(
                60,
                (int) ($global['node_watchdog_timeout_seconds'] ?? 900),
            ) * 1000,
            'browserDisconnectAbort' => (bool) ($global['browser_disconnect_abort'] ?? true),
            'navigationTimeoutMs' => max(30, min(3600, (int) ($global['navigation_timeout_seconds'] ?? 120))) * 1000,
            'postLoginWaitMs' => max(500, min(60000, (int) ($global['post_login_wait_ms'] ?? 2500))),
            'typingDelayMs' => max(0, min(1000, (int) ($global['typing_delay_ms'] ?? 35))),
            'livePreviewEnabled' => (bool) ($global['live_preview_enabled'] ?? true),
            'skipDebugArtifacts' => (bool) ($global['skip_debug_artifacts'] ?? false),
            'blockHeavyResources' => (bool) ($global['block_heavy_resources'] ?? false),
        ];

        if ($scanType === 'lists') {
            $runtime += [
                'followerListMaxItems' => max(0, (int) ($policy['max_items'] ?? 0)),
                'followingListMaxItems' => max(0, (int) ($policy['max_items'] ?? 0)),
                'relationshipListMaxScrollRounds' => max(20, (int) ($policy['max_scroll_rounds'] ?? 100000)),
                'relationshipPartitionLargeLists' => (bool) ($policy['partition_large_lists'] ?? true),
                'relationshipPartitionThreshold' => max(1, (int) ($policy['partition_threshold'] ?? 250)),
                'relationshipSearchQueriesPerDialog' => max(1, min(100, (int) ($policy['search_queries_per_dialog'] ?? 8))),
                'relationshipSearchPartitionMaxItems' => max(25, (int) ($policy['search_partition_max_items'] ?? 250)),
                'relationshipProgressCheckpointSize' => max(25, (int) ($policy['progress_checkpoint_size'] ?? 250)),
                'relationshipSearchTargetMaxItems' => max(0, (int) ($policy['search_target_max_items'] ?? 0)),
                'relationshipSearchTargetMaxScrollRounds' => max(1, (int) ($policy['search_target_max_scroll_rounds'] ?? 60)),
                'relationshipSearchInputMaxAttempts' => max(1, min(10, (int) ($policy['search_input_max_attempts'] ?? 3))),
                'relationshipSearchWaitMs' => max(250, min(60000, (int) ($policy['search_wait_ms'] ?? 900))),
            ];
        }

        if ($scanType === 'posts') {
            $runtime += [
                'postScanMaxItems' => max(1, (int) ($policy['max_items'] ?? 100)),
                'postScanMaxScrollRounds' => max(1, (int) ($policy['max_scroll_rounds'] ?? 40)),
                'postScanMaxLikesPerPost' => max(1, (int) ($policy['max_likes_per_post'] ?? 250)),
                'postScanMaxCommentsPerPost' => max(1, (int) ($policy['max_comments_per_post'] ?? 250)),
                'postScanOpenLikesDialogEnabled' => (bool) ($policy['open_likes_dialog_enabled'] ?? true),
                'postScanLikeDialogMaxScrollRounds' => max(1, min(1000, (int) ($policy['like_dialog_max_scroll_rounds'] ?? 40))),
                'postScanCommentDialogMaxScrollRounds' => max(1, min(1000, (int) ($policy['comment_dialog_max_scroll_rounds'] ?? 40))),
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
                'profileHoverCardsEnabled' => (bool) ($policy['profile_hover_cards_enabled'] ?? true),
                'profileHoverCardWaitMs' => max(250, min(60000, (int) ($policy['profile_hover_card_wait_ms'] ?? 850))),
            ];
        }

        if ($scanType === 'public_connections') {
            $listPolicy = $policies['lists'] ?? [];
            $runtime += [
                'relationshipSearchTargetMaxItems' => max(0, (int) ($listPolicy['search_target_max_items'] ?? 0)),
                'relationshipSearchTargetMaxScrollRounds' => max(1, (int) ($listPolicy['search_target_max_scroll_rounds'] ?? 60)),
                'relationshipSearchInputMaxAttempts' => max(1, min(10, (int) ($listPolicy['search_input_max_attempts'] ?? 3))),
                'relationshipSearchWaitMs' => max(250, min(60000, (int) ($listPolicy['search_wait_ms'] ?? 900))),
                'publicConnectionCandidateMaxAttempts' => max(1, min(10, (int) ($policy['candidate_max_attempts'] ?? 3))),
                'publicConnectionRetryDelayMs' => max(2, (int) ($policy['candidate_retry_delay_seconds'] ?? 6)) * 1000,
                'publicConnectionRetryMaxDelayMs' => max(2, (int) ($policy['candidate_retry_max_delay_seconds'] ?? 30)) * 1000,
                'publicConnectionCandidateMaxDurationMs' => max(60, (int) ($policy['candidate_max_duration_seconds'] ?? 1200)) * 1000,
                'publicConnectionDialogMissingMaxAttempts' => max(1, min(10, (int) ($policy['dialog_missing_max_attempts'] ?? 2))),
                'publicConnectionRateLimitAccountSwitchEnabled' => (bool) ($policy['rate_limit_account_switch_enabled'] ?? true),
                'publicConnectionMaxScraperProfileSwitches' => max(0, min(10, (int) ($policy['max_scraper_profile_switches'] ?? 3))),
            ];
        }

        return $runtime;
    }

    public function processStallTimeoutSeconds(): int
    {
        return max(60, min(86400, (int) ($this->for('global')['process_stall_timeout_seconds'] ?? 900)));
    }

    public function profileSwitchExtraAttempts(): int
    {
        return max(0, min(10, (int) ($this->for('global')['profile_switch_extra_attempts'] ?? 2)));
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
