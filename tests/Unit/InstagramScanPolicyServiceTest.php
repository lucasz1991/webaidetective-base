<?php

namespace Tests\Unit;

use App\Services\TrackedPeople\InstagramScanPolicyService;
use PHPUnit\Framework\TestCase;

class InstagramScanPolicyServiceTest extends TestCase
{
    public function test_stored_values_are_merged_without_accepting_unknown_keys(): void
    {
        $defaults = [
            'suggestion_deep_search' => [
                'skip_previously_checked' => true,
                'no_match_skip_after' => 2,
            ],
            'posts' => [
                'max_items' => 100,
            ],
        ];

        $merged = InstagramScanPolicyService::mergeWithDefaults($defaults, [
            'suggestion_deep_search' => [
                'skip_previously_checked' => false,
                'unknown' => 'ignored',
            ],
            'unknown_group' => [
                'value' => true,
            ],
        ]);

        $this->assertFalse($merged['suggestion_deep_search']['skip_previously_checked']);
        $this->assertSame(2, $merged['suggestion_deep_search']['no_match_skip_after']);
        $this->assertArrayNotHasKey('unknown', $merged['suggestion_deep_search']);
        $this->assertArrayNotHasKey('unknown_group', $merged);
        $this->assertSame(100, $merged['posts']['max_items']);
    }

    public function test_scraper_operations_are_mapped_to_the_expected_policy(): void
    {
        $service = new InstagramScanPolicyService;

        $this->assertSame('mini', $service->scanTypeForOperation('mini'));
        $this->assertSame('lists', $service->scanTypeForOperation('followers'));
        $this->assertSame('posts', $service->scanTypeForOperation('post-scan'));
        $this->assertSame('suggestions', $service->scanTypeForOperation('suggestions'));
        $this->assertSame('suggestion_deep_search', $service->scanTypeForOperation('suggestion-connections'));
        $this->assertSame('public_connections', $service->scanTypeForOperation('public-profile-connections'));
        $this->assertSame('profile', $service->scanTypeForOperation('profile'));
    }
}
