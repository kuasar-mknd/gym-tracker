<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Achievement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

final class AchievementUnlocked extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Le titre de la notification, au centre de notifications comme en push.
     *
     * Les succès portaient trois noms selon l'écran (« Succès », « Trophées »,
     * « Badges ») ; l'interface n'en garde qu'un, « Badges », le plus employé
     * (#1980).
     */
    private const string TITRE = 'Badge débloqué ! 🏆';

    public function __construct(public Achievement $achievement)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $_notifiable): array
    {
        $channels = ['database'];

        /** @var \App\Models\User $_notifiable */
        if ($_notifiable->notificationsPoussesActivees('achievement')) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    /**
     * @param  mixed  $_notification
     */
    public function toWebPush(object $_notifiable, $_notification): WebPushMessage
    {
        return new WebPushMessage()
            ->title(self::TITRE)
            ->icon('/pwa-192x192.png')
            /** @phpstan-ignore-next-line */
            ->body((string) ($this->toArray($_notifiable)['message'] ?? ''))
            ->action('Voir mes badges', url('/achievements'))
            ->data(['url' => '/achievements']);
    }

    /**
     * @return array<string, \Illuminate\Support\Carbon|int|string|bool|float|array<int, mixed>|null>
     */
    public function toArray(object $_notifiable): array
    {
        return [
            'type' => 'achievement',
            'title' => self::TITRE,
            'message' => "Félicitations ! Tu as débloqué le badge : {$this->achievement->name}.",
            'achievement_id' => $this->achievement->id,
            'achieved_at' => now(),
        ];
    }
}
