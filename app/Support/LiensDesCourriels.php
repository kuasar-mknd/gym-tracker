<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use LogicException;

/**
 * Les liens absolus des courriels d'authentification, bâtis sur `APP_URL`
 * quel que soit l'hôte de la requête qui déclenche l'envoi.
 *
 * Laravel bâtit par défaut le lien de réinitialisation et celui de
 * vérification d'adresse sur la racine de la requête courante : l'hôte qu'elle
 * annonce, ou qu'un proxy de confiance transmet, devenait celui du lien que
 * reçoit l'utilisateur, jeton compris. L'application ne sert qu'une adresse
 * publique, celle d'`APP_URL` : les liens n'en prennent pas d'autre.
 *
 * Le lien de vérification est signé, et la signature couvre l'URL complète,
 * hôte compris. Elle est donc calculée sur l'URL bâtie sur `APP_URL`, celle
 * que l'utilisateur suivra : le middleware `signed` de la route la valide
 * quand on la suit sous `APP_URL`, et la refuse sous un autre hôte.
 *
 * Les deux rappels sont posés une fois au démarrage, dans des propriétés
 * statiques de Laravel : sous Octane, ils servent toutes les requêtes d'un
 * worker. Ils ne capturent rien, et relisent configuration et générateur
 * d'URL à chaque appel. Le générateur de la requête n'est jamais modifié :
 * le lien se bâtit sur une copie.
 */
final class LiensDesCourriels
{
    /**
     * Le lien du courriel « mot de passe oublié », le même que celui de
     * Laravel, racine mise à part.
     */
    public static function lienDeReinitialisation(mixed $notifiable, string $jeton): string
    {
        if (! $notifiable instanceof CanResetPassword) {
            throw new LogicException('Le lien de réinitialisation se bâtit pour un compte qui peut changer son mot de passe.');
        }

        return self::generateurSurAppUrl()->route('password.reset', [
            'token' => $jeton,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
    }

    /**
     * Le lien signé du courriel de vérification d'adresse, le même que celui
     * de Laravel (route, durée, paramètres), racine mise à part.
     */
    public static function lienDeVerification(mixed $notifiable): string
    {
        if (! $notifiable instanceof MustVerifyEmail || ! $notifiable instanceof Model) {
            throw new LogicException('Le lien de vérification se bâtit pour un compte enregistré qui vérifie son adresse.');
        }

        return self::generateurSurAppUrl()->temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(self::minutesDeValiditeDUnLien()),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ],
        );
    }

    /**
     * Le lien signé du courriel qui ouvre un compte créé par l'inscription
     * (`LienDOuvertureDuCompte`) : même durée et mêmes paramètres que le lien
     * de vérification, vers la route des invités `inscription.ouvrir`, qui
     * mène à la connexion.
     */
    public static function lienDOuvertureDuCompte(User $compte): string
    {
        return self::generateurSurAppUrl()->temporarySignedRoute(
            'inscription.ouvrir',
            Carbon::now()->addMinutes(self::minutesDeValiditeDUnLien()),
            [
                'id' => $compte->getKey(),
                'hash' => sha1($compte->getEmailForVerification()),
            ],
        );
    }

    /**
     * Le lien signé du courriel qui confirme la nouvelle adresse demandée
     * depuis le profil (`LienDeChangementDAdresse`). Il nomme le compte, la
     * nouvelle adresse et l'empreinte de l'adresse actuelle : un lien émis
     * avant un autre changement ne vaut plus.
     *
     * La nouvelle adresse n'y paraît que chiffrée (`Crypt::encryptString()`),
     * sous la signature : un lien suivi en GET finit dans les journaux
     * d'accès, l'historique du navigateur, l'adresse que la connexion garde
     * pour après (`url.intended`) et, si la requête échoue, la trace de
     * l'exception. `ConfirmerLAdresseController` la lit par
     * `adresseDuLienDeConfirmation()`.
     */
    public static function lienDeConfirmationDAdresse(User $compte, string $nouvelleAdresse): string
    {
        return self::generateurSurAppUrl()->temporarySignedRoute(
            'adresse.confirmer',
            Carbon::now()->addMinutes(self::minutesDeValiditeDUnLien()),
            [
                'compte' => $compte->getKey(),
                'adresse' => Crypt::encryptString($nouvelleAdresse),
                'actuelle' => sha1($compte->email),
            ],
        );
    }

    /**
     * La nouvelle adresse que porte, chiffrée, le lien de confirmation suivi
     * (`lienDeConfirmationDAdresse()`). Une valeur qui ne se déchiffre pas
     * refuse le lien comme une signature fausse (403).
     *
     * @throws InvalidSignatureException
     */
    public static function adresseDuLienDeConfirmation(Request $requete): string
    {
        try {
            return Crypt::decryptString($requete->string('adresse')->toString());
        } catch (DecryptException) {
            throw new InvalidSignatureException();
        }
    }

    /**
     * La page de connexion, pour les avis qui y renvoient.
     */
    public static function lienDeConnexion(): string
    {
        return self::generateurSurAppUrl()->route('login');
    }

    /**
     * La durée de validité d'un lien signé, en minutes : celle du lien de
     * vérification de Laravel (`auth.verification.expire`, 60 par défaut).
     */
    public static function minutesDeValiditeDUnLien(): int
    {
        $duree = config('auth.verification.expire', 60);

        return is_numeric($duree) ? (int) $duree : 60;
    }

    /**
     * Une copie du générateur d'URL de l'application, dont la racine est
     * `APP_URL`.
     *
     * La copie garde les routes, les paramètres par défaut et les clés de
     * signature de l'original ; `setRequest()` lui donne une requête bâtie sur
     * `APP_URL` et oublie le générateur de routes de l'original, qui lirait
     * sinon sa racine à lui. `useOrigin()` garde un éventuel chemin d'`APP_URL`.
     */
    private static function generateurSurAppUrl(): UrlGenerator
    {
        $configuree = config('app.url');
        $racine = is_string($configuree) ? $configuree : '';

        $generateur = clone app(UrlGenerator::class);
        $generateur->setRequest(Request::create($racine));
        $generateur->useOrigin($racine);

        return $generateur;
    }
}
