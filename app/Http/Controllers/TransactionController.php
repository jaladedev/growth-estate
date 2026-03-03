<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;

/**
 * Routes:
 *   GET /transactions/user
 */
class TransactionController extends Controller
{
    // GET /transactions/user
    public function userTransactions(Request $request)
    {
        $request->validate([
            'type'     => 'sometimes|in:purchase,sale',
            'land_id'  => 'sometimes|integer|exists:lands,id',
            'per_page' => 'sometimes|integer|min:5|max:100',
        ]);

        $transactions = Transaction::with('land:id,title')
            ->where('user_id', $request->user()->id)
            ->when($request->type,    fn ($q) => $q->where('type', $request->type))
            ->when($request->land_id, fn ($q) => $q->where('land_id', $request->land_id))
            ->orderByDesc('transaction_date')
            ->paginate($request->input('per_page', 15));

        return response()->json(['success' => true, 'data' => $transactions]);
    }
}