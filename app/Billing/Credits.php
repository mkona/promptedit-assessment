<?php

namespace App\Billing;

use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;

/**
 * The credit ledger. When a generation is submitted we hold its maximum possible price,
 * then release the hold and charge the exact price once it finishes. Failed generations
 * only release the hold, so they cost nothing.
 */
class Credits
{
    public function balance(User $user): int
    {
        return (int) $user->creditTransactions()->sum('amount');
    }

    public function purchase(User $user, int $amount): void
    {
        $user->creditTransactions()->create([
            'type' => CreditTransactionType::Purchase,
            'amount' => $amount,
        ]);
    }

    public function hold(Generation $generation): void
    {
        $this->record($generation, CreditTransactionType::Hold, -$generation->max_price);
    }

    /**
     * Safe to call more than once: each ledger entry is unique per generation and type.
     */
    public function settle(Generation $generation): void
    {
        $this->record($generation, CreditTransactionType::HoldRelease, $generation->max_price);

        if ($generation->status === GenerationStatus::Succeeded) {
            $this->record($generation, CreditTransactionType::Charge, -(int) $generation->price);
        }
    }

    private function record(Generation $generation, CreditTransactionType $type, int $amount): void
    {
        CreditTransaction::query()->insertOrIgnore([
            'user_id' => $generation->user_id,
            'generation_id' => $generation->id,
            'type' => $type->value,
            'amount' => $amount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
