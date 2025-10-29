<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class CheckTransactionPin
{
    public function handle(Request $request, Closure $next)
    {
        $user = auth()->user();

        if (!$user || !$user->transaction_pin) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction PIN not set. Please create a PIN first.',
            ], 403);
        }

        $pin = $request->input('transaction_pin');
        \Log::info('Transaction PIN received: ', ['transaction_pin' => $pin]);

        if (!$pin || !Hash::check($pin, $user->transaction_pin)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid transaction PIN.',
            ], 401);
        }

        return $next($request);
    }
}
