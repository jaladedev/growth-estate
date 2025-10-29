<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class CheckTransactionPin
{
    public function handle(Request $request, Closure $next)
    {
        $user = auth()->user();

        // Ensure user exists and has a PIN
        if (!$user || !$user->transaction_pin) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction PIN not set. Please create a PIN first.',
            ], 403);
        }

        $pin = $request->input('transaction_pin');

        // Ensure PIN is provided
        if (!$pin) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction PIN is required.',
            ], 400);
        }

        $key = 'pin-attempts:' . $user->id;

        // Rate limiting to prevent brute-force
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json([
                'success' => false,
                'message' => 'Too many failed attempts. Please try again later.',
            ], 429);
        }

        // Verify the PIN
        if (!Hash::check($pin, $user->transaction_pin)) {
            RateLimiter::hit($key, 60); // Lock for 60 seconds after failed attempt
            return response()->json([
                'success' => false,
                'message' => 'Invalid transaction PIN.',
            ], 401);
        }

        // Clear attempts on success
        RateLimiter::clear($key);

        return $next($request);
    }
}
