<?php

declare(strict_types=1);

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
            'notifications' => $this->user()->notifications()->paginate(20),
        ]);
    }

    /**
     * Mark a notification as read.
     */
    public function markAsRead(Request $request, string $id): \Illuminate\Http\RedirectResponse
    {
        $this->user()->unreadNotifications()->where('id', $id)->update(['read_at' => now()]);

        return back();
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->user()->unreadNotifications->markAsRead();

        return back();
    }
}
