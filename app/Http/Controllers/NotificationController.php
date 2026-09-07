<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\NotificationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService
    ) {
    }

    public function index(Request $request): Response
    {
        return Inertia::render('Notifications/Index', [
            'notifications' => $this->user()->notifications()->paginate(20),
        ]);
    }

    /**
     * Le compteur de non-lues est en cache : sans l'invalider, la pastille
     * resterait allumée sur une notification qu'on vient d'ouvrir.
     */
    public function markAsRead(Request $request, string $id): \Illuminate\Http\RedirectResponse
    {
        $this->user()->unreadNotifications()->where('id', $id)->update(['read_at' => now()]);
        $this->notificationService->clearCache($this->user());

        return back();
    }

    public function markAllAsRead(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->user()->unreadNotifications()->update(['read_at' => now()]);
        $this->notificationService->clearCache($this->user());

        return back();
    }
}
