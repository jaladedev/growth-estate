<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $userId = $request->user()->id;
        $latestDate = $this->latestSnapshotDate($userId);

        if (!$latestDate) {
            return response()->json([
                'snapshot_date' => null,
                'total_units' => 0,
                'total_portfolio_value_kobo' => 0,
                'total_invested_kobo' => 0,
                'profit_loss_kobo' => 0,
                'profit_loss_percent' => 0,
            ]);
        }

        $snapshot = DB::table('portfolio_daily_snapshots')
            ->where('user_id', $userId)
            ->where('snapshot_date', $latestDate)
            ->first();

        return response()->json($snapshot);
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
