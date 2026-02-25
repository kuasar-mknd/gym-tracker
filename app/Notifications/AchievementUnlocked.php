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

    public function __construct(public Achievement $achievement)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $_notifiable): array
    {
        $channels = ['database'];

        /** @var \App\Models\User $user */
        $user = $_notifiable;
        if ($user->isPushEnabled('achievement')) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function toWebPush(object $_notifiable, mixed $_notification): WebPushMessage
    {
        return (new WebPushMessage())
            ->title('Succès Déverrouillé ! 🏆')
            ->icon('/logo.svg')
            /** @phpstan-ignore-next-line */
            ->body((string) ($this->toArray($_notifiable)['message'] ?? ''))
            ->action('Voir mes succès', url('/achievements'));
    }

    /**
     * @return array<string, \Illuminate\Support\Carbon|int|string|bool|float|array<int, mixed>|null>
     */
    public function toArray(object $_notifiable): array
    {
        return [
            'type' => 'achievement',
            'title' => 'Succès Déverrouillé ! 🏆',
            'message' => "Félicitations ! Tu as déverrouillé le succès : {$this->achievement->name}.",
            'achievement_id' => $this->achievement->id,
            'achieved_at' => now(),
        ];
    }
}
