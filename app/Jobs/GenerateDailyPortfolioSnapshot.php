<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class GenerateDailyPortfolioSnapshot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $snapshotDate = now()->toDateString();

        DB::transaction(function () use ($snapshotDate) {

            // Prevent duplicate runs (idempotency)
            $exists = DB::table('portfolio_daily_snapshots')
                ->where('snapshot_date', $snapshotDate)
                ->exists();

            if ($exists) {
                return;
            }

            // Get latest price per land (PGSQL optimized)
            $prices = DB::table('land_price_history as lph')
                ->join(DB::raw('(
                    SELECT land_id, MAX(price_date) AS price_date
                    FROM land_price_history
                    WHERE price_date <= CURRENT_DATE
                    GROUP BY land_id
                ) latest'), function ($join) {
                    $join->on('lph.land_id', '=', 'latest.land_id')
                         ->on('lph.price_date', '=', 'latest.price_date');
                })
                ->select('lph.land_id', 'lph.price_per_unit_kobo')
                ->get()
                ->keyBy('land_id');

            // Aggregate user holdings
            $holdings = DB::table('user_land')
                ->select('user_id', 'land_id', 'units')
                ->get();

            $userTotals = [];

            foreach ($holdings as $row) {
                if (!isset($prices[$row->land_id])) {
                    continue;
                }

                $value = $row->units * $prices[$row->land_id]->price_per_unit_kobo;

                // Per-asset snapshot (optional but powerful)
                DB::table('portfolio_asset_snapshots')->insert([
                    'user_id' => $row->user_id,
                    'land_id' => $row->land_id,
                    'snapshot_date' => $snapshotDate,
                    'units' => $row->units,
                    'value_kobo' => $value,
                    'created_at' => now(),
                ]);

                if (!isset($userTotals[$row->user_id])) {
                    $userTotals[$row->user_id] = [
                        'total_value' => 0,
                        'total_invested' => 0,
                    ];
                }

                $userTotals[$row->user_id]['total_value'] += $value;
            }

            // Get invested amount per user
            $invested = DB::table('purchases')
                ->select(
                    'user_id',
                    DB::raw('SUM(total_amount_paid_kobo - total_amount_received_kobo) AS invested')
                )
                ->groupBy('user_id')
                ->get()
                ->keyBy('user_id');

            // Insert user snapshots
            foreach ($userTotals as $userId => $totals) {
                $investedAmount = $invested[$userId]->invested ?? 0;

                DB::table('portfolio_daily_snapshots')->insert([
                    'user_id' => $userId,
                    'snapshot_date' => $snapshotDate,
                    'total_invested_kobo' => $investedAmount,
                    'total_value_kobo' => $totals['total_value'],
                    'total_profit_kobo' => $totals['total_value'] - $investedAmount,
                    'created_at' => now(),
                ]);
            }
        });
    }
}
