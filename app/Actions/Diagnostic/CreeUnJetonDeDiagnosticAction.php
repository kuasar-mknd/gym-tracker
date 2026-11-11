<?php

declare(strict_types=1);

namespace App\Actions\Diagnostic;

use App\Enums\PorteeDeDiagnostic;
use App\Models\Admin;
use App\Models\JetonDeDiagnostic;
use Carbon\CarbonInterface;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Crée un jeton de diagnostic (#2032), ou refuse, côté serveur, quoi que
 * l'interface ait laissé passer.
 *
 * Refus, dans l'ordre : l'interrupteur coupé ; la capacité ou le rôle de
 * super administrateur absents ; la double authentification non activée ;
 * `HEALTH_TO_ADDRESS` vide (une alerte qui ne part nulle part ne protège
 * rien) ; une durée ou des portées hors liste ; cinq essais de code dans la
 * minute ; un code faux ou déjà accepté ; deux jetons déjà actifs.
 *
 * Le jeton en clair n'est rendu qu'ici, une fois : l'appelant le garde
 * chiffré (`encrypt()`) le temps d'un rendu, jamais dans un champ de
 * formulaire, la session, une notification ni un journal.
 *
 * @throws \Illuminate\Contracts\Cache\LockTimeoutException Si le verrou de création reste pris plus de `ATTENTE_DU_VERROU` secondes.
 */
final readonly class CreeUnJetonDeDiagnosticAction
{
    /** Les essais de code par administrateur et par minute. */
    public const int ESSAIS_PAR_MINUTE = 5;

    /** Combien de secondes la création attend le verrou, puis le tient au plus. */
    public const int ATTENTE_DU_VERROU = 5;

    public const int VIE_DU_VERROU = 10;

    /**
     * @param  list<PorteeDeDiagnostic>  $portees
     * @return array{jeton: JetonDeDiagnostic, en_clair: string}
     */
    public function execute(
        Admin $createur,
        string $nom,
        array $portees,
        int $jours,
        #[SensitiveParameter]
        string $codeTotp,
    ): array {
        if (config('app.diagnostic_enabled') !== true) {
            throw new CreationDeJetonRefusee(CreationDeJetonRefusee::INTERRUPTEUR_COUPE);
        }

        if (! $createur->estSuperAdministrateur() || ! $createur->can('view-diagnostic')) {
            throw new CreationDeJetonRefusee(CreationDeJetonRefusee::CAPACITE_ABSENTE);
        }

        $secretTotp = $createur->getAppAuthenticationSecret();

        if (! is_string($secretTotp) || $secretTotp === '') {
            throw new CreationDeJetonRefusee(CreationDeJetonRefusee::DOUBLE_AUTHENTIFICATION_ABSENTE);
        }

        if (config('health.notifications.mail.to') === '' || config('health.notifications.mail.to') === []) {
            throw new CreationDeJetonRefusee(CreationDeJetonRefusee::ADRESSE_DES_ALERTES_VIDE);
        }

        $this->verifierLaDemande($portees, $jours);
        $this->verifierLeCode($createur, $secretTotp, $codeTotp);

        $instant = now();

        /** @var array{jeton: JetonDeDiagnostic, en_clair: string} $cree */
        $cree = Cache::lock('diagnostic:creation', self::VIE_DU_VERROU)
            ->block(self::ATTENTE_DU_VERROU, fn (): array => DB::transaction(fn (): array => $this->creerSousLeVerrou($createur, $nom, $portees, $jours, $instant)));

        return $cree;
    }

    /**
     * @param  list<PorteeDeDiagnostic>  $portees
     * @return array{jeton: JetonDeDiagnostic, en_clair: string}
     */
    private function creerSousLeVerrou(Admin $createur, string $nom, array $portees, int $jours, CarbonInterface $instant): array
    {
        if (JetonDeDiagnostic::query()->actifs($instant)->lockForUpdate()->count() >= JetonDeDiagnostic::ACTIFS_AU_PLUS) {
            throw new CreationDeJetonRefusee(CreationDeJetonRefusee::JETONS_ACTIFS_AU_MAXIMUM);
        }

        $identifiant = Str::lower(Str::random(JetonDeDiagnostic::LONGUEUR_DE_L_IDENTIFIANT));
        $secret = Str::random(JetonDeDiagnostic::LONGUEUR_DU_SECRET);

        $jeton = JetonDeDiagnostic::query()->create([
            'identifiant' => $identifiant,
            'empreinte' => JetonDeDiagnostic::empreinteDe($secret),
            'nom' => Str::limit(trim($nom), 80, ''),
            'portees' => array_map(static fn (PorteeDeDiagnostic $portee): string => $portee->value, $portees),
            'cree_par_admin_id' => $createur->id,
            'expire_le' => $instant->copy()->addDays($jours),
        ]);

        return ['jeton' => $jeton, 'en_clair' => JetonDeDiagnostic::composer($identifiant, $secret)];
    }

    /**
     * @param  list<PorteeDeDiagnostic>  $portees
     */
    private function verifierLaDemande(array $portees, int $jours): void
    {
        if ($portees === [] || array_unique($portees, SORT_REGULAR) !== $portees) {
            throw new CreationDeJetonRefusee(CreationDeJetonRefusee::PORTEES_INVALIDES);
        }

        $maximum = in_array(PorteeDeDiagnostic::TexteBrut, $portees, true)
            ? JetonDeDiagnostic::DUREE_AVEC_TEXTE_BRUT
            : max(JetonDeDiagnostic::DUREES);

        if (! in_array($jours, JetonDeDiagnostic::DUREES, true) || $jours > $maximum) {
            throw new CreationDeJetonRefusee(CreationDeJetonRefusee::DUREE_INVALIDE);
        }
    }

    private function verifierLeCode(Admin $createur, string $secretTotp, #[SensitiveParameter] string $codeTotp): void
    {
        $cle = 'diagnostic:creation:'.$createur->id;

        if (RateLimiter::tooManyAttempts($cle, self::ESSAIS_PAR_MINUTE)) {
            throw new CreationDeJetonRefusee(CreationDeJetonRefusee::TROP_D_ESSAIS);
        }

        RateLimiter::hit($cle, 60);

        if (! AppAuthentication::make()->verifyCode($codeTotp, $secretTotp, shouldPreventCodeReuse: true)) {
            throw new CreationDeJetonRefusee(CreationDeJetonRefusee::CODE_REFUSE);
        }
    }
}
