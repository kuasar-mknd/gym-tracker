<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PersonalRecord;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Number;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

final class PersonalRecordAchieved extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Le titre de la notification, au centre de notifications comme en push.
     */
    private const string TITRE = 'Nouveau record ! 🏆';

    public function __construct(public PersonalRecord $personalRecord)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $_notifiable): array
    {
        $channels = ['database'];

        /** @var \App\Models\User $_notifiable */
        if ($_notifiable->notificationsPoussesActivees('personal_record')) {
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
            ->action('Voir mes stats', url('/stats'))
            ->data(['url' => '/stats']);
    }

    /**
     * Le message est composé ici, et le centre de notifications comme le push
     * l'affichent tel quel : la valeur suit donc le format que
     * `resources/js/Utils/nombre.js` donne aux poids dans le reste de
     * l'application (fr-CH, au plus deux décimales, sans zéro inutile), et non
     * le `decimal:2` de la colonne, qui écrivait « 102.50kg ».
     *
     * @return array<string, \Illuminate\Support\Carbon|int|string|bool|float|array<int, mixed>|null>
     */
    public function toArray(object $_notifiable): array
    {
        $typeLabel = match ($this->personalRecord->type) {
            \App\Enums\PersonalRecordType::MaxWeight => 'poids maximum',
            \App\Enums\PersonalRecordType::Max1RM => '1RM estimé',
            \App\Enums\PersonalRecordType::MaxVolumeSet => 'volume par série',
            default => 'record personnel',
        };

        $valeur = (string) Number::format((float) $this->personalRecord->value, maxPrecision: 2, locale: 'fr_CH');

        return [
            'type' => 'personal_record',
            'title' => self::TITRE,
            'message' => "Félicitations ! Tu as battu ton record de {$typeLabel} sur l'exercice {$this->personalRecord->exercise->name} avec {$valeur}\u{00A0}kg.",
            'exercise_id' => $this->personalRecord->exercise_id,
            'achieved_at' => $this->personalRecord->achieved_at,
        ];
    }
}
