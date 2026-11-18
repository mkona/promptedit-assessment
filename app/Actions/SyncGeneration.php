<?php

namespace App\Actions;

use App\Billing\CostCalculator;
use App\Billing\Credits;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies a Pixelforge generation object (from the create response, a webhook or polling) to our record.
 *
 * Updates can arrive twice or out of order, so a generation only ever moves forward
 * (submitting → queued → processing → succeeded | failed) and is billed exactly once,
 * on the transition into a final status.
 */
class SyncGeneration
{
    public function __construct(
        private readonly CostCalculator $calculator,
        private readonly Credits $credits,
    ) {}

    /** @param array<string, mixed> $object */
    public function fromProvider(array $object): ?Generation
    {
        return DB::transaction(function () use ($object) {
            $generation = $this->findForUpdate($object);

            if ($generation === null) {
                Log::warning('Pixelforge update for an unknown generation.', ['id' => $object['id'] ?? null]);

                return null;
            }

            $generation->provider_id ??= $object['id'];
            $generation->rates ??= $object['rates'] ?? null;

            $incoming = GenerationStatus::from($object['status']);

            if (! $incoming->isAfter($generation->status)) {
                $this->refreshImageUrl($generation, $object);
                $generation->save();

                return $generation;
            }

            $generation->status = $incoming;

            match ($incoming) {
                GenerationStatus::Succeeded => $this->markSucceeded($generation, $object),
                GenerationStatus::Failed => $this->markFailed(
                    $generation,
                    $object['error']['code'] ?? 'generation_failed',
                    $object['error']['message'] ?? 'The generation failed.',
                    Date::parse($object['completed_at'] ?? now()),
                ),
                default => $generation->save(),
            };

            return $generation;
        });
    }

    /**
     * For requests Pixelforge never accepted (rejected prompt, gave up retrying, ...).
     */
    public function failBeforeSubmission(Generation $generation, string $code, string $message): void
    {
        DB::transaction(function () use ($generation, $code, $message) {
            $generation = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            if ($generation->status !== GenerationStatus::Submitting) {
                return;
            }

            $generation->status = GenerationStatus::Failed;
            $this->markFailed($generation, $code, $message, now());
        });
    }

    /** @param array<string, mixed> $object */
    private function markSucceeded(Generation $generation, array $object): void
    {
        $generation->usage = $object['usage'];
        $generation->provider_cost = $this->calculator->providerCost($object['usage'], $object['rates']);
        $generation->price = $this->calculator->price($generation->provider_cost);
        $generation->completed_at = Date::parse($object['completed_at']);
        $this->refreshImageUrl($generation, $object);
        $generation->save();

        $this->credits->settle($generation);
    }

    private function markFailed(Generation $generation, string $code, string $message, CarbonImmutable $completedAt): void
    {
        $generation->error_code = $code;
        $generation->error_message = $message;
        $generation->provider_cost = 0;
        $generation->price = 0;
        $generation->completed_at = $completedAt;
        $generation->save();

        $this->credits->settle($generation);
    }

    /** @param array<string, mixed> $object */
    private function refreshImageUrl(Generation $generation, array $object): void
    {
        if (! empty($object['image_url'])) {
            $generation->image_url = $object['image_url'];
            $generation->image_url_expires_at = Date::parse($object['image_url_expires_at']);
        }
    }

    /** @param array<string, mixed> $object */
    private function findForUpdate(array $object): ?Generation
    {
        $uuid = $object['metadata']['generation_uuid'] ?? null;

        return Generation::query()
            ->where(fn ($query) => $query
                ->where('provider_id', $object['id'])
                ->when($uuid, fn ($query) => $query->orWhere('uuid', $uuid)))
            ->lockForUpdate()
            ->first();
    }
}
