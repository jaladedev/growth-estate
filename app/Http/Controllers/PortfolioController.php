<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

class PortfolioController extends Controller
{
    /**
     * Get user's latest snapshot date
     */
    private function latestSnapshotDate(int $userId): ?string
    {
        return DB::table('portfolio_daily_snapshots')
            ->where('user_id', $userId)
            ->max('snapshot_date');
    }

    /**
     * Dashboard summary cards
     */
    public function summary(Request $request)
       {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            // Get user's current holdings from user_land
            $userLands = DB::table('user_land')
                ->where('user_id', $user->id)
                ->where('units', '>', 0)
                ->get();

            if ($userLands->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'total_units' => 0,
                        'total_invested' => 0,
                        'current_portfolio_value' => 0,
                        'total_profit_loss' => 0,
                        'profit_loss_percent' => 0,
                        'lands' => [],
                    ],
                ]);
            }

            // Get land IDs
            $landIds = $userLands->pluck('land_id')->toArray();

            // Get current prices for all lands
            $prices = \App\Models\LandPriceHistory::currentPricesForLands($landIds);

            // Calculate total invested (all-time)
            $investedData = DB::table('purchases')
                ->select(
                    DB::raw('SUM(total_amount_paid_kobo) as total_paid'),
                    DB::raw('SUM(total_amount_received_kobo) as total_received'),
                    DB::raw('SUM(total_amount_paid_kobo) - SUM(total_amount_received_kobo) as net_invested')
                )
                ->where('user_id', $user->id)
                ->whereIn('status', ['completed', 'partially_sold'])
                ->first();

            $totalInvested = $investedData->net_invested ?? 0;

            // Calculate current portfolio value and breakdown by land
            $totalUnits = 0;
            $totalValue = 0;
            $landBreakdown = [];

            foreach ($userLands as $userLand) {
                $price = $prices->get($userLand->land_id);
                $pricePerUnit = $price ? $price->price_per_unit_kobo : 0;
                $landValue = $userLand->units * $pricePerUnit;

                $totalUnits += $userLand->units;
                $totalValue += $landValue;

                // Get land details
                $land = DB::table('lands')->find($userLand->land_id);

                $landBreakdown[] = [
                    'land_id' => $userLand->land_id,
                    'land_name' => $land->title ?? 'Unknown',
                    'units' => $userLand->units,
                    'price_per_unit_kobo' => $pricePerUnit,
                    'price_per_unit_naira' => $pricePerUnit / 100,
                    'total_value_kobo' => $landValue,
                    'total_value_naira' => $landValue / 100,
                ];
            }

            // Calculate profit/loss
            $profitLoss = $totalValue - $totalInvested;
            $profitLossPercent = $totalInvested > 0 
                ? round(($profitLoss / $totalInvested) * 100, 2) 
                : 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'total_units' => $totalUnits,
                    'total_invested_kobo' => $totalInvested,
                    'total_invested_naira' => $totalInvested / 100,
                    'current_portfolio_value_kobo' => $totalValue,
                    'current_portfolio_value_naira' => $totalValue / 100,
                    'total_profit_loss_kobo' => $profitLoss,
                    'total_profit_loss_naira' => $profitLoss / 100,
                    'profit_loss_percent' => $profitLossPercent,
                    'lands' => $landBreakdown,
                ],
            ]);

        } catch (\Exception $e) {
            \Log::error('Portfolio summary error', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error fetching portfolio summary',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Portfolio value over time (line chart)
     */
    public function chart(Request $request)
    {
        $request->validate([
            'from' => 'nullable|date',
            'to'   => 'nullable|date|after_or_equal:from',
        ]);

        $userId = $request->user()->id;

        $query = DB::table('portfolio_daily_snapshots')
            ->select('snapshot_date', 'total_portfolio_value_kobo')
            ->where('user_id', $userId)
            ->orderBy('snapshot_date');

        if ($request->filled('from') && $request->filled('to')) {
            $query->whereBetween('snapshot_date', [$request->from, $request->to]);
        }

        return response()->json($query->get());
    }

    /**
     * Profit / Loss performance chart
     */
    public function performance(Request $request)
    {
        $userId = $request->user()->id;

        $snapshots = DB::table('portfolio_daily_snapshots')
            ->select(
                'snapshot_date',
                'total_invested_kobo',
                'total_portfolio_value_kobo',
                DB::raw('(total_portfolio_value_kobo - total_invested_kobo) as profit_loss_kobo'),
                DB::raw('CASE WHEN total_invested_kobo > 0 THEN ROUND((total_portfolio_value_kobo - total_invested_kobo) / total_invested_kobo * 100, 2) ELSE 0 END as profit_loss_percent')
            )
            ->where('user_id', $userId)
            ->orderBy('snapshot_date')
            ->get();

        return response()->json($snapshots);
    }

    /**
     * Asset allocation (pie / donut chart)
     */
    public function allocation(Request $request)
    {
        $userId = $request->user()->id;
        $latestDate = $this->latestSnapshotDate($userId);

        if (!$latestDate) {
            return response()->json([]);
        }

        return response()->json(
            DB::table('portfolio_land_snapshots as pls')
                ->join('lands', 'lands.id', '=', 'pls.land_id')
                ->select(
                    'lands.title as land_name',
                    'pls.land_value_kobo'
                )
                ->where('pls.user_id', $userId)
                ->where('pls.snapshot_date', $latestDate)
                ->get()
        );
    }

    /**
     * Single land performance over time
     */
    public function asset(Request $request, int $landId)
    {
        $userId = $request->user()->id;

        return response()->json(
            DB::table('portfolio_land_snapshots')
                ->select(
                    'snapshot_date',
                    'units_owned',
                    'invested_kobo',
                    'land_value_kobo',
                    DB::raw('(land_value_kobo - invested_kobo) as profit_loss_kobo')
                )
                ->where('user_id', $userId)
                ->where('land_id', $landId)
                ->orderBy('snapshot_date')
                ->get()
        );
    }
}
