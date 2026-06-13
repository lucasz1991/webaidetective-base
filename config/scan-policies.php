<?php

return [
    'defaults' => [
        'global' => [
            'process_stall_timeout_seconds' => 900,
            'node_watchdog_timeout_seconds' => 900,
        ],
        'mini' => [
            'error_attempts' => 1,
            'retry_delay_seconds' => 2,
            'session_fallback_enabled' => true,
        ],
        'profile' => [
            'error_attempts' => 1,
            'retry_delay_seconds' => 2,
            'visible_count_attempts' => 3,
        ],
        'lists' => [
            'error_attempts' => 1,
            'retry_delay_seconds' => 3,
            'max_items' => 0,
            'max_scroll_rounds' => 100000,
        ],
        'posts' => [
            'error_attempts' => 1,
            'retry_delay_seconds' => 3,
            'max_items' => 100,
            'max_scroll_rounds' => 40,
        ],
        'suggestions' => [
            'error_attempts' => 1,
            'retry_delay_seconds' => 3,
            'max_items' => 500,
            'inline_max_rounds' => 60,
            'dialog_max_rounds' => 100,
        ],
        'suggestion_deep_search' => [
            'error_attempts' => 1,
            'retry_delay_seconds' => 3,
            'candidate_max_items' => 300,
            'candidate_error_attempts' => 1,
            'candidate_retry_delay_seconds' => 3,
            'candidate_inline_max_rounds' => 40,
            'candidate_dialog_max_rounds' => 70,
            'public_list_max_scroll_rounds' => 60,
            'skip_previously_checked' => true,
            'no_match_skip_after' => 2,
            'max_scraper_profile_switches' => 3,
        ],
        'public_connections' => [
            'error_attempts' => 1,
            'retry_delay_seconds' => 5,
            'resume_previous' => true,
            'skip_completed_candidates' => true,
            'candidate_max_attempts' => 3,
            'candidate_retry_delay_seconds' => 6,
            'candidate_retry_max_delay_seconds' => 30,
            'candidate_max_duration_seconds' => 1200,
            'dialog_missing_max_attempts' => 2,
            'rate_limit_account_switch_enabled' => true,
        ],
    ],
];
