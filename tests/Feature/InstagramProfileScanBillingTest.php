<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\CreditWallet;
use App\Models\InstagramProfile;
use App\Models\InstagramProfileScan;
use App\Models\TrackedPersonInstagramSuggestionScan;
use App\Models\User;
use App\Services\Billing\ScanCreditService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
}
