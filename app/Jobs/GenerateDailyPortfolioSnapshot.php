<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Models\LandPriceHistory;

class GenerateDailyPortfolioSnapshot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected array $dates;

    public function __construct(string|array|null $dates = null)
    {
        if ($dates === null) {
            $dates = [now()->toDateString()];
        } elseif (is_string($dates)) {
            $dates = [$dates];
        }

        foreach ($dates as $date) {
            $dateObj = Carbon::parse($date);
            if ($dateObj->isPast() && !$dateObj->isToday()) {
                throw new \InvalidArgumentException(
                    "Historical snapshots not supported. Date {$date} is in the past."
                );
            }
        }

        $this->dates = $dates;
    }

    public function handle(): void
    {
        foreach ($this->dates as $date) {
            $snapshotDate = Carbon::parse($date)->toDateString();

            if ($this->snapshotExists($snapshotDate)) {
                Log::info("Snapshot already exists for {$snapshotDate}. Skipping.");
                continue;
            }

            try {
                $this->generateSnapshot($snapshotDate);
                Log::info("Successfully generated snapshot for {$snapshotDate}");
            } catch (\Exception $e) {
                Log::error("Failed to generate snapshot for {$snapshotDate}: " . $e->getMessage());
                throw $e;
            }
        }
    }

    protected function snapshotExists(string $date): bool
    {
        return DB::table('portfolio_daily_snapshots')
            ->where('snapshot_date', $date)
            ->exists();
    }

    protected function generateSnapshot(string $snapshotDate): void
    {
        DB::transaction(function () use ($snapshotDate) {
            if ($this->snapshotExists($snapshotDate)) {
                Log::warning("Snapshot for {$snapshotDate} was created by another process. Skipping.");
                return;
            }

            $prices = $this->getPrices($snapshotDate);

            if ($prices->isEmpty()) {
                Log::warning("No prices found for {$snapshotDate}. Cannot generate snapshot.");
                return;
            }

            $holdings = $this->getHoldings();

            if ($holdings->isEmpty()) {
                Log::warning("No user holdings found for {$snapshotDate}. Cannot generate snapshot.");
                return;
            }

            [$assetRows, $userTotals, $userUnits] = $this->calculateAssetValues($holdings, $prices, $snapshotDate);

            if (!empty($assetRows)) {
                foreach (array_chunk($assetRows, 1000) as $chunk) {
                    DB::table('portfolio_asset_snapshots')->insert($chunk);
                }
                Log::info("Inserted " . count($assetRows) . " asset snapshot rows for {$snapshotDate}");
            }

            $invested = $this->getInvestedAmounts($snapshotDate);

            $dailyRows = $this->calculateDailySnapshots($userTotals, $userUnits, $invested, $snapshotDate);

            if (!empty($dailyRows)) {
                foreach (array_chunk($dailyRows, 1000) as $chunk) {
                    DB::table('portfolio_daily_snapshots')->insert($chunk);
                }
                Log::info("Inserted " . count($dailyRows) . " daily snapshot rows for {$snapshotDate}");
            }
        });
    }

    protected function getPrices(string $snapshotDate): \Illuminate\Support\Collection
    {
        return Cache::remember(
            "land_prices_latest",
            now()->addMinutes(30),
            function () {
                // Get all unique land IDs that have holdings
                $landIds = DB::table('user_land')
                    ->where('units', '>', 0)
                    ->distinct()
                    ->pluck('land_id')
                    ->toArray();

                if (empty($landIds)) {
                    return collect();
                }

                // Get current prices for all lands in one query
                $prices = LandPriceHistory::currentPricesForLands($landIds);

                // Transform to match expected structure
                return $prices->map(function ($priceHistory) {
                    return (object)[
                        'land_id' => $priceHistory->land_id,
                        'price_per_unit_kobo' => $priceHistory->price_per_unit_kobo
                    ];
                });
            }
        );
    }

    protected function getHoldings(): \Illuminate\Support\Collection
    {
        return Cache::remember(
            "user_holdings_current",
            now()->addMinutes(10),
            fn() => DB::table('user_land')
                ->select('user_id', 'land_id', 'units')
                ->where('units', '>', 0)
                ->get()
        );
    }

    protected function getInvestedAmounts(string $snapshotDate): \Illuminate\Support\Collection
    {
        return Cache::remember(
            "user_invested_as_of_{$snapshotDate}",
            now()->addMinutes(30),
            fn() => DB::table('purchases')
                ->select(
                    'user_id',
                    DB::raw('SUM(total_amount_paid_kobo) as total_paid'),
                    DB::raw('SUM(total_amount_received_kobo) as total_received'),
                    DB::raw('SUM(total_amount_paid_kobo) - SUM(total_amount_received_kobo) as invested')
                )
                ->whereDate('purchase_date', '<=', $snapshotDate)
                ->whereIn('status', ['completed', 'partially_sold'])
                ->groupBy('user_id')
                ->get()
                ->keyBy('user_id')
        );
    }

    protected function calculateAssetValues(
        \Illuminate\Support\Collection $holdings,
        \Illuminate\Support\Collection $prices,
        string $snapshotDate
    ): array {
        $assetRows = [];
        $userTotals = [];
        $userUnits = [];

        foreach ($holdings as $row) {
            if (!isset($prices[$row->land_id])) {
                Log::debug("No price found for land_id {$row->land_id} on {$snapshotDate}");
                continue;
            }

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
            $userUnits[$row->user_id] = ($userUnits[$row->user_id] ?? 0) + $row->units;
        }

        return [$assetRows, $userTotals, $userUnits];
    }

    protected function calculateDailySnapshots(
        array $userTotals,
        array $userUnits,
        \Illuminate\Support\Collection $invested,
        string $snapshotDate
    ): array {
        $dailyRows = [];

        foreach ($userTotals as $userId => $totalValue) {
            $investedAmount = $invested[$userId]->invested ?? 0;
            $profitLoss = $totalValue - $investedAmount;
            $profitLossPercent = $investedAmount > 0 
                ? round(($profitLoss / $investedAmount) * 100, 2) 
                : 0;

            $dailyRows[] = [
                'user_id' => $userId,
                'snapshot_date' => $snapshotDate,
                'total_units' => $userUnits[$userId] ?? 0,
                'total_invested_kobo' => $investedAmount,
                'total_portfolio_value_kobo' => $totalValue,
                'profit_loss_kobo' => $profitLoss,
                'profit_loss_percent' => $profitLossPercent,
                'created_at' => now(),
            ];
        }

        return $dailyRows;
    }
}