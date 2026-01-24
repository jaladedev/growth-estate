<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class GeneratePortfolioSnapshot extends Command
{
    protected $signature = 'portfolio:snapshot {--date=}';
    protected $description = 'Generate daily portfolio snapshots for all users';

    public function handle()
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))->toDateString()
            : now()->toDateString();

        $this->info("Generating portfolio snapshots for {$date}");

        DB::transaction(function () use ($date) {

            $users = DB::table('users')->select('id')->get();

            foreach ($users as $user) {
                $this->snapshotUser($user->id, $date);
            }
        });

        $this->info("Portfolio snapshot completed.");
        return 0;
    }

    private function snapshotUser(int $userId, string $date): void
    {
        // Prevent duplicates
        $exists = DB::table('portfolio_daily_snapshots')
            ->where('user_id', $userId)
            ->where('snapshot_date', $date)
            ->exists();

        if ($exists) {
            return;
        }

        // User holdings
        $lands = DB::table('purchases as p')
            ->join('lands as l', 'l.id', '=', 'p.land_id')
            ->where('p.user_id', $userId)
            ->select(
                'p.land_id',
                'p.units as units_owned',
                'p.total_amount_paid_kobo as invested_kobo',
                'l.price_per_unit_kobo' 
            )
            ->get();

        if ($lands->isEmpty()) {
            return;
        }

        $totalUnits = 0;
        $totalInvested = 0;
        $totalValue = 0;

        foreach ($lands as $land) {
            $unitPriceKobo = $land->price_per_unit_kobo; 

            $landValue = $land->units_owned * $unitPriceKobo;
            $profitLoss = $landValue - $land->invested_kobo;

            // Save per-land snapshot
            DB::table('portfolio_land_snapshots')->insert([
                'user_id' => $userId,
                'land_id' => $land->land_id,
                'snapshot_date' => $date,
                'units_owned' => $land->units_owned,
                'invested_kobo' => $land->invested_kobo,
                'land_value_kobo' => $landValue,
                'profit_loss_kobo' => $profitLoss,
                'created_at' => now(),
            ]);

            $totalUnits += $land->units_owned;
            $totalInvested += $land->invested_kobo;
            $totalValue += $landValue;
        }

        $profitLossTotal = $totalValue - $totalInvested;
        $roiPercent = $totalInvested > 0
            ? round(($profitLossTotal / $totalInvested) * 100, 2)
            : 0;

        // Save daily snapshot
        DB::table('portfolio_daily_snapshots')->insert([
            'user_id' => $userId,
            'snapshot_date' => $date,
            'total_units' => $totalUnits,
            'total_invested_kobo' => $totalInvested,
            'total_portfolio_value_kobo' => $totalValue,
            'profit_loss_kobo' => $profitLossTotal,
            'profit_loss_percent' => $roiPercent,
            'created_at' => now(),
        ]);
    }
}
