<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\CreditWallet;
use App\Models\InstagramProfile;
use App\Models\InstagramProfileRelationship;
use App\Models\InstagramProfileScan;
use App\Models\TrackedPerson;
use App\Models\TrackedPersonInstagramInferredConnection;
use App\Models\TrackedPersonInstagramProfileLink;
use App\Models\TrackedPersonInstagramSuggestionScan;
use App\Models\User;
use App\Services\Billing\ScanCreditService;
use App\Services\TrackedPeople\InstagramProfileRelationshipStore;
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

    public function test_profile_data_is_propagated_to_linked_tracked_people(): void
    {
        $user = User::factory()->create();
        $profile = InstagramProfile::create([
            'username' => 'linked_profile_test',
            'profile_image_path' => 'instagram/profiles/linked.jpg',
            'profile_image_hash' => 'hash-linked',
            'followers_count' => 1234,
            'following_count' => 321,
            'posts_count' => 45,
            'last_status_level' => 'success',
            'last_status_message' => 'Mini scan done.',
            'last_scanned_at' => now('UTC'),
        ]);
        $currentPerson = TrackedPerson::create([
            'user_id' => $user->id,
            'first_name' => 'Current',
            'last_name' => 'Person',
            'instagram_username' => 'old_name',
            'current_instagram_profile_id' => $profile->id,
        ]);
        $linkedPerson = TrackedPerson::create([
            'user_id' => $user->id,
            'first_name' => 'Linked',
            'last_name' => 'Person',
            'instagram_username' => 'old_linked',
        ]);
        TrackedPersonInstagramProfileLink::create([
            'tracked_person_id' => $linkedPerson->id,
            'instagram_profile_id' => $profile->id,
            'user_id' => $user->id,
            'relation_type' => 'observed',
            'is_current' => true,
            'linked_at' => now('UTC'),
        ]);

        app(InstagramProfileRelationshipStore::class)
            ->propagateProfileDataToLinkedTrackedPeople($profile);

        foreach ([$currentPerson->fresh(), $linkedPerson->fresh()] as $person) {
            $this->assertSame('linked_profile_test', $person->instagram_username);
            $this->assertSame('instagram/profiles/linked.jpg', $person->instagram_profile_image_path);
            $this->assertSame('hash-linked', $person->instagram_profile_image_hash);
            $this->assertSame(1234, $person->instagram_followers_count);
            $this->assertSame(321, $person->instagram_following_count);
            $this->assertSame(45, $person->instagram_posts_count);
            $this->assertSame('success', $person->last_instagram_status_level);
            $this->assertSame('Mini scan done.', $person->last_instagram_status_message);
        }
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
                'suggestionBranchedConnections' => [
                    [
                        'sourceUsername' => 'first_level_b',
                        'sourceDisplayName' => 'First B',
                        'suggestionPreview' => [
                            ['username' => 'second_level_c', 'displayName' => 'Second C'],
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
        $firstLevelB = InstagramProfile::where('username', 'first_level_b')->firstOrFail();
        $secondLevelA = InstagramProfile::where('username', 'second_level_a')->firstOrFail();
        $secondLevelC = InstagramProfile::where('username', 'second_level_c')->firstOrFail();

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
        $this->assertDatabaseHas('instagram_profile_relationships', [
            'source_instagram_profile_id' => $firstLevelB->id,
            'related_instagram_profile_id' => $secondLevelC->id,
            'list_type' => 'profile_suggestions',
            'status' => 'active',
        ]);
        $this->assertSame(5, InstagramProfileRelationship::query()
            ->where('list_type', 'profile_suggestions')
            ->where('status', 'active')
            ->count());
    }

    public function test_deepsearch_seed_profiles_are_loaded_from_previous_basic_suggestions(): void
    {
        $user = User::factory()->create();
        $target = InstagramProfile::create(['username' => 'deep_seed_target']);
        $graphSeed = InstagramProfile::create(['username' => 'deep_graph_seed']);
        InstagramProfileRelationship::create([
            'source_instagram_profile_id' => $target->id,
            'related_instagram_profile_id' => $graphSeed->id,
            'list_type' => 'profile_suggestions',
            'status' => 'active',
            'first_seen_at' => now('UTC'),
            'last_seen_at' => now('UTC'),
        ]);
        TrackedPersonInstagramSuggestionScan::create([
            'tracked_person_id' => null,
            'instagram_profile_id' => $target->id,
            'user_id' => $user->id,
            'target_username' => $target->username,
            'status_level' => 'success',
            'status_message' => 'Basic suggestions stored.',
            'suggestions_observed_count' => 1,
            'suggestions_checked_count' => 0,
            'suggestion_matches_count' => 0,
            'gracefully_stopped' => false,
            'raw_payload' => [
                'suggestionScan' => [
                    'scanType' => 'suggestions',
                    'observedSuggestions' => [
                        ['username' => 'deep_payload_seed', 'displayName' => 'Payload Seed'],
                    ],
                ],
            ],
            'analyzed_at' => now('UTC')->subMinute(),
        ]);
        TrackedPersonInstagramSuggestionScan::create([
            'tracked_person_id' => null,
            'instagram_profile_id' => $target->id,
            'user_id' => $user->id,
            'target_username' => $target->username,
            'status_level' => 'success',
            'status_message' => 'DeepSearch stored.',
            'suggestions_observed_count' => 1,
            'suggestions_checked_count' => 1,
            'suggestion_matches_count' => 0,
            'gracefully_stopped' => false,
            'raw_payload' => [
                'suggestionScan' => [
                    'scanType' => 'suggestion-deepsearch',
                    'suggestionBranchedConnections' => [
                        [
                            'sourceUsername' => 'deep_payload_seed',
                            'suggestionPreview' => [
                                ['username' => 'must_not_be_seeded_from_deepsearch'],
                            ],
                        ],
                    ],
                ],
            ],
            'analyzed_at' => now('UTC'),
        ]);

        $method = new ReflectionMethod(app(TrackedPersonInstagramSuggestionScanService::class), 'buildDeepSearchSeedProfiles');
        $method->setAccessible(true);
        $seeds = $method->invoke(
            app(TrackedPersonInstagramSuggestionScanService::class),
            null,
            $target,
            $target->username,
        );

        $this->assertSame(
            ['deep_graph_seed', 'deep_payload_seed'],
            collect($seeds)->pluck('username')->sort()->values()->all(),
        );
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

    public function test_public_suggestion_list_hits_are_stored_as_normal_inferred_connections_with_suggestion_origin(): void
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

        $storePublicInferred = new ReflectionMethod($service, 'storeInferredPublicListConnections');
        $storePublicInferred->setAccessible(true);
        $storePublicInferred->invoke($service, $person, null, 'public_rule_target', [$connection], $seenAt);

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

        foreach (['follows_target', 'followed_by_target'] as $relationshipType) {
            $inferredConnection = TrackedPersonInstagramInferredConnection::query()
                ->where('tracked_person_id', $person->id)
                ->whereNull('public_profile_id')
                ->where('relationship_type', $relationshipType)
                ->where('candidate_username', 'public_candidate_hit')
                ->firstOrFail();

            $this->assertTrue((bool) data_get($inferredConnection->evidence, 'fromSuggestionScan'));
            $this->assertSame(
                'public_lists_from_suggestion_scan',
                data_get($inferredConnection->evidence, 'relationship_origin'),
            );
            $this->assertContains('suggestion_scan_public_lists', $inferredConnection->source_lists);
        }

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

    public function test_suggestion_resume_uses_pending_candidate_queue_in_original_order(): void
    {
        $user = User::factory()->create();
        $person = TrackedPerson::create([
            'user_id' => $user->id,
            'first_name' => 'Resume',
            'last_name' => 'Target',
            'instagram_username' => 'resume_target',
        ]);
        $scan = TrackedPersonInstagramSuggestionScan::create([
            'tracked_person_id' => $person->id,
            'user_id' => $user->id,
            'target_username' => 'resume_target',
            'status_level' => 'partial',
            'status_message' => 'Pausiert',
            'suggestions_observed_count' => 4,
            'suggestions_checked_count' => 2,
            'suggestion_matches_count' => 0,
            'gracefully_stopped' => true,
            'raw_payload' => [
                'suggestionScan' => [
                    'candidatesToCheck' => [
                        ['username' => 'alpha_done', 'profileUrl' => 'https://www.instagram.com/alpha_done/'],
                        ['username' => 'beta_retry', 'profileUrl' => 'https://www.instagram.com/beta_retry/'],
                        ['username' => 'gamma_next', 'profileUrl' => 'https://www.instagram.com/gamma_next/'],
                        ['username' => 'delta_next', 'profileUrl' => 'https://www.instagram.com/delta_next/'],
                    ],
                    'checkedCandidates' => [
                        ['username' => 'alpha_done', 'checked' => true],
                        [
                            'username' => 'beta_retry',
                            'checked' => false,
                            'skipped' => true,
                            'skippedReason' => 'candidate-http-429',
                        ],
                    ],
                ],
            ],
            'analyzed_at' => now('UTC'),
        ]);

        $method = new ReflectionMethod(app(TrackedPersonInstagramSuggestionScanService::class), 'buildResumePendingFromLastScan');
        $method->setAccessible(true);
        [$resumePendingOnly, $pendingCandidates] = $method->invoke(
            app(TrackedPersonInstagramSuggestionScanService::class),
            $scan,
        );

        $this->assertTrue($resumePendingOnly);
        $this->assertSame(
            ['beta_retry', 'gamma_next', 'delta_next'],
            array_column($pendingCandidates, 'username'),
        );
    }
}
