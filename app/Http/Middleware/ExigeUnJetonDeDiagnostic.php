<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\PorteeDeDiagnostic;
use App\Models\Admin;
use App\Models\JetonDeDiagnostic;
use App\Services\Diagnostic\EnveloppeDeDiagnostic;
use App\Support\AlertesDeDiagnostic;
use App\Support\JournalDuDiagnostic;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * La garde de `/diagnostic/v1` (#2032), sans état d'une requête à l'autre :
 * la configuration, la ligne du jeton, la capacité de son créateur et les
 * limiteurs se relisent à chaque requête, jamais d'une propriété ou d'un
 * singleton.
 *
 * Dans l'ordre : l'interrupteur `DIAGNOSTIC_ENABLED` ; HTTPS en production ;
 * `Authorization: Bearer` de la forme exacte du jeton, et nulle part
 * ailleurs ; la ligne cherchée par l'identifiant et l'empreinte du secret
 * comparée par `hash_equals`, contre une empreinte factice quand
 * l'identifiant est inconnu ; ni révoqué ni expiré ; un créateur qui existe
 * et garde la capacité ; la portée que la route déclare dans `PORTEES`, lue
 * par son nom ; enfin les deux limiteurs du jeton.
 *
 * Tout refus d'avant le jeton, et celui d'une route sans portée, lève un
 * `NotFoundHttpException` nu, que le
 * gestionnaire de `bootstrap/app.php` rend comme il rend un chemin inconnu :
 * même statut, même corps, mêmes en-têtes hors `Date`. Un refus n'écrit rien
 * en base. Une base qui ne répond pas, au jeton comme au créateur, ne peut pas
 * vérifier le jeton : la requête est refusée de même.
 *
 * Après un jeton valide seulement : 403 `portee-non-accordee`, 429 avec
 * `Retry-After`, et toute défaillance (cache, audit, contrôleur) devient une
 * 500 sans détail. Toute réponse porte alors `no-store`, `Vary:
 * Authorization` et `X-Robots-Tag`.
 */
class ExigeUnJetonDeDiagnostic
{
    /** Le jeton, rangé dans les attributs de la requête pour le contrôleur. */
    public const string JETON = 'diagnostic.jeton';

    /** Le nombre d'éléments rendus, que le contrôleur pose pour l'audit. */
    public const string ELEMENTS = 'diagnostic.elements';

    public const int REQUETES_PAR_MINUTE = 30;

    public const int REQUETES_PAR_HEURE = 300;

    public const int MINUTES_ENTRE_DEUX_ECRITURES_D_USAGE = 10;

    /**
     * La portée de chaque route, par son nom : `null` ouvre la route à tout
     * jeton. Une route que cette liste ne nomme est refusée.
     *
     * @var array<string, PorteeDeDiagnostic|null>
     */
    public const array PORTEES = [
        'diagnostic.etat' => null,
        'diagnostic.sante' => PorteeDeDiagnostic::Sante,
    ];

    private const string MOTIF_DE_L_ENTETE = '/^Bearer '.JetonDeDiagnostic::PREFIXE.'(?<identifiant>[a-z0-9]{'.JetonDeDiagnostic::LONGUEUR_DE_L_IDENTIFIANT.'})_(?<secret>[A-Za-z0-9]{'.JetonDeDiagnostic::LONGUEUR_DU_SECRET.'})$/D';

    private const string EMPREINTE_FACTICE = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(private readonly JournalDuDiagnostic $journal)
    {
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $debut = hrtime(true);
        $maintenant = now();
        $entete = $request->headers->get('Authorization');

        if (config('app.diagnostic_enabled') !== true) {
            $this->refuser($request, 'coupe');
        }

        if (app()->isProduction() && ! $request->isSecure()) {
            $this->refuser($request, 'http');
        }

        $jeton = $this->jetonPresente($request, $entete);
        $this->verifierLaVieDuJeton($request, $jeton, $maintenant);

        $createurAutorise = $this->lire($request, static function () use ($jeton): bool {
            $createur = $jeton->createur;

            return $createur instanceof Admin && $createur->estSuperAdministrateur() && $createur->can('view-diagnostic');
        });

        if (! $createurAutorise) {
            $this->refuser($request, 'createur', $jeton);
        }

        $nomDeLaRoute = $request->route()?->getName();

        if (! is_string($nomDeLaRoute) || ! array_key_exists($nomDeLaRoute, self::PORTEES)) {
            $this->refuser($request, 'route-sans-portee', $jeton);
        }

        try {
            $reponse = $this->servir($request, $next, $jeton, $nomDeLaRoute, $maintenant, $debut);
        } catch (Throwable $erreur) {
            report($erreur);

            $reponse = $this->reponseJson(500, 'erreur-interne');
        }

        return $this->sansCache($reponse);
    }

    /**
     * Le jeton que l'en-tête présente, sa ligne trouvée par l'identifiant et
     * son secret exact. Tout autre cas est un refus.
     */
    private function jetonPresente(Request $request, #[\SensitiveParameter] mixed $entete): JetonDeDiagnostic
    {
        if (! is_string($entete)) {
            $this->refuser($request, 'absent');
        }

        if (preg_match(self::MOTIF_DE_L_ENTETE, $entete, $champs) !== 1) {
            $this->refuser($request, 'mal-forme');
        }

        $ligne = $this->lire($request, static fn (): ?JetonDeDiagnostic => JetonDeDiagnostic::query()->where('identifiant', $champs['identifiant'])->first());
        $attendue = $ligne instanceof JetonDeDiagnostic ? $ligne->empreinte : self::EMPREINTE_FACTICE;

        if (! hash_equals($attendue, JetonDeDiagnostic::empreinteDe($champs['secret'])) || ! $ligne instanceof JetonDeDiagnostic) {
            $this->refuser($request, 'inconnu', $ligne);
        }

        return $ligne;
    }

    private function verifierLaVieDuJeton(Request $request, JetonDeDiagnostic $jeton, CarbonInterface $maintenant): void
    {
        $cause = match (true) {
            $jeton->revoque_le instanceof CarbonInterface => 'revoque',
            $jeton->expire_le->lte($maintenant) => 'expire',
            default => null,
        };

        if ($cause !== null) {
            resolve(AlertesDeDiagnostic::class)->jetonInvalidePresente($jeton);

            $this->refuser($request, $cause, $jeton);
        }
    }

    /**
     * Ce que la route rend, une fois le jeton reconnu : la portée, les
     * limiteurs, la trace d'usage, le contrôleur, l'audit.
     *
     * @param  Closure(Request): Response  $next
     */
    private function servir(Request $request, Closure $next, JetonDeDiagnostic $jeton, string $nomDeLaRoute, CarbonInterface $maintenant, int|float $debut): Response
    {
        $portee = self::PORTEES[$nomDeLaRoute];

        if ($portee instanceof PorteeDeDiagnostic && ! in_array($portee->value, $jeton->portees, true)) {
            return $this->audite($request, $jeton, $nomDeLaRoute, $this->reponseJson(403, 'portee-non-accordee'), $debut);
        }

        $attente = $this->tropDeRequetes($jeton);

        if ($attente !== null) {
            resolve(AlertesDeDiagnostic::class)->debitDepasse($jeton);

            return $this->audite($request, $jeton, $nomDeLaRoute, $this->reponseJson(429, 'debit-depasse', ['Retry-After' => (string) $attente]), $debut);
        }

        $this->noterLUsage($jeton, $request->ip(), $maintenant);
        $request->attributes->set(self::JETON, $jeton);

        return $this->audite($request, $jeton, $nomDeLaRoute, $this->nonVide($next($request)), $debut);
    }

    /**
     * Les secondes à attendre quand le jeton a épuisé la minute ou l'heure,
     * sinon `null` : alors les deux compteurs comptent la requête. Un refus
     * ne compte contre aucun des deux.
     */
    private function tropDeRequetes(JetonDeDiagnostic $jeton): ?int
    {
        $parMinute = 'diagnostic:debit:minute:'.$jeton->identifiant;
        $parHeure = 'diagnostic:debit:heure:'.$jeton->identifiant;

        $attentes = array_filter([
            RateLimiter::tooManyAttempts($parMinute, self::REQUETES_PAR_MINUTE) ? RateLimiter::availableIn($parMinute) : null,
            RateLimiter::tooManyAttempts($parHeure, self::REQUETES_PAR_HEURE) ? RateLimiter::availableIn($parHeure) : null,
        ], static fn (?int $secondes): bool => $secondes !== null);

        if ($attentes !== []) {
            return max([1, ...$attentes]);
        }

        RateLimiter::hit($parMinute, 60);
        RateLimiter::hit($parHeure, 3600);

        return null;
    }

    /**
     * Au plus une écriture par jeton et par dix minutes ; la première
     * utilisation du jeton déclenche son alerte.
     */
    private function noterLUsage(JetonDeDiagnostic $jeton, ?string $adresse, CarbonInterface $maintenant): void
    {
        $derniere = $jeton->derniere_utilisation_le;
        $limite = $maintenant->copy()->subMinutes(self::MINUTES_ENTRE_DEUX_ECRITURES_D_USAGE);

        if ($derniere instanceof CarbonInterface && $derniere->gt($limite)) {
            return;
        }

        $ecrit = JetonDeDiagnostic::query()
            ->whereKey($jeton->getKey())
            ->where(static fn ($requete) => $requete->whereNull('derniere_utilisation_le')->orWhere('derniere_utilisation_le', '<=', $limite))
            ->update(['derniere_utilisation_le' => $maintenant, 'derniere_adresse' => $adresse]);

        if ($ecrit === 1 && ! $derniere instanceof CarbonInterface) {
            resolve(AlertesDeDiagnostic::class)->premierUsage($jeton);
        }
    }

    /**
     * Une ligne d'audit, écrite avant la réponse ; si elle ne s'écrit pas, la
     * levée devient la 500 sans donnée de `handle()`.
     */
    private function audite(Request $request, JetonDeDiagnostic $jeton, string $nomDeLaRoute, Response $reponse, int|float $debut): Response
    {
        $elements = $request->attributes->get(self::ELEMENTS);

        $this->journal->appel(
            $jeton,
            $nomDeLaRoute,
            [],
            $reponse->getStatusCode(),
            is_int($elements) ? $elements : 0,
            strlen((string) $reponse->getContent()),
            (int) ((hrtime(true) - $debut) / 1_000_000),
            $request->ip(),
        );

        return $reponse;
    }

    /**
     * Une 500 du contrôleur, que le gestionnaire a rendue avec sa page ou
     * son détail, devient la 500 sans donnée.
     */
    private function nonVide(Response $reponse): Response
    {
        return $reponse->getStatusCode() === 500 ? $this->reponseJson(500, 'erreur-interne') : $reponse;
    }

    /**
     * @param  array<string, string>  $entetes
     */
    private function reponseJson(int $statut, string $erreur, array $entetes = []): JsonResponse
    {
        return new JsonResponse(['erreur' => $erreur, 'note' => EnveloppeDeDiagnostic::NOTE], $statut, $entetes);
    }

    private function sansCache(Response $reponse): Response
    {
        $reponse->headers->set('Cache-Control', 'no-store, private');
        $reponse->headers->set('Vary', 'Authorization');
        $reponse->headers->set('X-Robots-Tag', 'noindex');

        return $reponse;
    }

    /**
     * Une lecture de la base avant le jeton : une base qui ne répond pas
     * refuse la requête, comme n'importe quel autre cas.
     *
     * @template TValeur
     *
     * @param  Closure(): TValeur  $lecture
     * @return TValeur
     */
    private function lire(Request $request, Closure $lecture): mixed
    {
        try {
            return $lecture();
        } catch (Throwable) {
            $this->refuser($request, 'base');
        }
    }

    /**
     * La réponse de tout refus d'avant le jeton : celle d'un chemin inconnu.
     */
    private function refuser(Request $request, string $cause, ?JetonDeDiagnostic $connu = null): never
    {
        $this->journal->refus($cause, $connu, $request->ip());

        throw new NotFoundHttpException();
    }
}
