<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    /**
     * Display a listing of notifications.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('Notifications/Index', [
            'notifications' => $request->user()->notifications()->paginate(20),
        ]);
    }

    /**
     * Mark a notification as read.
     */
    public function markAsRead(Request $request, string $id)
    {
        $request->user()->unreadNotifications()->where('id', $id)->update(['read_at' => now()]);

        return back();
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }
}
