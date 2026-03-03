<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Admin user management.
 *
 * Routes (under /admin prefix + admin middleware):
 *   PATCH /admin/users/{user}/suspend
 *   PATCH /admin/users/{user}/unsuspend
 */
class AdminUserController extends Controller
{
    public function suspend(Request $request, User $user)
    {
        // Prevent suspending another admin
        if ($user->is_admin) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot suspend an admin account.',
            ], 403);
        }

        // Idempotent — tell the caller if nothing changed
        if ($user->is_suspended) {
            return response()->json([
                'success' => true,
                'message' => "User {$user->name} is already suspended.",
                'changed' => false,
            ]);
        }

        $user->update(['is_suspended' => true]);

        Log::info('User suspended', [
            'target_user_id' => $user->id,
            'target_email'   => $user->email,
            'by_admin_id'    => $request->user()->id,
            'by_admin_email' => $request->user()->email,
            'ip'             => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => "User {$user->name} has been suspended.",
            'changed' => true,
        ]);
    }

    public function unsuspend(Request $request, User $user)
    {
        // Idempotent
        if (! $user->is_suspended) {
            return response()->json([
                'success' => true,
                'message' => "User {$user->name} is not suspended.",
                'changed' => false,
            ]);
        }

        $user->update(['is_suspended' => false]);

        Log::info('User unsuspended', [
            'target_user_id' => $user->id,
            'target_email'   => $user->email,
            'by_admin_id'    => $request->user()->id,
            'by_admin_email' => $request->user()->email,
            'ip'             => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => "User {$user->name} has been unsuspended.",
            'changed' => true,
        ]);
    }
}