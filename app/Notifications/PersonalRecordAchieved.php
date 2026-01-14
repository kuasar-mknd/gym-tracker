<?php

namespace App\Notifications;

use App\Models\PersonalRecord;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class PersonalRecordAchieved extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public PersonalRecord $personalRecord) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($notifiable->isPushEnabled('personal_record')) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function toWebPush($notifiable, $notification)
    {
        return (new WebPushMessage)
            ->title('Nouveau Record ! 🏆')
            ->icon('/logo.svg')
            ->body($this->toArray($notifiable)['message'])
            ->action('Voir mes stats', url('/stats'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $typeLabel = match ($this->personalRecord->type) {
            'max_weight' => 'Poids Maximum',
            'max_1rm' => '1RM Estimé',
            'max_volume_set' => 'Volume par Série',
            default => 'Record Personnel',
        };

        return [
            'type' => 'personal_record',
            'title' => 'Nouveau Record ! 🏆',
            'message' => "Félicitations ! Tu as battu ton record de {$typeLabel} sur l'exercice {$this->personalRecord->exercise->name} avec {$this->personalRecord->value}kg.",
            'exercise_id' => $this->personalRecord->exercise_id,
            'achieved_at' => $this->personalRecord->achieved_at,
        ];
    }
}
