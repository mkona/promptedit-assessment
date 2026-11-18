<?php

namespace App\Jobs;

use App\Actions\SyncGeneration;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Sends a generation to Pixelforge. Retries are safe because every attempt reuses the
 * generation's uuid as the Idempotency-Key, so Pixelforge never creates or bills it twice.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    private const MAX_BACKOFF_SECONDS = 30;

    public function __construct(public Generation $generation) {}

    public function handle(PixelforgeClient $pixelforge, SyncGeneration $sync): void
    {
        if ($this->generation->fresh()?->status !== GenerationStatus::Submitting) {
            return;
        }

        try {
            $object = $pixelforge->createGeneration($this->generation->toProviderRequest(), $this->generation->uuid);
        } catch (PixelforgeException $e) {
            if ($e->isRetryable() && $this->attempts() < $this->tries) {
                $this->release($this->retryDelay($e->retryAfter));

                return;
            }

            $sync->failBeforeSubmission($this->generation, $e->errorCode, $this->userMessage($e));

            return;
        } catch (ConnectionException) {
            if ($this->attempts() < $this->tries) {
                $this->release($this->retryDelay(null));

                return;
            }

            $sync->failBeforeSubmission($this->generation, 'connection_failed', 'We could not reach the image provider. You were not charged.');

            return;
        }

        $sync->fromProvider($object);
    }

    public function failed(?Throwable $exception): void
    {
        app(SyncGeneration::class)->failBeforeSubmission(
            $this->generation,
            'submission_failed',
            'Something went wrong while starting this generation. You were not charged.',
        );
    }

    private function retryDelay(?int $retryAfter): int
    {
        $exponential = min(self::MAX_BACKOFF_SECONDS, 2 ** $this->attempts());

        return max($retryAfter ?? 0, $exponential) + random_int(0, 2);
    }

    private function userMessage(PixelforgeException $e): string
    {
        return match ($e->errorCode) {
            'content_policy_violation' => 'This prompt was rejected by the content policy. You were not charged.',
            'daily_limit_exceeded' => 'The daily generation limit has been reached. Please try again tomorrow. You were not charged.',
            'rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded' => 'The image provider stayed busy for too long. Please try again. You were not charged.',
            default => $e->getMessage().' You were not charged.',
        };
    }
}
