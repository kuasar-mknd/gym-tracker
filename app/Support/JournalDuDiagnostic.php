<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\JetonDeDiagnostic;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * L'audit de l'accès de diagnostic (#2032).
 *
 * `appel()` écrit, dans le canal `diagnostic`, une ligne par appel servi, avant
 * la réponse, et lève si l'écriture échoue : la garde répond alors une 500 sans
 * donnée. Son contexte ne porte que l'identifiant et le nom du jeton, la
 * route, les paramètres validés, le statut, des nombres, une durée et
 * l'adresse : jamais le jeton, jamais un texte venu de l'extérieur.
 *
 * `refus()` écrit dans le canal `diagnostic_refus`, qui ne lève jamais : au
 * plus une ligne par identifiant connu et par minute, et une ligne de
 * synthèse par minute, avec le compte des refus anonymes de la minute
 * précédente. La cause est d'un vocabulaire fermé ; un texte présenté par le
 * client n'y entre pas.
 */
final class JournalDuDiagnostic
{
    public const string CANAL = 'diagnostic';

    public const string CANAL_DES_REFUS = 'diagnostic_refus';

    private const int SECONDES_DES_COMPTEURS = 180;

    /**
     * @param  array<string, mixed>  $parametres  les paramètres validés de la requête
     */
    public function appel(
        JetonDeDiagnostic $jeton,
        string $route,
        array $parametres,
        int $statut,
        int $elements,
        int $octets,
        int $dureeEnMillisecondes,
        ?string $adresse,
    ): void {
        Log::channel(self::CANAL)->info('diagnostic.appel', [
            'jeton' => $jeton->identifiant,
            'nom' => $jeton->nom,
            'route' => $route,
            'parametres' => $parametres,
            'statut' => $statut,
            'elements' => $elements,
            'octets' => $octets,
            'duree_ms' => $dureeEnMillisecondes,
            'adresse' => $adresse,
        ]);
    }

    /**
     * Le même travail de cache pour tout refus, d'un identifiant connu ou
     * non : la durée ne dit pas si l'identifiant existe. Seule la clef du
     * compteur change (`connus` ou `anonymes`), pas le nombre d'opérations.
     */
    public function refus(string $cause, ?JetonDeDiagnostic $connu, ?string $adresse): void
    {
        if (self::lEcritureSeraitEnSql()) {
            return;
        }

        rescue(function () use ($cause, $connu, $adresse): void {
            $maintenant = now();
            $minute = $maintenant->format('Y-m-d\TH:i');
            $precedente = $maintenant->copy()->subMinute()->format('Y-m-d\TH:i');

            $cleDuCompteur = 'diagnostic:refus:'.($connu instanceof JetonDeDiagnostic ? 'connus' : 'anonymes').':'.$minute;
            Cache::add($cleDuCompteur, 0, self::SECONDES_DES_COMPTEURS);
            Cache::increment($cleDuCompteur);

            $premierDeLaLigne = Cache::add('diagnostic:refus:jeton:'.($connu instanceof JetonDeDiagnostic ? $connu->identifiant : 'anonyme').':'.$minute, true, self::SECONDES_DES_COMPTEURS);
            $premierDeLaSynthese = Cache::add('diagnostic:refus:synthese:'.$minute, true, self::SECONDES_DES_COMPTEURS);
            $compte = Cache::get('diagnostic:refus:anonymes:'.$precedente);

            if ($connu instanceof JetonDeDiagnostic && $premierDeLaLigne) {
                Log::channel(self::CANAL_DES_REFUS)->info('diagnostic.refus', ['jeton' => $connu->identifiant, 'cause' => $cause, 'adresse' => $adresse]);
            }

            if ($premierDeLaSynthese) {
                Log::channel(self::CANAL_DES_REFUS)->info('diagnostic.refus.synthese', [
                    'minute_precedente' => $precedente,
                    'refus_anonymes' => is_int($compte) ? $compte : 0,
                ]);
            }
        }, null, false);
    }

    /**
     * Sur un cache `database` (ou une bascule qui le nomme), chaque compteur
     * serait une écriture SQL par requête non authentifiée : le
     * refus n'est alors ni compté ni journalisé. Un cache en mémoire ne
     * remplace pas Redis, Octane le vide à chaque requête.
     */
    public static function lEcritureSeraitEnSql(): bool
    {
        return array_any(self::nomsDesMagasinsDuDefaut(), self::estEnSql(...));
    }

    /**
     * @return list<string>
     */
    private static function nomsDesMagasinsDuDefaut(): array
    {
        $defaut = config('cache.default');

        if (! is_string($defaut)) {
            return [];
        }

        $noms = config('cache.stores.'.$defaut.'.driver') === 'failover' ? config('cache.stores.'.$defaut.'.stores') : [$defaut];

        return array_values(array_filter(is_array($noms) ? $noms : [], is_string(...)));
    }

    private static function estEnSql(string $nom): bool
    {
        return config('cache.stores.'.$nom.'.driver') === 'database';
    }
}
