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

        if ($_notifiable->isPushEnabled('training_reminder')) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    /**
     * @param  mixed  $_notification
     */
    public function toWebPush(User $_notifiable, $_notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Prêt pour ta séance ? 💪')
            ->icon('/logo.svg')
            ->body($this->message ?? '')
            ->action('Ouvrir Gym Tracker', url('/'));
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
