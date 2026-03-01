<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function suspend(Request $request, User $user)
    {
        $this->authorizeAdmin();

        if ($user->is_admin) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot suspend an admin account.',
            ], 403);
        }

        $user->update(['is_suspended' => true]);

        return response()->json([
            'success' => true,
            'message' => "User {$user->name} has been suspended.",
        ]);
    }

    public function unsuspend(Request $request, User $user)
    {
        $this->authorizeAdmin();

        $user->update(['is_suspended' => false]);

        return response()->json([
            'success' => true,
            'message' => "User {$user->name} has been unsuspended.",
        ]);
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);
    }
}
