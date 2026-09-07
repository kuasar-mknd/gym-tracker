<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;

/**
 * Le jeu de plaques d'une salle ordinaire, posé à la création du compte.
 *
 * Sans inventaire, le calculateur ouvrait sur « Impossible de charger ce poids
 * avec les plaques disponibles » — un message d'échec comme premier contact,
 * alors que rien n'indiquait qu'il fallait d'abord déclarer ses plaques
 * (#1799). Ce sont les disques olympiques par paire ; qui a autre chose les
 * modifie dans « Mon inventaire ».
 */
final class CreerLesPlaquesParDefautAction
{
    /**
     * Poids en kilos, chacun en paire — une plaque de chaque côté de la barre.
     *
     * @var list<float>
     */
    public const array POIDS = [25.0, 20.0, 15.0, 10.0, 5.0, 2.5, 1.25];

    public function execute(User $utilisateur): void
    {
        if ($utilisateur->plates()->exists()) {
            return;
        }

        $utilisateur->plates()->createMany(
            array_map(static fn (float $poids): array => ['weight' => $poids, 'quantity' => 2], self::POIDS)
        );
    }
}
