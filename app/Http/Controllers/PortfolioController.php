<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PortfolioController extends Controller
{
    /**
     * Get latest portfolio summary
     * Used for dashboard top cards
     */
    public function summary(Request $request)
    {
        return response()->json(
            DB::table('portfolio_daily_snapshots')
                ->where('user_id', $request->user()->id)
                ->orderByDesc('snapshot_date')
                ->first()
        );
    }

    /**
     * Portfolio value over time (line chart)
     * Query params:
     *  - from (optional)
     *  - to (optional)
     */
    public function chart(Request $request)
    {
        $userId = $request->user()->id;

        $query = DB::table('portfolio_daily_snapshots')
            ->select(
                'snapshot_date',
                'total_portfolio_value_kobo'
            )
            ->where('user_id', $userId)
            ->orderBy('snapshot_date');

        if ($request->filled('from') && $request->filled('to')) {
            $query->whereBetween('snapshot_date', [
                $request->from,
                $request->to
            ]);
        }

        return response()->json($query->get());
    }

    /**
     * Profit / Loss performance chart
     */
    public function performance(Request $request)
    {
        return response()->json(
            DB::table('portfolio_daily_snapshots')
                ->select(
                    'snapshot_date',
                    'profit_loss_kobo',
                    'profit_loss_percent'
                )
                ->where('user_id', $request->user()->id)
                ->orderBy('snapshot_date')
                ->get()
        );
    }

    /**
     * Asset allocation (donut / pie chart)
     * Uses latest snapshot date
     */
    public function allocation(Request $request)
    {
        $userId = $request->user()->id;

        $latestDate = DB::table('portfolio_land_snapshots')
            ->where('user_id', $userId)
            ->max('snapshot_date');

        if (!$latestDate) {
            return response()->json([]);
        }

        return response()->json(
            DB::table('portfolio_land_snapshots as pls')
                ->join('lands', 'lands.id', '=', 'pls.land_id')
                ->select(
                    'lands.name as land_name',
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
        return response()->json(
            DB::table('portfolio_land_snapshots')
                ->select(
                    'snapshot_date',
                    'units_owned',
                    'invested_kobo',
                    'land_value_kobo',
                    'profit_loss_kobo'
                )
                ->where('user_id', $request->user()->id)
                ->where('land_id', $landId)
                ->orderBy('snapshot_date')
                ->get()
        );
    }
}
