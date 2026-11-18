<?php

namespace App\Billing;

use App\Pixelforge\Pricing;
use App\Support\Nanodollars;
use InvalidArgumentException;

/**
 * Converts token counts into exact nanodollar costs, and estimates costs before a generation runs.
 */
class CostCalculator
{
    public function __construct(private readonly int $markupPercent) {}

    /**
     * @param  array<string, int>  $tokens  Token counts keyed like the Pixelforge rates.
     * @param  array<string, string>  $rates  USD per 1M tokens, as exact decimal strings.
     */
    public function providerCost(array $tokens, array $rates): int
    {
        $total = '0';

        foreach (['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'] as $type) {
            $rate = $rates[$type] ?? null;

            if (! is_numeric($rate)) {
                throw new InvalidArgumentException("Missing or invalid rate for {$type}.");
            }

            // USD per 1M tokens × 1,000 = nanodollars per token.
            $total = bcadd($total, bcmul((string) ($tokens[$type] ?? 0), bcmul($rate, '1000', 9), 9), 9);
        }

        return $this->roundUp($total);
    }

    public function price(int $providerCost): int
    {
        return Nanodollars::withMarkup($providerCost, $this->markupPercent);
    }

    public function estimate(Pricing $pricing, int $promptCharacters, string $size, string $quality, int $referenceImages): CostEstimate
    {
        $textTokens = (int) ceil($promptCharacters / $pricing->charactersPerTextToken());
        $variance = $pricing->textTokenVarianceBasisPoints();
        $reasoning = $pricing->reasoningTokens($quality);

        $fixed = [
            'input_image_tokens' => $referenceImages * $pricing->tokensPerReferenceImage(),
            'output_tokens' => $pricing->outputTokens($quality, $size),
        ];

        $expected = $this->providerCost($fixed + [
            'input_text_tokens' => $textTokens,
            'reasoning_tokens' => intdiv($reasoning['min'] + $reasoning['max'], 2),
        ], $pricing->rates);

        $minimum = $this->providerCost($fixed + [
            'input_text_tokens' => intdiv($textTokens * (10_000 - $variance), 10_000),
            'reasoning_tokens' => $reasoning['min'],
        ], $pricing->rates);

        $maximum = $this->providerCost($fixed + [
            'input_text_tokens' => intdiv($textTokens * (10_000 + $variance) + 9_999, 10_000),
            'reasoning_tokens' => $reasoning['max'],
        ], $pricing->rates);

        return new CostEstimate(
            expectedCost: $expected,
            expectedPrice: $this->price($expected),
            minimumPrice: $this->price($minimum),
            maximumPrice: $this->price($maximum),
        );
    }

    /** @param numeric-string $nanos */
    private function roundUp(string $nanos): int
    {
        $whole = bcadd($nanos, '0', 0);

        return bccomp($nanos, $whole, 9) === 1 ? (int) $whole + 1 : (int) $whole;
    }
}
