<?php

namespace App\Http\Resources;

use App\Models\Generation;
use App\Support\Nanodollars;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Generation */
class GenerationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,
            'estimated_price' => Nanodollars::toDecimal($this->estimated_price),
            'max_price' => Nanodollars::toDecimal($this->max_price),
            'price' => $this->price === null ? null : Nanodollars::toDecimal($this->price),
            'image_url' => $this->hasLiveImage() ? $this->image_url : null,
            'image_expired' => $this->image_url !== null && ! $this->hasLiveImage(),
            'error_message' => $this->error_message,
            'created_at' => $this->created_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
