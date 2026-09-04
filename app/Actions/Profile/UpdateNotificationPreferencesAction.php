<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Models\User;

class UpdateNotificationPreferencesAction
{
    /**
     * @param array{
     *     preferences: array<string, bool>,
     *     push_preferences?: array<string, bool>,
     *     days?: array<string, array<int, int>>
     * } $data
     */
    public function execute(User $user, array $data): void
    {
        // ⚡ Bolt: Replaced updateOrCreate loop with a single bulk upsert to prevent N+1 queries.
        $upsertData = [];
        foreach ($data['preferences'] as $type => $isEnabled) {
            $upsertData[] = [
                'user_id' => $user->id,
                'type' => $type,
                'is_enabled' => $isEnabled,
                'is_push_enabled' => $data['push_preferences'][$type] ?? false,
                'days' => $this->joursEncodes($data['days'][$type] ?? null),
            ];
        }

        if ($upsertData !== []) {
            \App\Models\NotificationPreference::upsert(
                $upsertData,
                ['user_id', 'type'],
                ['is_enabled', 'is_push_enabled', 'days']
            );
        }
    }

    /**
     * `upsert` passe a cote des casts du modele : le JSON s'ecrit ici.
     *
     * @param  array<int, int>|null  $jours
     */
    private function joursEncodes(?array $jours): ?string
    {
        if ($jours === null || $jours === []) {
            return null;
        }

        $tries = array_unique($jours);
        sort($tries);

        return json_encode($tries, JSON_THROW_ON_ERROR);
    }
}
