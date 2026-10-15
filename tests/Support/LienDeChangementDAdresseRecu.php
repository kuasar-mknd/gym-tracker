<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Notifications\LienDeChangementDAdresse;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

/**
 * Le lien de confirmation qu'une nouvelle adresse demandée depuis le profil a
 * reçu, lu dans les avis simulés (`Notification::fake()`).
 *
 * L'adresse d'un compte ne change plus au `PATCH /profile` : seulement quand le
 * lien envoyé à la nouvelle adresse est suivi, depuis une session du compte
 * (`ConfirmerLAdresseController`). Un test qui change une adresse demande donc
 * le changement, puis suit ce lien.
 */
final class LienDeChangementDAdresseRecu
{
    /**
     * Le dernier lien parti vers cette adresse ; le test échoue s'il n'y en a pas.
     */
    public static function pour(string $adresse): string
    {
        $liens = [];

        Notification::assertSentOnDemand(
            LienDeChangementDAdresse::class,
            static function (LienDeChangementDAdresse $avis, array $_canaux, AnonymousNotifiable $destinataire) use ($adresse, &$liens): bool {
                if (($destinataire->routes['mail'] ?? null) !== $adresse) {
                    return false;
                }

                $liens[] = $avis->lien;

                return true;
            },
        );

        return (string) end($liens);
    }
}
