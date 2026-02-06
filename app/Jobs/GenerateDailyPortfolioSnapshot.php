<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class GenerateDailyPortfolioSnapshot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected array $dates;

    /**
     * @param string|array|null $dates Single date string or array of dates, defaults to today
     */
    public function __construct(string|array|null $dates = null)
    {
        if ($dates === null) {
            $dates = [now()->toDateString()];
        } elseif (is_string($dates)) {
            $dates = [$dates];
        }

        $this->dates = $dates;
    }

    public function handle(): void
    {
        foreach ($this->dates as $date) {
            $snapshotDate = Carbon::parse($date)->toDateString();

            DB::transaction(function () use ($snapshotDate) {

                // Prevent duplicate runs
                if (DB::table('portfolio_daily_snapshots')
                    ->where('snapshot_date', $snapshotDate)
                    ->exists()
                ) {
                    return;
                }

                // Use caching for latest prices
                $prices = Cache::remember(
                    "land_prices_as_of_{$snapshotDate}",
                    now()->addMinutes(30), // TTL for live snapshots; historical can be longer
                    function () use ($snapshotDate) {
                        return collect(DB::select("
                            SELECT lph.land_id, lph.price_per_unit_kobo
                            FROM land_price_history lph
                            JOIN (
                                SELECT land_id, MAX(price_date) AS price_date
                                FROM land_price_history
                                WHERE price_date <= ?
                                GROUP BY land_id
                            ) latest
                            ON lph.land_id = latest.land_id
                            AND lph.price_date = latest.price_date
                        ", [$snapshotDate]))->keyBy('land_id');
                    }
                );

                if ($prices->isEmpty()) return;

                // Cache user holdings
                $holdings = Cache::remember(
                    "user_holdings",
                    now()->addMinutes(10), // short TTL, can be live
                    fn() => DB::table('user_land')
                        ->select('user_id', 'land_id', 'units')
                        ->get()
                );

                if ($holdings->isEmpty()) return;

                $assetRows = [];
                $userTotals = [];

                foreach ($holdings as $row) {
                    if (!isset($prices[$row->land_id])) continue;

                    $value = $row->units * $prices[$row->land_id]->price_per_unit_kobo;

                    $assetRows[] = [
                        'user_id' => $row->user_id,
                        'land_id' => $row->land_id,
                        'snapshot_date' => $snapshotDate,
                        'units' => $row->units,
                        'value_kobo' => $value,
                        'created_at' => now(),
                    ];

                    $userTotals[$row->user_id] = ($userTotals[$row->user_id] ?? 0) + $value;
                }

                if (!empty($assetRows)) {
                    DB::table('portfolio_asset_snapshots')->insert($assetRows);
                }

                // Cache invested amounts per user as of snapshot date
                $invested = Cache::remember(
                    "user_invested_as_of_{$snapshotDate}",
                    now()->addMinutes(30),
                    fn() => DB::table('purchases')
                        ->select(
                            'user_id',
                            DB::raw('SUM(total_amount_paid_kobo - total_amount_received_kobo) AS invested')
                        )
                        ->whereDate('purchase_date', '<=', $snapshotDate)
                        ->groupBy('user_id')
                        ->get()
                        ->keyBy('user_id')
                );

                $dailyRows = [];
                foreach ($userTotals as $userId => $totalValue) {
                    $investedAmount = $invested[$userId]->invested ?? 0;

                    $dailyRows[] = [
                        'user_id' => $userId,
                        'snapshot_date' => $snapshotDate,
                        'total_invested_kobo' => $investedAmount,
                        'total_value_kobo' => $totalValue,
                        'total_profit_kobo' => $totalValue - $investedAmount,
                        'created_at' => now(),
                    ];
                }

                if (!empty($dailyRows)) {
                    DB::table('portfolio_daily_snapshots')->insert($dailyRows);
                }
            });
        }
    }
}
