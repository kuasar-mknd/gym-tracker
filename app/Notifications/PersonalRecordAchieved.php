<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\PersonalRecordType;
use App\Models\PersonalRecord;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

final class PersonalRecordAchieved extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public PersonalRecord $personalRecord)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $_notifiable): array
    {
        $channels = ['database'];

        /** @var User $_notifiable */
        if ($_notifiable->isPushEnabled('personal_record')) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    /**
     * @param  mixed  $_notification
     */
    public function toWebPush(object $_notifiable, $_notification): WebPushMessage
    {
        return (new WebPushMessage())
            ->title('Nouveau Record ! 🏆')
            ->icon('/logo.svg')
            /** @phpstan-ignore-next-line */
            ->body((string) ($this->toArray($_notifiable)['message'] ?? ''))
            ->action('Voir mes stats', url('/stats'));
    }

    /**
     * @return array<string, Carbon|int|string|bool|float|array<int, mixed>|null>
     */
    public function toArray(object $_notifiable): array
    {
        $typeLabel = match ($this->personalRecord->type) {
            PersonalRecordType::MaxWeight => 'Poids Maximum',
            PersonalRecordType::Max1RM => '1RM Estimé',
            PersonalRecordType::MaxVolumeSet => 'Volume par Série',
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
