<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Auth\PrevenirLeTitulaireDeLAdresse;
use App\Actions\CreateUserAction;
use App\Enums\MotifDeLaTentative;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Traits\Localizable;

/**
 * Crée le compte d'une inscription, ou prévient le titulaire de l'adresse
 * qu'elle a déjà, hors de la requête qui l'a demandée.
 *
 * L'inscription (`RegisteredUserController`) répond la même chose que
 * l'adresse soit libre ou prise, dans le même temps, et ne cherche pas le
 * compte : c'est ici qu'on le cherche. Adresse libre, le compte naît en
 * attente (`CreateUserAction`) et le lien qui l'ouvre part à l'adresse ;
 * adresse prise, son titulaire est prévenu (`PrevenirLeTitulaireDeLAdresse`).
 * Le compte se cherche comme l'index unique le juge, selon la collation : une
 * inscription concurrente qui gagne la course passe au cas « adresse prise ».
 *
 * La requête hache le mot de passe dans les deux cas : seule son empreinte
 * voyage, et le cast `hashed` du compte la garde telle quelle. Un job rejoué
 * après avoir créé le compte retrouve son empreinte sur le compte en attente,
 * et ne fait rien : il ne prévient pas l'inscrit de sa propre inscription.
 *
 * Comme `EnvoyerLeLienDeReinitialisation` : chiffré dans la file (adresse,
 * nom et empreinte sont des saisies), mis en file après la validation de la
 * transaction, et traité dans la langue de la requête.
 */
final class TraiterUneInscription implements ShouldBeEncrypted, ShouldQueue
{
    use Localizable;
    use Queueable;

    public function __construct(
        public string $adresse,
        public string $nom,
        public string $empreinte,
        public string $langue,
    ) {
        $this->afterCommit();
    }

    public function handle(CreateUserAction $creerLeCompte, PrevenirLeTitulaireDeLAdresse $prevenirLeTitulaire): void
    {
        $this->withLocale($this->langue, function () use ($creerLeCompte, $prevenirLeTitulaire): void {
            $titulaire = $this->titulaire() ?? $this->creerLeCompte($creerLeCompte);

            if ($titulaire === null || $this->estCetteInscription($titulaire)) {
                return;
            }

            $prevenirLeTitulaire->execute($titulaire, MotifDeLaTentative::Inscription);
        });
    }

    /**
     * Le compte qui occupe déjà l'adresse, selon la collation de l'index.
     */
    private function titulaire(): ?User
    {
        return User::query()->where('email', $this->adresse)->first();
    }

    /**
     * Crée le compte en attente ; rend null quand il est créé, ou le compte
     * d'une inscription concurrente arrivée juste avant.
     */
    private function creerLeCompte(CreateUserAction $creerLeCompte): ?User
    {
        try {
            $creerLeCompte->execute([
                'name' => $this->nom,
                'email' => $this->adresse,
                'password' => $this->empreinte,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->titulaire();
        }

        return null;
    }

    /**
     * Ce compte est-il celui que ce job a déjà créé (un rejeu) ?
     */
    private function estCetteInscription(User $titulaire): bool
    {
        return $titulaire->estEnAttente() && hash_equals((string) $titulaire->getAuthPassword(), $this->empreinte);
    }
}
