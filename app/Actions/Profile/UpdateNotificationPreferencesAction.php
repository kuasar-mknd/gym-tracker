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
        foreach ($data['preferences'] as $type => $isEnabled) {
            $user->notificationPreferences()->updateOrCreate(
                ['type' => $type],
                [
                    'is_enabled' => $isEnabled,
                    'is_push_enabled' => $data['push_preferences'][$type] ?? false,
                    'value' => $data['values'][$type] ?? null,
                ]
            );
        }
    }
}
