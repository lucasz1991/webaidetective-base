<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\CreditWallet;
use App\Models\InstagramProfile;
use App\Models\InstagramProfileRelationship;
use App\Models\InstagramProfileScan;
use App\Models\TrackedPerson;
use App\Models\TrackedPersonInstagramInferredConnection;
use App\Models\TrackedPersonInstagramSuggestionScan;
use App\Models\User;
use App\Services\Billing\ScanCreditService;
use App\Services\TrackedPeople\TrackedPersonInstagramSuggestionScanService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionMethod;
use Tests\TestCase;

class InstagramProfileScanBillingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_profile_scan_cost_is_charged_and_stored_once(): void
    {
        $user = User::factory()->create();
        $profile = InstagramProfile::create(['username' => 'billing_profile_test']);
        $scan = InstagramProfileScan::create([
            'instagram_profile_id' => $profile->id,
            'user_id' => $user->id,
            'scan_mode' => 'mini',
            'status_level' => 'success',
            'status_message' => 'Test scan completed.',
            'raw_payload' => ['billing' => ['totalCredits' => 7]],
            'scanned_at' => now('UTC'),
        ]);
        CreditWallet::create([
            'user_id' => $user->id,
            'available_credits' => 20,
            'reserved_credits' => 0,
            'used_credits' => 0,
            'bonus_credits' => 0,
        ]);
        $service = app(ScanCreditService::class);

        $this->assertSame(7, $service->charge(
            $user->id,
            $scan,
            $scan->raw_payload,
            'Instagram-Mini-Scan @billing_profile_test',
        ));
        $this->assertSame(0, $service->charge(
            $user->id,
            $scan,
            $scan->raw_payload,
            'Instagram-Mini-Scan @billing_profile_test',
        ));

        $wallet = $user->creditWallet()->firstOrFail();
        $this->assertSame(13, $wallet->available_credits);
        $this->assertSame(7, $wallet->used_credits);
        $this->assertSame(1, CreditTransaction::query()
            ->where('user_id', $user->id)
            ->where('reference_type', $scan->getMorphClass())
            ->where('reference_id', $scan->id)
            ->count());
    }

    public function test_suggestion_scan_can_be_stored_without_tracked_person(): void
    {
        $user = User::factory()->create();
        $profile = InstagramProfile::create(['username' => 'suggestion_profile_test']);

        $scan = TrackedPersonInstagramSuggestionScan::create([
            'tracked_person_id' => null,
            'instagram_profile_id' => $profile->id,
            'user_id' => $user->id,
            'target_username' => $profile->username,
            'status_level' => 'success',
            'status_message' => 'Suggestions stored.',
            'suggestions_observed_count' => 4,
            'suggestions_checked_count' => 0,
            'suggestion_matches_count' => 0,
            'gracefully_stopped' => false,
            'raw_payload' => ['billing' => ['totalCredits' => 3]],
            'analyzed_at' => now('UTC'),
        ]);

        $this->assertNull($scan->tracked_person_id);
        $this->assertSame($profile->id, $scan->instagram_profile_id);
        $this->assertSame($scan->id, $profile->suggestionScans()->firstOrFail()->id);
    }

    public function test_suggestion_scan_stores_initial_and_second_level_suggestion_relationships(): void
    {
        $user = User::factory()->create();
        $target = InstagramProfile::create(['username' => 'network_target_test']);
        $scan = TrackedPersonInstagramSuggestionScan::create([
            'tracked_person_id' => null,
            'instagram_profile_id' => $target->id,
            'user_id' => $user->id,
            'target_username' => $target->username,
            'status_level' => 'success',
            'status_message' => 'Suggestions stored.',
            'suggestions_observed_count' => 2,
            'suggestions_checked_count' => 1,
            'suggestion_matches_count' => 0,
            'gracefully_stopped' => false,
            'raw_payload' => [],
            'analyzed_at' => now('UTC'),
        ]);
        $payload = [
            'suggestionScan' => [
                'observedSuggestions' => [
                    ['username' => 'first_level_a', 'displayName' => 'First A'],
                    ['username' => 'first_level_b', 'displayName' => 'First B'],
                ],
                'checkedCandidates' => [
                    [
                        'username' => 'first_level_a',
                        'displayName' => 'First A',
                        'suggestionPreview' => [
                            ['username' => 'second_level_a', 'displayName' => 'Second A'],
                            ['username' => 'second_level_b', 'displayName' => 'Second B'],
                        ],
                    ],
                ],
            ],
        ];

        $method = new ReflectionMethod(
            app(TrackedPersonInstagramSuggestionScanService::class),
            'storeSuggestionRelationshipsFromPayload',
        );
        $method->setAccessible(true);
        $method->invoke(
            app(TrackedPersonInstagramSuggestionScanService::class),
            null,
            $scan,
            $target,
            $target->username,
            $payload,
            now('UTC'),
        );

        $firstLevelA = InstagramProfile::where('username', 'first_level_a')->firstOrFail();
        $secondLevelA = InstagramProfile::where('username', 'second_level_a')->firstOrFail();

        $this->assertDatabaseHas('instagram_profile_relationships', [
            'source_instagram_profile_id' => $target->id,
            'related_instagram_profile_id' => $firstLevelA->id,
            'list_type' => 'profile_suggestions',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('instagram_profile_relationships', [
            'source_instagram_profile_id' => $firstLevelA->id,
            'related_instagram_profile_id' => $secondLevelA->id,
            'list_type' => 'profile_suggestions',
            'status' => 'active',
        ]);
        $this->assertSame(4, InstagramProfileRelationship::query()
            ->where('list_type', 'profile_suggestions')
            ->where('status', 'active')
            ->count());
    }

    public function test_known_suggestions_are_added_to_candidate_history_without_rechecking(): void
    {
        $user = User::factory()->create();
        $target = InstagramProfile::create(['username' => 'known_history_target']);
        $knownFromGraph = InstagramProfile::create(['username' => 'known_from_graph']);
        TrackedPersonInstagramSuggestionScan::create([
            'tracked_person_id' => null,
            'instagram_profile_id' => $target->id,
            'user_id' => $user->id,
            'target_username' => $target->username,
            'status_level' => 'success',
            'status_message' => 'Suggestions stored.',
            'suggestions_observed_count' => 1,
            'suggestions_checked_count' => 0,
            'suggestion_matches_count' => 0,
            'gracefully_stopped' => false,
            'raw_payload' => [
                'suggestionScan' => [
                    'observedSuggestions' => [
                        ['username' => 'known_from_previous_scan'],
                    ],
                ],
            ],
            'analyzed_at' => now('UTC'),
        ]);
        InstagramProfileRelationship::create([
            'source_instagram_profile_id' => $target->id,
            'related_instagram_profile_id' => $knownFromGraph->id,
            'list_type' => 'profile_suggestions',
            'status' => 'active',
            'first_seen_at' => now('UTC'),
            'last_seen_at' => now('UTC'),
        ]);

        $method = new ReflectionMethod(
            app(TrackedPersonInstagramSuggestionScanService::class),
            'buildSuggestionCandidateHistory',
        );
        $method->setAccessible(true);
        $history = $method->invoke(
            app(TrackedPersonInstagramSuggestionScanService::class),
            null,
            $target,
            2,
        );

        $this->assertTrue($history['known_from_graph']['knownProfile']);
        $this->assertTrue($history['known_from_graph']['knownSuggestion']);
        $this->assertTrue($history['known_from_previous_scan']['knownProfile']);
        $this->assertTrue($history['known_from_previous_scan']['knownSuggestion']);
    }

    public function test_public_suggestion_list_hits_are_stored_as_reconstructed_lists_not_suggestion_connections(): void
    {
        $user = User::factory()->create();
        $person = TrackedPerson::create([
            'user_id' => $user->id,
            'first_name' => 'Public',
            'last_name' => 'Target',
            'instagram_username' => 'public_rule_target',
        ]);
        $connection = [
            'username' => 'public_candidate_hit',
            'displayName' => 'Public Candidate',
            'profileUrl' => 'https://www.instagram.com/public_candidate_hit/',
            'profileVisibility' => 'public',
            'isPrivate' => false,
            'targetFoundAsSuggestion' => false,
            'targetFoundInPublicLists' => true,
            'targetFoundInFollowers' => true,
            'targetFoundInFollowing' => true,
            'sourceLists' => ['public_profile_followers', 'public_profile_following'],
            'publicListSearch' => ['targetFound' => true],
        ];
        $service = app(TrackedPersonInstagramSuggestionScanService::class);
        $seenAt = now('UTC');

        $storeInferred = new ReflectionMethod($service, 'storeInferredSuggestionConnections');
        $storeInferred->setAccessible(true);
        $storeInferred->invoke($service, $person, null, 'public_rule_target', [$connection], [], $seenAt);

        $storePublicLists = new ReflectionMethod($service, 'storePublicListRelationships');
        $storePublicLists->setAccessible(true);
        $storePublicLists->invoke($service, $person, null, 'public_rule_target', [$connection], $seenAt);

        $target = InstagramProfile::where('username', 'public_rule_target')->firstOrFail();
        $candidate = InstagramProfile::where('username', 'public_candidate_hit')->firstOrFail();

        $this->assertSame(0, TrackedPersonInstagramInferredConnection::query()
            ->where('tracked_person_id', $person->id)
            ->where('relationship_type', 'suggestion_connection')
            ->where('candidate_username', 'public_candidate_hit')
            ->count());

        foreach (['followers', 'following'] as $listType) {
            $relationship = InstagramProfileRelationship::query()
                ->where('source_instagram_profile_id', $target->id)
                ->where('related_instagram_profile_id', $candidate->id)
                ->where('list_type', $listType)
                ->firstOrFail();

            $this->assertTrue((bool) data_get($relationship->evidence, 'reconstructed'));
            $this->assertSame(
                'reconstructed_from_public_suggestion_scan',
                data_get($relationship->evidence, 'relationship_origin'),
            );
        }
    }
}
