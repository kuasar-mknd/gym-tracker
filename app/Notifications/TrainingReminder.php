<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

final class TrainingReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ?string $message = null)
    {
        $this->message ??= "C'est le moment de s'entraîner ! 💪";
    }

    /**
     * @return array<int, string>
     */
    public function via(User $_notifiable): array
    {
        $channels = ['database'];

        if ($_notifiable->notificationsPoussesActivees('training_reminder')) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    /**
     * Le rappel ouvre le tableau de bord lui-même : « / » ne fait qu'y
     * rediriger, au prix d'un aller-retour de plus (#1969).
     *
     * @param  mixed  $_notification
     */
    public function toWebPush(User $_notifiable, $_notification): WebPushMessage
    {
        return new WebPushMessage()
            ->title('Prêt pour ta séance ? 💪')
            ->icon('/pwa-192x192.png')
            ->body($this->message ?? '')
            ->action('Ouvrir Gym Tracker', route('dashboard'))
            ->data(['url' => route('dashboard', absolute: false)]);
    }

    /**
     * @return array<string, \Illuminate\Support\Carbon|int|string|bool|float|array<int, mixed>|null>
     */
    public function toArray(User $_notifiable): array
    {
        return [
            'type' => 'training_reminder',
            'title' => 'Rappel d\'entraînement',
            'message' => $this->message,
            'sent_at' => now(),
        ];
    }
}
