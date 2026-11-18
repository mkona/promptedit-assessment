<?php

namespace App\Models;

use App\Enums\CreditTransactionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only credit ledger in nanodollars. A user's balance is the sum of their amounts.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $generation_id
 * @property CreditTransactionType $type
 * @property int $amount
 */
#[Fillable(['user_id', 'generation_id', 'type', 'amount'])]
class CreditTransaction extends Model
{
    protected function casts(): array
    {
        return [
            'type' => CreditTransactionType::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
