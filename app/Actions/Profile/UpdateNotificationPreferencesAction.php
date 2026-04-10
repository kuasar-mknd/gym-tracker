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
     *     values?: array<string, mixed>
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
                'value' => $data['values'][$type] ?? null,
            ];
        }

        if ($upsertData !== []) {
            \App\Models\NotificationPreference::upsert(
                $upsertData,
                ['user_id', 'type'],
                ['is_enabled', 'is_push_enabled', 'value']
            );
        }
    }
}
