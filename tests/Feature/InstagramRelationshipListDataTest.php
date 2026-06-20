<?php

namespace Tests\Feature;

use App\Models\InstagramProfile;
use App\Models\InstagramProfileListScan;
use App\Models\InstagramProfileListScanItem;
use App\Models\InstagramProfileRelationship;
use App\Models\TrackedPerson;
use App\Models\User;
use App\Support\InstagramRelationshipListData;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Tests\TestCase;

class InstagramRelationshipListDataTest extends TestCase
{
    use DatabaseTransactions;

    public function test_passive_relationships_are_loaded_from_other_profile_lists_by_username_fallback(): void
    {
        $user = User::factory()->create();
        $target = InstagramProfile::create(['username' => 'target_profile_test']);
        $passiveFollower = InstagramProfile::create(['username' => 'passive_follower_test']);
        $passivelyFollowed = InstagramProfile::create(['username' => 'passively_followed_test']);
        $trackedPerson = TrackedPerson::create([
            'user_id' => $user->id,
            'first_name' => 'Target',
            'last_name' => 'Person',
            'instagram_username' => 'target_profile_test',
            'current_instagram_profile_id' => null,
        ]);

        $this->createRelationshipFromListScan($user, $passiveFollower, $target, 'following');
        $this->createRelationshipFromListScan($user, $passivelyFollowed, $target, 'followers');

        $relationshipLists = app(InstagramRelationshipListData::class);
        $followersData = $relationshipLists->forTrackedPerson($trackedPerson->fresh(), 'followers');
        $followingData = $relationshipLists->forTrackedPerson($trackedPerson->fresh(), 'following');
        $profileFollowersData = $relationshipLists->forInstagramProfile($target, (int) $user->id, 'followers');
        $profileFollowingData = $relationshipLists->forInstagramProfile($target, (int) $user->id, 'following');

        $this->assertPassiveItem($followersData['passiveItems'], 'passive_follower_test');
        $this->assertPassiveItem($followersData['activeItems'], 'passive_follower_test');
        $this->assertPassiveItem($followingData['passiveItems'], 'passively_followed_test');
        $this->assertPassiveItem($followingData['activeItems'], 'passively_followed_test');
        $this->assertPassiveItem($profileFollowersData['activeItems'], 'passive_follower_test');
        $this->assertPassiveItem($profileFollowingData['activeItems'], 'passively_followed_test');
    }

    public function test_tracked_person_lists_include_direct_instagram_profile_list_scans(): void
    {
        $user = User::factory()->create();
        $target = InstagramProfile::create(['username' => 'target_direct_scan_test']);
        $directFollower = InstagramProfile::create(['username' => 'direct_profile_scan_follower_test']);
        $trackedPerson = TrackedPerson::create([
            'user_id' => $user->id,
            'first_name' => 'Target',
            'last_name' => 'Direct',
            'instagram_username' => $target->username,
            'current_instagram_profile_id' => null,
        ]);

        $this->createRelationshipFromListScan($user, $target, $directFollower, 'followers');

        $data = app(InstagramRelationshipListData::class)->forTrackedPerson($trackedPerson->fresh(), 'followers');
        $item = $data['activeItems']->firstWhere('username', 'direct_profile_scan_follower_test');

        $this->assertNotNull($item);
        $this->assertFalse((bool) data_get($item, 'passive'));
    }

