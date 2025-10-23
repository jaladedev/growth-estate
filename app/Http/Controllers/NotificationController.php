<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function getNotifications(Request $request)
    {
        $user = $request->user();
        return response()->json([
            'notifications' => $user->notifications
        ]);
    }

    public function getUnreadNotifications(Request $request)
    {
        $user = $request->user();
        return response()->json([
            'unread_notifications' => $user->unreadNotifications
        ]);
    }

    public function markAllAsRead(Request $request)
    {
        $user = $request->user();
        $user->unreadNotifications->markAsRead();

        return response()->json([
            'message' => 'All notifications marked as read'
        ]);
    }
}
