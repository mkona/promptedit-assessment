<?php

namespace App\Pixelforge;

/**
 * Snapshot of GET /v1/pricing.
 *
 * @phpstan-type Rates array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string}
 * @phpstan-type Tokens array{
 *     characters_per_text_token: int,
 *     text_token_variance: float,
 *     per_reference_image: int,
 *     output: array<string, array<string, int>>,
 *     reasoning: array<string, array{min: int, max: int}>,
 * }
 */
final readonly class Pricing
{
    /**
     * @param  Rates  $rates
     * @param  Tokens  $tokens
     */
    public function __construct(
        public array $rates,
        public array $tokens,
    ) {}

    /** @param array<string, mixed> $response */
    public static function fromResponse(array $response): self
    {
        return new self($response['rates'], $response['tokens']);
    }

    public function charactersPerTextToken(): int
    {
        return (int) $this->tokens['characters_per_text_token'];
    }

    public function textTokenVarianceBasisPoints(): int
    {
        return (int) round(((float) $this->tokens['text_token_variance']) * 10_000);
    }

    public function tokensPerReferenceImage(): int
    {
        return (int) $this->tokens['per_reference_image'];
    }

    public function outputTokens(string $quality, string $size): int
    {
        return (int) $this->tokens['output'][$quality][$size];
    }

    /** @return array{min: int, max: int} */
    public function reasoningTokens(string $quality): array
    {
        $range = $this->tokens['reasoning'][$quality];

        return ['min' => (int) $range['min'], 'max' => (int) $range['max']];
    }

    /** @return list<string> */
    public function sizes(): array
    {
        return array_keys($this->tokens['output']['medium']);
    }

    /** @return list<string> */
    public function qualities(): array
    {
        return array_keys($this->tokens['output']);
    }
}