    public function test_passive_marker_survives_when_profile_is_already_in_direct_list(): void
    {
        $user = User::factory()->create();
        $target = InstagramProfile::create(['username' => 'target_direct_merge_test']);
        $passiveFollower = InstagramProfile::create(['username' => 'direct_and_passive_test']);
        $trackedPerson = TrackedPerson::create([
            'user_id' => $user->id,
            'first_name' => 'Target',
            'last_name' => 'Merge',
            'instagram_username' => $target->username,
            'current_instagram_profile_id' => $target->id,
        ]);

        $trackedPerson->instagramSnapshots()->create([
            'instagram_profile_id' => $target->id,
            'instagram_username' => $target->username,
            'status_level' => 'success',
            'raw_payload' => [
                'extractedProfile' => [
                    'followersList' => [
                        'items' => [
                            [
                                'username' => 'direct_and_passive_test',
                                'displayName' => 'Direct Item',
                            ],
                        ],
                        'activeCount' => 1,
                        'observedCount' => 1,
                    ],
                ],
            ],
            'analyzed_at' => now('UTC'),
        ]);
        $this->createRelationshipFromListScan($user, $passiveFollower, $target, 'following');

        $data = app(InstagramRelationshipListData::class)->forTrackedPerson(
            $trackedPerson->fresh()->load('latestInstagramSnapshot'),
            'followers',
        );
        $item = $data['activeItems']->firstWhere('username', 'direct_and_passive_test');

        $this->assertNotNull($item);
        $this->assertTrue((bool) $item['passive']);
        $this->assertSame('Passiver Follower', $item['statusLabel']);
    }

    public function test_passive_relationships_stay_scoped_to_the_observing_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $target = InstagramProfile::create(['username' => 'target_scope_test']);
        $otherUsersSource = InstagramProfile::create(['username' => 'other_user_source_test']);
        $trackedPerson = TrackedPerson::create([
            'user_id' => $user->id,
            'first_name' => 'Target',
            'last_name' => 'Scope',
            'instagram_username' => $target->username,
            'current_instagram_profile_id' => $target->id,
        ]);

        $this->createRelationshipFromListScan($otherUser, $otherUsersSource, $target, 'following');

        $data = app(InstagramRelationshipListData::class)->forTrackedPerson($trackedPerson->fresh(), 'followers');

        $this->assertNull($data['passiveItems']->firstWhere('username', 'other_user_source_test'));
        $this->assertNull($data['activeItems']->firstWhere('username', 'other_user_source_test'));
    }

    private function createRelationshipFromListScan(
        User $user,
        InstagramProfile $source,
        InstagramProfile $related,
        string $listType,
    ): InstagramProfileRelationship {
        $scan = InstagramProfileListScan::create([
            'instagram_profile_id' => $source->id,
            'user_id' => $user->id,
            'instagram_username' => $source->username,
            'list_type' => $listType,
            'scan_mode' => 'profile_list',
            'status_level' => 'success',
            'status_message' => 'Test list scan.',
            'attempted' => true,
            'available' => true,
            'complete' => true,
            'observed_count' => 1,
            'active_count' => 1,
            'known_count' => 1,
            'scanned_at' => now('UTC'),
        ]);

        $relationship = InstagramProfileRelationship::create([
            'source_instagram_profile_id' => $source->id,
            'related_instagram_profile_id' => $related->id,
            'first_seen_scan_id' => $scan->id,
            'last_seen_scan_id' => $scan->id,
            'list_type' => $listType,
            'status' => 'active',
            'first_seen_at' => now('UTC')->subMinute(),
            'last_seen_at' => now('UTC'),
        ]);

        InstagramProfileListScanItem::create([
            'list_scan_id' => $scan->id,
            'relationship_id' => $relationship->id,
            'source_instagram_profile_id' => $source->id,
            'related_instagram_profile_id' => $related->id,
            'list_type' => $listType,
            'item_status' => 'observed',
            'username_snapshot' => $related->username,
            'display_name_snapshot' => $related->display_name,
            'profile_url_snapshot' => $related->profile_url,
            'raw_item' => [
                'username' => $related->username,
                'displayName' => $related->display_name,
                'profileUrl' => $related->profile_url,
            ],
            'observed_at' => now('UTC'),
        ]);

        return $relationship;
    }

    private function assertPassiveItem(Collection $items, string $username): void
    {
        $item = $items->firstWhere('username', $username);

        $this->assertNotNull($item, 'Expected passive item '.$username.' to exist.');
        $this->assertTrue((bool) data_get($item, 'passive'));
    }
}
