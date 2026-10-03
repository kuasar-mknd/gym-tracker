<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Models\NotificationPreference;
use App\Models\User;

/**
 * Allume l'envoi push de quelques types de notification, et rien d'autre.
 *
 * S'abonner ne suffit pas à recevoir : `PersonalRecordAchieved` ne part en push
 * que si la préférence du type le dit, et un compte neuf n'a aucune ligne de
 * préférence. Le profil y pourvoit en renvoyant tout son formulaire ; une
 * invitation hors du profil n'a pas ce formulaire, et
 * `UpdateNotificationPreferencesAction` réécrit `is_enabled`, `is_push_enabled`
 * et `days` de chaque type qu'on lui nomme.
 *
 * Ici, une ligne existante ne voit changer que son envoi push : un type que
 * l'utilisateur a coupé dans le profil le reste, et ses jours de rappel aussi.
 * Une ligne neuve naît activée, la valeur que le profil affiche à un compte
 * qui n'a encore rien réglé. Une seule instruction, comme l'action voisine.
 */
final class ActiverLesEnvoisPushAction
{
    /**
     * @param  list<string>  $types
     */
    public function execute(User $user, array $types): void
    {
        NotificationPreference::upsert(
            array_map(fn (string $type): array => [
                'user_id' => $user->id,
                'type' => $type,
                'is_enabled' => true,
                'is_push_enabled' => true,
            ], $types),
            ['user_id', 'type'],
            ['is_push_enabled'],
        );
    }
}
