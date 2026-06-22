<?php

namespace Tests\Feature;

use App\Models\InstagramPost;
use App\Models\InstagramPostComment;
use App\Models\InstagramPostScan;
use App\Models\InstagramProfile;
use App\Models\User;
use App\Services\TrackedPeople\TrackedPersonInstagramPostScanService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionMethod;
use Tests\TestCase;

class InstagramPostEngagementStorageTest extends TestCase
{
    use DatabaseTransactions;

    public function test_comment_like_count_is_normalized_and_stored(): void
    {
        $user = User::factory()->create();
        $profile = InstagramProfile::create(['username' => 'comment_likes_profile']);
        $scan = InstagramPostScan::create([
            'instagram_profile_id' => $profile->id,
            'user_id' => $user->id,
            'status_level' => 'success',
            'attempted' => true,
            'available' => true,
            'complete' => true,
            'scanned_at' => now('UTC'),
        ]);
        $post = InstagramPost::create([
            'instagram_profile_id' => $profile->id,
            'shortcode' => 'CommentLikesTest',
            'media_type' => 'post',
            'post_url' => 'https://www.instagram.com/p/CommentLikesTest/',
        ]);
        $service = app(TrackedPersonInstagramPostScanService::class);
        $normalize = new ReflectionMethod($service, 'normalizePostComments');
        $normalize->setAccessible(true);
        $comments = $normalize->invoke($service, [[
            'instagramCommentId' => 'comment-1',
            'username' => 'comment_author',
            'text' => 'Das ist ein guter Beitrag',
            'likesCount' => 17,
        ]]);

        $this->assertSame(17, $comments[0]['likes_count']);

        $store = new ReflectionMethod($service, 'storePostComments');
        $store->setAccessible(true);
        $store->invoke($service, $post, $scan, $comments, true, [], now('UTC'));

        $this->assertDatabaseHas('instagram_post_comments', [
            'instagram_post_id' => $post->id,
            'instagram_comment_id' => 'comment-1',
            'likes_count' => 17,
            'is_active' => true,
        ]);
    }

    public function test_missing_like_count_does_not_erase_previously_stored_comment_likes(): void
    {
        $user = User::factory()->create();
        $profile = InstagramProfile::create(['username' => 'comment_likes_preserve']);
        $scan = InstagramPostScan::create([
            'instagram_profile_id' => $profile->id,
            'user_id' => $user->id,
            'status_level' => 'success',
            'attempted' => true,
            'available' => true,
            'complete' => false,
            'scanned_at' => now('UTC'),
        ]);
        $post = InstagramPost::create([
            'instagram_profile_id' => $profile->id,
            'shortcode' => 'PreserveLikesTest',
            'media_type' => 'post',
            'post_url' => 'https://www.instagram.com/p/PreserveLikesTest/',
        ]);
        InstagramPostComment::create([
            'instagram_post_id' => $post->id,
            'instagram_comment_id' => 'comment-2',
            'comment_text' => 'Bereits gespeichert',
            'likes_count' => 23,
            'is_active' => true,
        ]);
        $service = app(TrackedPersonInstagramPostScanService::class);
        $store = new ReflectionMethod($service, 'storePostComments');
        $store->setAccessible(true);
        $store->invoke($service, $post, $scan, [[
            'instagram_comment_id' => 'comment-2',
            'parent_instagram_comment_id' => null,
            'instagram_user_id' => null,
            'username' => 'comment_author',
            'full_name' => null,
            'profile_image_url' => null,
            'comment_text' => 'Bereits gespeichert',
            'likes_count' => null,
            'is_verified' => null,
            'published_at' => null,
            'raw_comment' => [],
        ]], false, [], now('UTC'));

        $this->assertSame(
            23,
            InstagramPostComment::query()
                ->where('instagram_post_id', $post->id)
                ->where('instagram_comment_id', 'comment-2')
                ->value('likes_count'),
        );
    }
}
