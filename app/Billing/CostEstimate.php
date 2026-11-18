<?php

namespace App\Billing;

use App\Support\Nanodollars;

final readonly class CostEstimate
{
    public function __construct(
        public int $expectedCost,
        public int $expectedPrice,
        public int $minimumPrice,
        public int $maximumPrice,
    ) {}

    /** @return array{expected: string, minimum: string, maximum: string} */
    public function toArray(): array
    {
        return [
            'expected' => Nanodollars::toDecimal($this->expectedPrice),
            'minimum' => Nanodollars::toDecimal($this->minimumPrice),
            'maximum' => Nanodollars::toDecimal($this->maximumPrice),
        ];
    }
}
