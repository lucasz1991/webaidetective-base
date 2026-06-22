<?php

namespace Tests\Feature;

use App\Models\InstagramProfile;
use App\Models\InstagramStoryItem;
use App\Models\InstagramStoryScan;
use App\Models\TrackedPerson;
use App\Models\User;
use App\Services\TrackedPeople\TrackedPersonInstagramStoryScanService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionMethod;
use Tests\TestCase;
use Livewire\Livewire;

class InstagramStoryScanStorageTest extends TestCase
{
    use DatabaseTransactions;

    public function test_story_and_highlight_scans_are_stored_separately(): void
    {
        $user = User::factory()->create();
        $profile = InstagramProfile::create(['username' => 'story_storage_test']);
        $person = TrackedPerson::create([
            'user_id' => $user->id,
            'first_name' => 'Story',
            'last_name' => 'Storage',
            'instagram_username' => $profile->username,
            'current_instagram_profile_id' => $profile->id,
        ]);
        $storyScan = InstagramStoryScan::create([
            'instagram_profile_id' => $profile->id,
            'tracked_person_id' => $person->id,
            'user_id' => $user->id,
            'instagram_username' => $profile->username,
            'scan_type' => 'stories',
            'status_level' => 'success',
            'attempted' => true,
            'available' => true,
            'complete' => true,
            'observed_count' => 1,
            'scanned_at' => now('UTC'),
        ]);
        $highlightScan = InstagramStoryScan::create([
            'instagram_profile_id' => $profile->id,
            'tracked_person_id' => $person->id,
            'user_id' => $user->id,
            'instagram_username' => $profile->username,
            'scan_type' => 'highlights',
            'status_level' => 'success',
            'attempted' => true,
            'available' => true,
            'complete' => true,
            'observed_count' => 1,
            'scanned_at' => now('UTC'),
        ]);
        InstagramStoryItem::create([
            'instagram_story_scan_id' => $storyScan->id,
            'instagram_profile_id' => $profile->id,
            'item_key' => 'story-1',
            'source_type' => 'stories',
            'position' => 0,
            'media_type' => 'image',
            'story_url' => 'https://www.instagram.com/stories/story_storage_test/1/',
            'source_url' => 'https://scontent.cdninstagram.com/story.jpg',
            'preview_url' => 'https://scontent.cdninstagram.com/story-preview.jpg',
        ]);
        InstagramStoryItem::create([
            'instagram_story_scan_id' => $highlightScan->id,
            'instagram_profile_id' => $profile->id,
            'item_key' => 'highlight-1',
            'source_type' => 'highlights',
            'highlight_id' => '123456',
            'highlight_title' => 'Reisen',
            'position' => 0,
            'media_type' => 'image',
            'story_url' => 'https://www.instagram.com/stories/highlights/123456/',
            'source_url' => 'https://scontent.cdninstagram.com/highlight.jpg',
        ]);

        $this->assertSame(2, $person->instagramStoryScans()->count());
        $this->assertSame(2, $profile->storyScans()->count());
        $this->assertSame('Reisen', $highlightScan->items()->firstOrFail()->highlight_title);
        $this->assertSame(
            'https://scontent.cdninstagram.com/story-preview.jpg',
            $storyScan->items()->firstOrFail()->preview_media_url,
        );
    }

    public function test_scraper_items_are_normalized_for_story_and_highlight_storage(): void
    {
        $service = app(TrackedPersonInstagramStoryScanService::class);
        $method = new ReflectionMethod($service, 'normalizeItems');
        $method->setAccessible(true);
        $stories = $method->invoke($service, [[
            'storyId' => 'story-42',
            'storyUrl' => 'https://www.instagram.com/stories/example/42/',
            'mediaType' => 'video',
            'sourceUrl' => 'https://scontent.cdninstagram.com/story.mp4',
            'previewUrl' => 'https://scontent.cdninstagram.com/story.jpg',
            'durationSeconds' => 4.25,
        ]], 'stories');
        $highlights = $method->invoke($service, [[
            'highlightId' => 'highlight-42',
            'title' => 'Sommer',
            'highlightUrl' => 'https://www.instagram.com/stories/highlights/highlight-42/',
            'coverUrl' => 'https://scontent.cdninstagram.com/highlight.jpg',
        ]], 'highlights');

        $this->assertSame('story-42', $stories[0]['item_key']);
        $this->assertSame('video', $stories[0]['media_type']);
        $this->assertSame(4250, $stories[0]['duration_ms']);
        $this->assertSame('highlight-42', $highlights[0]['highlight_id']);
        $this->assertSame('Sommer', $highlights[0]['highlight_title']);
        $this->assertSame('https://scontent.cdninstagram.com/highlight.jpg', $highlights[0]['preview_url']);
    }

    public function test_tracked_person_detail_renders_story_tab_and_scan_options(): void
    {
        $user = User::factory()->create();
        $profile = InstagramProfile::create(['username' => 'story_detail_test']);
        $person = TrackedPerson::create([
            'user_id' => $user->id,
            'first_name' => 'Story',
            'last_name' => 'Detail',
            'instagram_username' => $profile->username,
            'current_instagram_profile_id' => $profile->id,
        ]);

        Livewire::actingAs($user)
            ->test(\App\Livewire\User\TrackedPersonDetail::class, [
                'trackedPersonId' => $person->id,
            ])
            ->assertSee('Stories')
            ->assertSee('Storys scannen')
            ->assertSee('Highlights scannen');
    }
}
