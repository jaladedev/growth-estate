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

            // ==============================
            // Prevent duplicate runs
            // ==============================
            $exists = DB::table('portfolio_daily_snapshots')
                ->where('snapshot_date', $snapshotDate)
                ->exists();

            if ($exists) {
                return;
            }

            // ==============================
            // Get latest price per land
            // (as of snapshot date)
            // ==============================
            $prices = collect(DB::select("
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

            if ($prices->isEmpty()) {
                return;
            }

            // ==============================
            // Get user holdings
            // ==============================
            $holdings = DB::table('user_land')
                ->select('user_id', 'land_id', 'units')
                ->get();

            if ($holdings->isEmpty()) {
                return;
            }

            $assetRows = [];
            $userTotals = [];

            foreach ($holdings as $row) {

                if (!isset($prices[$row->land_id])) {
                    continue;
                }

                $value = $row->units * $prices[$row->land_id]->price_per_unit_kobo;

                // Build asset snapshot rows (bulk insert later)
                $assetRows[] = [
                    'user_id' => $row->user_id,
                    'land_id' => $row->land_id,
                    'snapshot_date' => $snapshotDate,
                    'units' => $row->units,
                    'value_kobo' => $value,
                    'created_at' => now(),
                ];

                if (!isset($userTotals[$row->user_id])) {
                    $userTotals[$row->user_id] = 0;
                }

                $userTotals[$row->user_id] += $value;
            }

            // ==============================
            // Bulk insert asset snapshots
            // ==============================
            if (!empty($assetRows)) {
                DB::table('portfolio_asset_snapshots')->insert($assetRows);
            }

            // ==============================
            // Get invested amount per user
            // ==============================
            $invested = DB::table('purchases')
                ->select(
                    'user_id',
                    DB::raw('SUM(total_amount_paid_kobo - total_amount_received_kobo) AS invested')
                )
                ->groupBy('user_id')
                ->get()
                ->keyBy('user_id');

            // ==============================
            // Build daily snapshot rows
            // ==============================
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

            // ==============================
            // Bulk insert daily snapshots
            // ==============================
            if (!empty($dailyRows)) {
                DB::table('portfolio_daily_snapshots')->insert($dailyRows);
            }
        });
    }
}
