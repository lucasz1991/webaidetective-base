<?php

namespace Tests\Unit;

use App\Services\Social\InstagramProfileDataExtractor;
use PHPUnit\Framework\TestCase;

class InstagramProfileDataExtractorTest extends TestCase
{
    public function test_profile_image_url_is_read_from_mini_scan_profile_image_field(): void
    {
        $extractor = new InstagramProfileDataExtractor;
        $imageUrl = 'https://scontent.cdninstagram.com/v/t51.2885-19/profile.jpg?x=1&amp;y=2';

        $extracted = $extractor->extract([
            'operationMode' => 'mini',
            'profile' => [
                'profileImageUrl' => $imageUrl,
                'ogImage' => null,
                'counts' => [
                    'posts' => 12,
                    'followers' => 345,
                    'following' => 67,
                    'sources' => [
                        'posts' => 'profile_dom',
                        'followers' => 'profile_dom',
                        'following' => 'profile_dom',
                    ],
                ],
            ],
        ]);

        $this->assertSame($imageUrl, $extracted['profile_image_url']);
        $this->assertSame([
            'https://scontent.cdninstagram.com/v/t51.2885-19/profile.jpg?x=1&y=2',
        ], $extracted['image_urls']);
    }

    public function test_profile_image_url_falls_back_to_og_image(): void
    {
        $extractor = new InstagramProfileDataExtractor;
        $imageUrl = 'https://scontent.cdninstagram.com/v/t51.2885-19/og-profile.jpg';

        $extracted = $extractor->extract([
            'operationMode' => 'mini',
            'profile' => [
                'ogImage' => $imageUrl,
            ],
        ]);

        $this->assertSame($imageUrl, $extracted['profile_image_url']);
        $this->assertSame([$imageUrl], $extracted['image_urls']);
    }
}
