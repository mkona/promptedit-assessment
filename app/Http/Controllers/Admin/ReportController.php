<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GenerationStatus;
use App\Http\Controllers\Controller;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use App\Support\Nanodollars;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Spend, provider cost and profit per UTC day and user. Days follow completed_at,
 * because Pixelforge bills a generation on the day it completed.
 */
class ReportController extends Controller
{
    public function __invoke(Request $request, PixelforgeClient $pixelforge): Response
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $to = Date::parse($validated['to'] ?? now('UTC')->toDateString(), 'UTC');
        $from = Date::parse($validated['from'] ?? $to->subDays(13)->toDateString(), 'UTC');

        $rows = DB::table('generations')
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->where('generations.status', GenerationStatus::Succeeded->value)
            ->whereBetween('generations.completed_at', [$from->startOfDay(), $to->endOfDay()])
            ->groupBy(DB::raw('date(generations.completed_at)'), 'users.id', 'users.name', 'users.email')
            ->orderByDesc(DB::raw('date(generations.completed_at)'))
            ->select([
                DB::raw('date(generations.completed_at) as day'),
                'users.id as user_id',
                'users.name as user_name',
                'users.email as user_email',
                DB::raw('count(*) as generations'),
                DB::raw('sum(generations.price) as spend'),
                DB::raw('sum(generations.provider_cost) as cost'),
            ])
            ->get();

        $providerUsage = $this->providerUsage($pixelforge, $from->toDateString(), $to->toDateString());

        $days = $rows->groupBy(fn ($row) => (string) $row->day)->map(function (Collection $users, string $day) use ($providerUsage) {
            $spend = (int) $users->sum('spend');
            $cost = (int) $users->sum('cost');
            $reported = $providerUsage[$day] ?? null;

            return [
                'day' => $day,
                'generations' => (int) $users->sum('generations'),
                'spend' => Nanodollars::toDecimal($spend),
                'cost' => Nanodollars::toDecimal($cost),
                'profit' => Nanodollars::toDecimal($spend - $cost),
                'provider_reported_cost' => $reported === null ? null : Nanodollars::toDecimal($reported),
                'matches_provider' => $reported === null ? null : $reported === $cost,
                'users' => $users->map(fn ($row) => [
                    'id' => $row->user_id,
                    'name' => $row->user_name,
                    'email' => $row->user_email,
                    'generations' => (int) $row->generations,
                    'spend' => Nanodollars::toDecimal((int) $row->spend),
                    'cost' => Nanodollars::toDecimal((int) $row->cost),
                    'profit' => Nanodollars::toDecimal((int) $row->spend - (int) $row->cost),
                ])->values(),
            ];
        });

        // Days Pixelforge billed that we have no record of are mismatches too.
        foreach ($providerUsage ?? [] as $day => $reported) {
            if ($reported > 0 && ! $days->has($day)) {
                $days->put($day, [
                    'day' => $day,
                    'generations' => 0,
                    'spend' => Nanodollars::toDecimal(0),
                    'cost' => Nanodollars::toDecimal(0),
                    'profit' => Nanodollars::toDecimal(0),
                    'provider_reported_cost' => Nanodollars::toDecimal($reported),
                    'matches_provider' => false,
                    'users' => [],
                ]);
            }
        }

        $days = $days->sortKeysDesc()->values();

        $totalSpend = (int) $rows->sum('spend');
        $totalCost = (int) $rows->sum('cost');

        return Inertia::render('admin/Report', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $days,
            'totals' => [
                'generations' => (int) $rows->sum('generations'),
                'spend' => Nanodollars::toDecimal($totalSpend),
                'cost' => Nanodollars::toDecimal($totalCost),
                'profit' => Nanodollars::toDecimal($totalSpend - $totalCost),
                'provider_reported_cost' => $providerUsage === null ? null : Nanodollars::toDecimal(array_sum($providerUsage)),
            ],
            'providerUnavailable' => $providerUsage === null,
        ]);
    }

    /** @return array<string, int>|null Pixelforge's billed cost per day, or null if it couldn't be fetched. */
    private function providerUsage(PixelforgeClient $pixelforge, string $from, string $to): ?array
    {
        try {
            $usage = $pixelforge->usage($from, $to);
        } catch (PixelforgeException|ConnectionException) {
            return null;
        }

        $costs = [];

        foreach (is_array($usage['data'] ?? null) ? $usage['data'] : [] as $day) {
            if (is_array($day) && is_string($day['date'] ?? null) && is_string($day['cost_usd'] ?? null)) {
                $costs[$day['date']] = Nanodollars::fromDecimal($day['cost_usd']);
            }
        }

        return $costs;
    }
}
