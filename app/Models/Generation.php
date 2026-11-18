<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\CarbonImmutable;

/**
 * Money columns are integer nanodollars. "cost" is what Pixelforge charges us, "price" is what the user pays.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string|null $provider_id
 * @property GenerationStatus $status
 * @property string $prompt
 * @property string $size
 * @property string $quality
 * @property list<string> $reference_images
 * @property array<string, string>|null $rates
 * @property array<string, int>|null $usage
 * @property int $estimated_cost
 * @property int $estimated_price
 * @property int $max_price
 * @property int|null $provider_cost
 * @property int|null $price
 * @property string|null $image_url
 * @property CarbonImmutable|null $image_url_expires_at
 * @property string|null $error_code
 * @property string|null $error_message
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'uuid', 'user_id', 'status', 'prompt', 'size', 'quality', 'reference_images',
    'estimated_cost', 'estimated_price', 'max_price',
])]
class Generation extends Model
{
    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'reference_images' => 'array',
            'rates' => 'array',
            'usage' => 'array',
            'image_url_expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, mixed> */
    public function toProviderRequest(): array
    {
        return array_filter([
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images ?: null,
            'metadata' => [
                'generation_uuid' => $this->uuid,
                'user_id' => (string) $this->user_id,
            ],
        ]);
    }

    public function hasLiveImage(): bool
    {
        return $this->image_url !== null && $this->image_url_expires_at?->isFuture() === true;
    }
}
