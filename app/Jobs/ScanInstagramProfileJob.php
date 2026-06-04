<?php

namespace App\Jobs;

use App\Models\InstagramProfile;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ScanInstagramProfileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $profileId;
    protected int $userId;

    /**
     * Erstelle einen neuen Job.
     */
    public function __construct(int $profileId, int $userId)
    {
        $this->profileId = $profileId;
        $this->userId = $userId;
        
        // Set retry policy
        $this->tries = 3;
        $this->timeout = 300; // 5 minutes
        $this->backoff = [60, 120, 300]; // Progressive backoff
    }

    /**
     * Führe den Job aus - Scan Instagram Profil und fetch Followers/Following.
     */
    public function handle(): void
    {
        try {
            $profile = InstagramProfile::find($this->profileId);
            if (!$profile) {
                $this->fail(new \Exception('InstagramProfile not found: ' . $this->profileId));
                return;
            }

            $user = User::find($this->userId);
            if (!$user) {
                $this->fail(new \Exception('User not found: ' . $this->userId));
                return;
            }

            // TODO: Implement actual Instagram profile scanning here
            // This would typically:
            // 1. Fetch the profile data from Instagram
            // 2. Update follower/following counts
            // 3. Create or update InstagramProfileRelationship records
            // 4. Store profile images
            
            // For now, just update the last_scanned_at timestamp
            $profile->update([
                'last_scanned_at' => now(),
            ]);

            // Log the scan activity
            activity()
                ->causedBy($user)
                ->performedOn($profile)
                ->log('Scanned Instagram profile: ' . $profile->display_handle);

        } catch (\Exception $e) {
            // Log the error and fail the job
            \Log::error('ScanInstagramProfileJob failed', [
                'profile_id' => $this->profileId,
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
            ]);
            
            $this->fail($e);
        }
    }

    /**
     * Behandle einen gescheiterten Job.
     */
    public function failed(\Throwable $exception): void
    {
        \Log::error('ScanInstagramProfileJob permanently failed', [
            'profile_id' => $this->profileId,
            'user_id' => $this->userId,
            'error' => $exception->getMessage(),
        ]);
    }
}
