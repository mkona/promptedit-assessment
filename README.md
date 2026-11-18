# PromptEdit Images

Laravel 13 + Vue 3 + Inertia app for the PromptEdit take-home: users generate images through the Pixelforge API and pay Pixelforge's cost plus a 25% markup, to the exact nanodollar.

## Running it locally

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
# set PIXELFORGE_API_KEY and PIXELFORGE_WEBHOOK_SECRET in .env
php artisan migrate
composer run dev            # web server, queue worker and Vite
php artisan schedule:work   # polls generations whose webhooks never arrived
php artisan test
```

Webhooks need a public URL. Point the key's webhook at `https://<tunnel>/webhooks/pixelforge`, for example with `cloudflared tunnel --url http://localhost:8000`.

`php artisan users:make-admin you@example.com` unlocks the admin report.

## How it works

1. **Submit.** `GenerationController@store` validates the form, estimates the price, and in one transaction (with the user's row locked) checks the balance, creates the generation and puts a hold on its maximum possible price. The `SubmitGeneration` job then calls `POST /v1/generations`.
2. **Retry safely.** The job sends the generation's UUID as the `Idempotency-Key`, so retries never create or bill a second image. Rate limits, concurrency limits, overloads and network errors are retried with exponential backoff that respects `Retry-After`, up to 10 attempts. Rejected prompts, the daily limit and other errors fail right away, free of charge.
3. **Track status.** Webhooks, the create response and polling all go through `SyncGeneration`. It locks the row and only ever moves a generation forward (submitting → queued → processing → succeeded | failed), so out-of-order and duplicate updates can't move it back or bill it twice. Each webhook is verified (HMAC-SHA256, constant-time compare, 5-minute timestamp tolerance) and stored by event id in the same transaction that applies it. A redelivered event is a no-op, and a failure rolls back so Pixelforge's retry gets processed.
4. **Match early webhooks.** Every request carries `metadata.generation_uuid`, so a webhook that arrives before the create call returns still finds its generation.
5. **Poll as a fallback.** `generations:reconcile` runs every minute and polls generations that have been quiet for over a minute, in case webhooks never arrive.

## Money

- All amounts are integer **nanodollars** (1 USD = 10⁹). Pixelforge prices have nine decimals, so this is lossless and never uses floats. Rate × tokens is computed with bcmath.
- The provider cost is computed from the generation's own `usage` and `rates`. Rates are fixed when the generation is created, so later price changes don't affect it.
- **User price = cost × 1.25.** A markup can produce fractions of a nanodollar (for example 1 × 1.25). Those are always **rounded up**, so we never charge less than cost plus markup. The difference is at most $0.000000001 per image.
- The estimate before submitting comes from `GET /v1/pricing` (cached for 10 minutes). It shows an expected price (mid-range reasoning tokens) and the possible range: the text-token ±15% variance plus the minimum and maximum reasoning tokens for the chosen quality.
- **Credits ledger.** `credit_transactions` is append-only: `purchase`, `hold` (−max price at submit), `hold_release` (+max price when finished) and `charge` (−exact price on success). The balance is the sum. A unique index on (generation, type) makes settling idempotent, and failed generations only release their hold.

## Admin report

`/admin/report` shows, per UTC day and per user, the credit spend (what users paid), the provider cost (what Pixelforge charged) and the profit. Days follow `completed_at`, because Pixelforge bills on the day a generation completes. Each day is compared with `GET /v1/usage`, and days Pixelforge billed that we have no record of are flagged too.

## Trade-offs and next steps

- Images aren't copied to our own storage, so they disappear when Pixelforge's 24-hour URL expires. Downloading them to S3 in a queued job would fix that.
- The "Buy credits" button is a fake purchase. A real one would add a Stripe Checkout session and a webhook that writes the `purchase` ledger entry.
- Live status uses Inertia polling every 2 seconds, only while something is pending. Broadcasting with Reverb would remove the polling.
