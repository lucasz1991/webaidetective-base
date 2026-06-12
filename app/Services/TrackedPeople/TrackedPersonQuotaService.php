<?php

namespace App\Services\TrackedPeople;

use App\Models\Plan;
use App\Models\User;

class TrackedPersonQuotaService
{
    public function maxProfiles(User $user): ?int
    {
        $subscription = $user->activeSubscription()
            ->with('plan')
            ->first();
        $maxProfiles = $subscription?->plan?->max_profiles
            ?? Plan::query()->orderBy('priority_level')->value('max_profiles');

        return is_numeric($maxProfiles) ? max(0, (int) $maxProfiles) : null;
    }

    public function assertCanCreate(User $user): void
    {
        $maxProfiles = $this->maxProfiles($user);

        if ($maxProfiles === null || $user->trackedPeople()->count() < $maxProfiles) {
            return;
        }

        throw new \RuntimeException(
            'Das Limit von '.number_format($maxProfiles, 0, ',', '.').' beobachteten Profilen ist erreicht.',
        );
    }
}
