<?php

use App\Billing\CostCalculator;
use App\Pixelforge\Pricing;
use App\Support\Nanodollars;

function pricingFixture(): Pricing
{
    return Pricing::fromResponse(json_decode(file_get_contents(__DIR__.'/../fixtures/pricing.json'), true));
}

it('converts between decimal strings and nanodollars without loss', function () {
    expect(Nanodollars::fromDecimal('0.046125000'))->toBe(46_125_000)
        ->and(Nanodollars::fromDecimal('12.5'))->toBe(12_500_000_000)
        ->and(Nanodollars::toDecimal(1))->toBe('0.000000001')
        ->and(Nanodollars::toDecimal(-373_875_000))->toBe('-0.373875000');
});

it('rounds a markup fraction of a nanodollar up', function () {
    expect(Nanodollars::withMarkup(1, 25))->toBe(2)
        ->and(Nanodollars::withMarkup(4, 25))->toBe(5)
        ->and(Nanodollars::withMarkup(305_510_000, 25))->toBe(381_887_500);
});

it('computes the exact provider cost of the documented succeeded generation', function () {
    $cost = (new CostCalculator(25))->providerCost(
        ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184],
        ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
    );

    expect(Nanodollars::toDecimal($cost))->toBe('0.305510000');
});

it('matches the documented worst case estimate', function () {
    $estimate = (new CostCalculator(0))->estimate(pricingFixture(), 400, '1536x1024', 'high', 2);

    expect(Nanodollars::toDecimal($estimate->maximumPrice))->toBe('0.373875000');
});

it('keeps the expected price between the minimum and the maximum', function () {
    $estimate = (new CostCalculator(25))->estimate(pricingFixture(), 120, '1024x1024', 'medium', 0);

    expect($estimate->minimumPrice)->toBeLessThan($estimate->expectedPrice)
        ->and($estimate->expectedPrice)->toBeLessThan($estimate->maximumPrice);
});
