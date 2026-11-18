<?php

namespace App\Console\Commands;

use App\Actions\SyncGeneration;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;

/**
 * Webhook delivery isn't guaranteed, so generations that have been quiet for a while are polled.
 */
#[Signature('generations:reconcile {--limit=20}')]
#[Description('Poll Pixelforge for generations that have not reached a final status')]
class ReconcileGenerations extends Command
{
    public function handle(PixelforgeClient $pixelforge, SyncGeneration $sync): int
    {
        $stale = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('provider_id')
            ->where('updated_at', '<', now()->subMinute())
            ->oldest('updated_at')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($stale as $generation) {
            try {
                $sync->fromProvider($pixelforge->getGeneration($generation->provider_id));
            } catch (PixelforgeException|ConnectionException $e) {
                $this->warn("{$generation->provider_id}: {$e->getMessage()}");
            }

            $generation->touch();
        }

        $this->info("Checked {$stale->count()} generation(s).");

        return self::SUCCESS;
    }
}
