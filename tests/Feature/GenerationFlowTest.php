<?php

use App\Billing\Credits;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Support\Nanodollars;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

const WEBHOOK_SECRET = 'whsec_test';

beforeEach(function () {
    config(['services.pixelforge.webhook_secret' => WEBHOOK_SECRET, 'services.pixelforge.api_key' => 'pf_test']);

    Http::preventStrayRequests();
    Http::fake([
        '*/v1/pricing' => Http::response(json_decode(file_get_contents(__DIR__.'/../fixtures/pricing.json'), true)),
    ]);
});

function userWithCredits(int $dollars = 5): User
{
    $user = User::factory()->create();
    app(Credits::class)->purchase($user, $dollars * Nanodollars::PER_DOLLAR);

    return $user;
}

/** @return array<string, mixed> */
function providerObject(Generation $generation, string $status, array $overrides = []): array
{
    return array_merge([
        'id' => 'gen_test_'.$generation->id,
        'object' => 'generation',
        'status' => $status,
        'prompt' => $generation->prompt,
        'size' => $generation->size,
        'quality' => $generation->quality,
        'reference_images' => $generation->reference_images,
        'metadata' => ['generation_uuid' => $generation->uuid],
        'image_url' => null,
        'image_url_expires_at' => null,
        'usage' => null,
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'error' => null,
        'created_at' => '2026-11-18T10:00:00Z',
        'started_at' => null,
        'completed_at' => null,
    ], $overrides);
}

function succeededObject(Generation $generation): array
{
    return providerObject($generation, 'succeeded', [
        'usage' => ['input_text_tokens' => 12, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 800, 'total_tokens' => 1912],
        'image_url' => 'https://pixelforge.promptedit.dev/images/x.png',
        'image_url_expires_at' => now()->addDay()->toIso8601String(),
        'completed_at' => '2026-11-18T10:00:20Z',
    ]);
}

function sendWebhook(string $eventId, array $object, ?string $secret = WEBHOOK_SECRET)
{
    $body = json_encode([
        'id' => $eventId,
        'object' => 'event',
        'type' => 'generation.'.$object['status'],
        'created_at' => now()->toIso8601String(),
        'data' => ['object' => $object],
    ]);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

    return test()->call('POST', '/webhooks/pixelforge', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
    ], $body);
}

function submittedGeneration(User $user): Generation
{
    Queue::fake();

    test()->actingAs($user)->post('/generations', [
        'prompt' => 'A lighthouse on a cliff at dawn',
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => [],
    ])->assertSessionHasNoErrors();

    return Generation::sole();
}

it('holds the maximum price when a generation is submitted', function () {
    $user = userWithCredits();
    $generation = submittedGeneration($user);

    expect($generation->status)->toBe(GenerationStatus::Submitting)
        ->and(app(Credits::class)->balance($user))->toBe(5 * Nanodollars::PER_DOLLAR - $generation->max_price);
    Queue::assertPushed(SubmitGeneration::class);
});

it('refuses to generate without enough credits', function () {
    Queue::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->post('/generations', [
        'prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'high',
    ])->assertSessionHasErrors('prompt');

    expect(Generation::count())->toBe(0);
});

it('charges the exact marked-up cost once, even when the event is delivered twice', function () {
    $user = userWithCredits();
    $generation = submittedGeneration($user);
    $object = succeededObject($generation);

    sendWebhook('evt_1', $object)->assertNoContent();
    sendWebhook('evt_1', $object)->assertNoContent();

    $generation->refresh();
    // 12×5 + 1100×40 + 800×12.5 = 54,060 USD per 1M tokens = $0.05406, plus 25%.
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and(Nanodollars::toDecimal($generation->provider_cost))->toBe('0.054060000')
        ->and(Nanodollars::toDecimal($generation->price))->toBe('0.067575000')
        ->and(app(Credits::class)->balance($user))->toBe(5 * Nanodollars::PER_DOLLAR - $generation->price);
});

it('never moves a finished generation backwards', function () {
    $user = userWithCredits();
    $generation = submittedGeneration($user);

    sendWebhook('evt_done', succeededObject($generation))->assertNoContent();
    sendWebhook('evt_late', providerObject($generation, 'processing'))->assertNoContent();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded);
});

it('does not charge for failed generations', function () {
    $user = userWithCredits();
    $generation = submittedGeneration($user);

    sendWebhook('evt_fail', providerObject($generation, 'failed', [
        'error' => ['code' => 'generation_failed', 'message' => 'No image.'],
        'completed_at' => '2026-11-18T10:00:20Z',
    ]))->assertNoContent();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->price)->toBe(0)
        ->and(app(Credits::class)->balance($user))->toBe(5 * Nanodollars::PER_DOLLAR);
});

it('matches a webhook that arrives before the create request returned', function () {
    $user = userWithCredits();
    $generation = submittedGeneration($user);

    sendWebhook('evt_early', providerObject($generation, 'processing'))->assertNoContent();

    expect($generation->refresh()->provider_id)->toBe('gen_test_'.$generation->id)
        ->and($generation->status)->toBe(GenerationStatus::Processing);
});

it('rejects webhooks with a bad signature', function () {
    $user = userWithCredits();
    $generation = submittedGeneration($user);

    sendWebhook('evt_forged', succeededObject($generation), 'whsec_wrong')->assertStatus(400);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Submitting);
});

it('submits with an idempotency key and records the queued generation', function () {
    $user = userWithCredits();
    $generation = submittedGeneration($user);

    Http::fake(['*/v1/generations' => Http::response(providerObject($generation, 'queued'), 202)]);

    app()->call([new SubmitGeneration($generation), 'handle']);

    Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', $generation->uuid)
        && $request['metadata']['generation_uuid'] === $generation->uuid);
    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
});

it('fails a rejected prompt without charging', function () {
    $user = userWithCredits();
    $generation = submittedGeneration($user);

    Http::fake(['*/v1/generations' => Http::response(['error' => [
        'type' => 'invalid_request_error', 'code' => 'content_policy_violation', 'message' => 'The prompt was rejected.',
    ]], 400)]);

    app()->call([new SubmitGeneration($generation), 'handle']);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('content_policy_violation')
        ->and(app(Credits::class)->balance($user))->toBe(5 * Nanodollars::PER_DOLLAR);
});
