<?php

namespace App\Services\Billing;

use App\Models\CreditTransaction;
use App\Models\CreditWallet;
use App\Services\Support\DatabaseKeepAlive;
use Illuminate\Database\Eloquent\Model;

class ScanCreditService
{
    public function charge(int $userId, Model $reference, array $payload, string $description): int
    {
        $credits = max(0, (int) data_get($payload, 'billing.totalCredits', 0));

        if ($userId <= 0 || ! $reference->exists || $credits === 0) {
            return 0;
        }

        return DatabaseKeepAlive::transaction(function () use ($userId, $reference, $credits, $description): int {
            $wallet = CreditWallet::query()->firstOrCreate(
                ['user_id' => $userId],
                [
                    'available_credits' => 0,
                    'reserved_credits' => 0,
                    'used_credits' => 0,
                    'bonus_credits' => 0,
                ],
            );
            $wallet = CreditWallet::query()->lockForUpdate()->findOrFail($wallet->id);

            $alreadyCharged = CreditTransaction::query()
                ->where('user_id', $userId)
                ->where('type', CreditTransaction::TYPE_SCAN)
                ->where('reference_type', $reference->getMorphClass())
                ->where('reference_id', $reference->getKey())
                ->exists();

            if ($alreadyCharged) {
                return 0;
            }

            $fromAvailable = min($credits, (int) $wallet->available_credits);
            $remaining = $credits - $fromAvailable;
            $fromBonus = min($remaining, (int) $wallet->bonus_credits);

            $wallet->forceFill([
                'available_credits' => (int) $wallet->available_credits - $fromAvailable,
                'bonus_credits' => (int) $wallet->bonus_credits - $fromBonus,
                'used_credits' => (int) $wallet->used_credits + $credits,
            ])->save();

            CreditTransaction::create([
                'user_id' => $userId,
                'amount' => -$credits,
                'type' => CreditTransaction::TYPE_SCAN,
                'description' => $description,
                'reference_type' => $reference->getMorphClass(),
                'reference_id' => $reference->getKey(),
            ]);

            return $credits;
        });
    }
}
