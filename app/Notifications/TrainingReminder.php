<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class TrainingReminder extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct() {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($notifiable->isPushEnabled('training_reminder')) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function toWebPush($notifiable, $notification)
    {
        return (new WebPushMessage)
            ->title("C'est l'heure de bouger ! 🏋️‍♂️")
            ->icon('/logo.svg')
            ->body($this->toArray($notifiable)['message'])
            ->action("M'entraîner maintenant", url('/workouts/active'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'training_reminder',
            'title' => 'Prêt pour une séance ? 🏋️',
            'message' => "Ça fait quelques jours que tu n'as pas enregistré d'entraînement. Prêt à reprendre aujourd'hui ?",
        ];
    }
}
