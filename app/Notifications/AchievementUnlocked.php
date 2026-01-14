<?php

namespace App\Notifications;

use App\Models\Achievement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class AchievementUnlocked extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public Achievement $achievement) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($notifiable->isNotificationEnabled('achievements') && $notifiable->routeNotificationFor('webpush')) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'achievement_id' => $this->achievement->id,
            'slug' => $this->achievement->slug,
            'name' => $this->achievement->name,
            'icon' => $this->achievement->icon,
            'message' => "Nouveau badge débloqué : {$this->achievement->name} !",
        ];
    }

    /**
     * Get the Web Push representation of the notification.
     */
    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('🏆 Badge Débloqué !')
            ->icon('/icons/icon-192x192.png')
            ->body("Félicitations ! Tu as gagné le badge : {$this->achievement->name}")
            ->action('Voir mes badges', 'view_achievements');
    }
}
