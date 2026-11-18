<?php

namespace App\Http\Controllers;

use App\Actions\SyncGeneration;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class PixelforgeWebhookController extends Controller
{
    private const TOLERANCE_SECONDS = 300;

    public function __invoke(Request $request, SyncGeneration $sync): Response
    {
        $payload = $request->getContent();

        abort_unless($this->hasValidSignature($payload, (string) $request->header('Pixelforge-Signature')), 400, 'Invalid Pixelforge signature.');

        $event = json_decode($payload, true);

        abort_unless(is_array($event) && isset($event['id'], $event['type']), 400, 'Malformed event.');

        if ($event['type'] === 'ping') {
            return response()->noContent();
        }

        // Recording the event and applying it share one transaction: if applying fails,
        // the event isn't marked as seen, so Pixelforge's retry is processed.
        DB::transaction(function () use ($event, $sync) {
            $isNew = WebhookEvent::query()->insertOrIgnore([
                'id' => $event['id'],
                'type' => $event['type'],
                'generation_id' => $event['data']['object']['id'] ?? null,
                'payload' => json_encode($event),
                'received_at' => now(),
            ]) === 1;

            if ($isNew && isset($event['data']['object']['status'])) {
                $sync->fromProvider($event['data']['object']);
            }
        });

        return response()->noContent();
    }

    private function hasValidSignature(string $payload, string $header): bool
    {
        $parts = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key] = $value;
        }

        $timestamp = (int) ($parts['t'] ?? 0);
        $secret = (string) config('services.pixelforge.webhook_secret');

        if ($secret === '' || abs(time() - $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', "{$timestamp}.{$payload}", $secret), $parts['v1'] ?? '');
    }
}
