<?php

declare(strict_types=1);

namespace App\Models\Traits;

use App\Actions\Diagnostic\RevoqueLesJetonsDeDiagnosticAction;
use App\Enums\RaisonDeRevocationDuJeton;
use App\Models\Admin;

/**
 * Ce qui, chez un administrateur, révoque les jetons de diagnostic qu'il a
 * créés (#2032) : un mot de passe changé, la double authentification retirée
 * ou remplacée, l'administrateur supprimé.
 *
 * Dans l'évènement du modèle plutôt que dans chaque écran : chaque chemin,
 * présent ou futur (panneau, commande, console), change un mot de passe ou
 * retire un secret, et aucun n'a à y penser. Une suppression en masse
 * (`Admin::query()->delete()`) n'émet pas l'évènement : ses jetons restent actifs
 * sans créateur, jusqu'à leur expiration ou `diagnostic:revoquer --tous`.
 */
trait RevoqueLesJetonsDeDiagnostic
{
    public static function bootRevoqueLesJetonsDeDiagnostic(): void
    {
        static::updated(static function (Admin $admin): void {
            if ($admin->wasChanged('password')) {
                self::revoquerSesJetons($admin, RaisonDeRevocationDuJeton::MotDePasseChange);
            }

            if ($admin->wasChanged('app_authentication_secret')) {
                self::revoquerSurChangementDuSecret($admin);
            }
        });

        static::deleting(static function (Admin $admin): void {
            self::revoquerSesJetons($admin, RaisonDeRevocationDuJeton::AdministrateurSupprime);
        });
    }

    private static function revoquerSesJetons(Admin $admin, RaisonDeRevocationDuJeton $raison): void
    {
        resolve(RevoqueLesJetonsDeDiagnosticAction::class)->execute($raison, null, null, $admin->id);
    }

    private static function revoquerSurChangementDuSecret(Admin $admin): void
    {
        if (! self::aUnSecret($admin->getAppAuthenticationSecret())) {
            self::revoquerSesJetons($admin, RaisonDeRevocationDuJeton::DoubleAuthentificationRetiree);

            return;
        }

        if (self::aUnSecret($admin->getPrevious()['app_authentication_secret'] ?? null)) {
            self::revoquerSesJetons($admin, RaisonDeRevocationDuJeton::DoubleAuthentificationChangee);
        }
    }

    private static function aUnSecret(mixed $secret): bool
    {
        return is_string($secret) && $secret !== '';
    }
}
