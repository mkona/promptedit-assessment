<?php

namespace App\Pixelforge;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PixelforgeClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
    ) {}

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed> The generation object.
     */
    public function createGeneration(array $parameters, string $idempotencyKey): array
    {
        return $this->send(
            $this->request()->withHeaders(['Idempotency-Key' => $idempotencyKey])->post('generations', $parameters)
        );
    }

    /** @return array<string, mixed> The generation object. */
    public function getGeneration(string $id): array
    {
        return $this->send($this->request()->get("generations/{$id}"));
    }

    /** @return array<string, mixed> */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->send($this->request()->get('usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]));
    }

    public function pricing(): Pricing
    {
        $response = Cache::remember('pixelforge.pricing', now()->addMinutes(10), fn () => $this->send(
            Http::baseUrl($this->baseUrl)->acceptJson()->timeout(10)->get('pricing')
        ));

        return Pricing::fromResponse($response);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken((string) $this->apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(15);
    }

    /** @return array<string, mixed> */
    private function send(Response $response): array
    {
        if ($response->successful()) {
            return $response->json();
        }

        $retryAfter = $response->header('Retry-After');

        throw new PixelforgeException(
            status: $response->status(),
            errorCode: (string) $response->json('error.code', 'unknown_error'),
            message: (string) $response->json('error.message', "Pixelforge returned HTTP {$response->status()}."),
            retryAfter: is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }
}
