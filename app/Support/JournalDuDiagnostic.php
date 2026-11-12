<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\JetonDeDiagnostic;
use Carbon\CarbonInterface;
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
 * synthèse par minute pour les refus anonymes, avec le compte de la minute
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

    public function refus(string $cause, ?JetonDeDiagnostic $connu, ?string $adresse): void
    {
        rescue(function () use ($cause, $connu, $adresse): void {
            $maintenant = now();
            $minute = $maintenant->format('Y-m-d\TH:i');

            if ($connu instanceof JetonDeDiagnostic) {
                if (Cache::add('diagnostic:refus:jeton:'.$connu->identifiant.':'.$minute, true, self::SECONDES_DES_COMPTEURS)) {
                    Log::channel(self::CANAL_DES_REFUS)->info('diagnostic.refus', ['jeton' => $connu->identifiant, 'cause' => $cause, 'adresse' => $adresse]);
                }

                return;
            }

            $this->compterUnRefusAnonyme($maintenant);
        }, null, false);
    }

    private function compterUnRefusAnonyme(CarbonInterface $maintenant): void
    {
        $minute = $maintenant->format('Y-m-d\TH:i');
        $cle = 'diagnostic:refus:anonymes:'.$minute;
        Cache::add($cle, 0, self::SECONDES_DES_COMPTEURS);
        Cache::increment($cle);

        if (Cache::add('diagnostic:refus:synthese:'.$minute, true, self::SECONDES_DES_COMPTEURS)) {
            $precedente = $maintenant->copy()->subMinute()->format('Y-m-d\TH:i');
            $compte = Cache::get('diagnostic:refus:anonymes:'.$precedente);

            Log::channel(self::CANAL_DES_REFUS)->info('diagnostic.refus.synthese', [
                'minute_precedente' => $precedente,
                'refus_anonymes' => is_int($compte) ? $compte : 0,
            ]);
        }
    }
}
