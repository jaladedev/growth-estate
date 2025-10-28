<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;

class CheckTransactionPin
{
    public function handle(Request $request, Closure $next)
    {
        $user = JWTAuth::parseToken()->authenticate();
        $pin = $request->input('transaction_pin');

        if (!$pin || !password_verify($pin, $user->transaction_pin)) {
            return response()->json(['error' => 'Invalid transaction PIN'], 403);
        }

        return $next($request);
    }
}
