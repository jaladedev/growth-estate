<?php

namespace App\Http\Controllers;

use App\Models\Land;
use App\Models\PortfolioDailySnapshot;
use App\Models\UserLand;
use App\Services\PortfolioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

class PortfolioController extends Controller
{
    /**
     * GET /api/portfolio/summary
     */
    public function summary()
    {
        $user = JWTAuth::parseToken()->authenticate();

        return response()->json([
            'success' => true,
            'data'    => PortfolioService::summary($user->id),
        ]);
    }

    /**
     * GET /api/portfolio/chart
     * Returns daily snapshot data for the portfolio value chart.
     */
    public function chart(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();

        $request->validate([
            'days' => 'sometimes|integer|min:7|max:365',
        ]);

        $days = $request->integer('days', 30);

        $snapshots = PortfolioDailySnapshot::where('user_id', $user->id)
            ->where('snapshot_date', '>=', now()->subDays($days)->toDateString())
            ->orderBy('snapshot_date')
            ->select('snapshot_date', 'total_portfolio_value_kobo', 'total_invested_kobo')
            ->get()
            ->map(fn ($s) => [
                'date'               => $s->snapshot_date,
                'value_kobo'         => $s->total_portfolio_value_kobo,
                'value_naira'        => $s->total_portfolio_value_kobo / 100,
                'invested_kobo'      => $s->total_invested_kobo,
                'invested_naira'     => $s->total_invested_kobo / 100,
                'profit_loss_kobo'   => $s->total_portfolio_value_kobo - $s->total_invested_kobo,
                'profit_loss_naira'  => ($s->total_portfolio_value_kobo - $s->total_invested_kobo) / 100,
            ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'days'      => $days,
                'snapshots' => $snapshots,
            ],
        ]);
    }

    /**
     * GET /api/portfolio/performance
     * Returns return-on-investment metrics.
     */
    public function performance()
    {
        $user    = JWTAuth::parseToken()->authenticate();
        $summary = PortfolioService::summary($user->id);

        $firstSnapshot = PortfolioDailySnapshot::where('user_id', $user->id)
            ->orderBy('snapshot_date')
            ->first();

        $daysSinceFirstInvestment = $firstSnapshot
            ? now()->diffInDays($firstSnapshot->snapshot_date)
            : 0;

        return response()->json([
            'success' => true,
            'data'    => [
                'total_return_kobo'      => $summary['total_profit_loss_kobo'],
                'total_return_naira'     => $summary['total_profit_loss_naira'],
                'total_return_percent'   => $summary['profit_loss_percent'],
                'days_invested'          => $daysSinceFirstInvestment,
                'annualized_return'      => $this->annualizedReturn(
                    $summary['profit_loss_percent'],
                    $daysSinceFirstInvestment
                ),
            ],
        ]);
    }

    /**
     * GET /api/portfolio/allocation
     * Returns per-land breakdown as a percentage of total portfolio.
     */
    public function allocation()
    {
        $user    = JWTAuth::parseToken()->authenticate();
        $summary = PortfolioService::summary($user->id);

        $totalValue = $summary['current_portfolio_value_kobo'];

        $lands = collect($summary['lands'])->map(function ($land) use ($totalValue) {
            $land['allocation_percent'] = $totalValue > 0
                ? round(($land['total_portfolio_value_kobo'] / $totalValue) * 100, 2)
                : 0;
            return $land;
        });

        return response()->json([
            'success' => true,
            'data'    => [
                'total_portfolio_value_kobo'  => $totalValue,
                'total_value_naira' => $totalValue / 100,
                'lands'             => $lands,
            ],
        ]);
    }

    /**
     * GET /api/portfolio/asset/{land}
     * Returns details for a single held asset.
     */
    public function asset(Land $land)
    {
        $user = JWTAuth::parseToken()->authenticate();

        $userLand = UserLand::where('user_id', $user->id)
            ->where('land_id', $land->id)
            ->where('units', '>', 0)
            ->first();

        if (! $userLand) {
            return response()->json([
                'success' => false,
                'message' => 'You do not hold any units in this land.',
            ], 404);
        }

        $pricePerUnit  = $land->current_price_per_unit_kobo;
        $currentValue  = $userLand->units * $pricePerUnit;

        $purchase = DB::table('purchases')
            ->where('user_id', $user->id)
            ->where('land_id', $land->id)
            ->first();

        $costBasis = $purchase
            ? ($purchase->total_amount_paid_kobo - $purchase->total_amount_received_kobo)
            : 0;

        $profitLoss = $currentValue - $costBasis;

        return response()->json([
            'success' => true,
            'data'    => [
                'land_id'              => $land->id,
                'land_name'            => $land->title,
                'units'                => $userLand->units,
                'price_per_unit_kobo'  => $pricePerUnit,
                'price_per_unit_naira' => $pricePerUnit / 100,
                'current_value_kobo'   => $currentValue,
                'current_value_naira'  => $currentValue / 100,
                'cost_basis_kobo'      => $costBasis,
                'cost_basis_naira'     => $costBasis / 100,
                'profit_loss_kobo'     => $profitLoss,
                'profit_loss_naira'    => $profitLoss / 100,
                'profit_loss_percent'  => $costBasis > 0
                    ? round(($profitLoss / $costBasis) * 100, 2)
                    : 0,
                'first_purchased_at'   => $purchase?->purchase_date,
            ],
        ]);
    }

    private function annualizedReturn(float $totalReturnPercent, int $days): float
    {
        if ($days < 1) {
            return 0;
        }

        // Compound annualized return: (1 + r)^(365/days) - 1
        $r = $totalReturnPercent / 100;
        return round((pow(1 + $r, 365 / $days) - 1) * 100, 2);
    }
}