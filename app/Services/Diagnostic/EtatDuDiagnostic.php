<?php

declare(strict_types=1);

namespace App\Services\Diagnostic;

use App\Enums\PorteeDeDiagnostic;
use App\Models\JetonDeDiagnostic;
use App\Services\Diagnostic\Projections\ProjectionDeLaSante;
use App\Services\Diagnostic\Projections\ProjectionDesErreursNavigateur;
use App\Services\Diagnostic\Projections\ProjectionDesExceptions;
use App\Services\Diagnostic\Projections\ProjectionDesFiles;
use App\Services\Diagnostic\Projections\ProjectionDesTachesPlanifiees;
use App\Services\Diagnostic\Projections\ProjectionDesVersions;
use App\Support\AlertesDeDiagnostic;
use Carbon\CarbonInterface;

/**
 * La réponse de `GET /diagnostic/v1/etat` (#2032) : l'application, le jeton
 * qui lit, puis, pour chaque source que ses portées ouvrent, le plus court
 * résumé des vingt-quatre dernières heures. Les mêmes projections que le
 * résumé, donc la même structure fermée : aucun texte libre, `TexteBrut`
 * compris, et quelques groupes seulement par source. Chaque source ouverte
 * rend aussi son compte sur sept jours (`ComptesSur7Jours`, gardé 60 s sous une
 * clef par source).
 */
final class EtatDuDiagnostic
{
    public const int GROUPES_DES_EXCEPTIONS = 5;

    public const int GROUPES_DES_ERREURS_NAVIGATEUR = 3;

    public const int ECHECS_DES_FILES = 5;

    public const int CONTROLES_ET_TACHES_AU_PLUS = 10;

    public function __invoke(JetonDeDiagnostic $jeton, CarbonInterface $maintenant): EnveloppeRendue
    {
        $fenetre = FenetreDeDiagnostic::depuis(null, $maintenant);
        $portees = array_values(array_filter(array_map(
            PorteeDeDiagnostic::tryFrom(...),
            $jeton->portees,
        )));

        $donnees = [
            'application' => new ProjectionDesVersions()->application(),
            'jeton' => [
                'fin' => AlertesDeDiagnostic::fin($jeton),
                'portees' => array_map(static fn (PorteeDeDiagnostic $portee): string => $portee->value, $portees),
                'expire_le' => ValeurLue::instant($jeton->expire_le),
            ],
        ];

        foreach (PorteeDeDiagnostic::SOURCES as $portee) {
            if (in_array($portee, $portees, true)) {
                $donnees = [...$donnees, ...$this->section($portee, $fenetre)];
            }
        }

        return new EnveloppeDeDiagnostic()(
            $donnees,
            $fenetre->jusqua,
            tronque: EnveloppeDeDiagnostic::signale($donnees, 'tronque'),
            echantillon: EnveloppeDeDiagnostic::signale($donnees, 'echantillon'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function section(PorteeDeDiagnostic $portee, FenetreDeDiagnostic $fenetre): array
    {
        return match ($portee) {
            PorteeDeDiagnostic::Sante => $this->sections($fenetre),
            PorteeDeDiagnostic::Exceptions => ['exceptions' => $this->groupes(new ProjectionDesExceptions()->liste($fenetre), 'groupes', self::GROUPES_DES_EXCEPTIONS, $portee, $fenetre)],
            PorteeDeDiagnostic::Files => ['files' => $this->groupes(new ProjectionDesFiles()->liste($fenetre), 'echecs', self::ECHECS_DES_FILES, $portee, $fenetre)],
            PorteeDeDiagnostic::ErreursNavigateur => ['erreurs_navigateur' => $this->groupes(new ProjectionDesErreursNavigateur()->liste($fenetre), 'groupes', self::GROUPES_DES_ERREURS_NAVIGATEUR, $portee, $fenetre)],
            PorteeDeDiagnostic::TexteBrut => [],
        };
    }

    /**
     * La santé : les versions, les contrôles, les tâches, et le compte de leurs
     * échecs sur sept jours, tiré de la liste lue pour elles (les 50 premières
     * tâches) : ni lecture ni clef de cache de plus.
     *
     * @return array<string, mixed>
     */
    private function sections(FenetreDeDiagnostic $fenetre): array
    {
        $liste = new ProjectionDesTachesPlanifiees()->liste($fenetre);

        return [
            'versions' => new ProjectionDesVersions()->liste(),
            'sante' => $this->sante($fenetre),
            'taches_planifiees' => $this->taches($liste),
            'echecs_de_taches_sur_7_jours' => ['total' => $liste['lignes_lues'], 'plafonne' => $liste['echantillon']],
        ];
    }

    /**
     * Le dernier passage, et les contrôles qui ne sont pas au vert.
     *
     * @return array<string, mixed>
     */
    private function sante(FenetreDeDiagnostic $fenetre): array
    {
        $passage = new ProjectionDeLaSante()->liste($fenetre);
        $horsDuVert = array_values(array_filter(
            $passage['controles'],
            static fn (array $controle): bool => $controle['statut'] !== 'ok',
        ));

        return [
            'dernier_passage_le' => $passage['dernier_passage_le'],
            'age_en_secondes' => $passage['age_en_secondes'],
            'controles_hors_du_vert' => array_slice($horsDuVert, 0, self::CONTROLES_ET_TACHES_AU_PLUS),
            'tronque' => count($horsDuVert) > self::CONTROLES_ET_TACHES_AU_PLUS,
        ];
    }

    /**
     * Les tâches en retard ou échouées, sans le détail de leurs échecs.
     *
     * @param  array{taches: array<int, array<string, mixed>>, lignes_lues: int, echantillon: bool, tronque: bool}  $liste
     * @return array<string, mixed>
     */
    private function taches(array $liste): array
    {
        $enDifficulte = array_values(array_filter(
            $liste['taches'],
            static fn (array $tache): bool => $tache['etat'] !== 'a_l_heure',
        ));

        return [
            'taches' => array_map(static fn (array $tache): array => [
                'commande' => $tache['commande'],
                'etat' => $tache['etat'],
                'dernier_echec_le' => $tache['dernier_echec_le'],
                'echecs_sur_7_jours' => $tache['echecs_sur_7_jours'],
            ], array_slice($enDifficulte, 0, self::CONTROLES_ET_TACHES_AU_PLUS)),
            'echantillon' => $liste['echantillon'],
            'tronque' => $liste['tronque'] || count($enDifficulte) > self::CONTROLES_ET_TACHES_AU_PLUS,
        ];
    }

    /**
     * La liste de la projection, réduite à ses premiers groupes.
     *
     * @param  array<string, mixed>  $liste
     * @return array<string, mixed>
     */
    private function groupes(array $liste, string $cle, int $auPlus, PorteeDeDiagnostic $source, FenetreDeDiagnostic $fenetre): array
    {
        $groupes = is_array($liste[$cle] ?? null) ? array_values($liste[$cle]) : [];

        return [
            ...array_diff_key($liste, ['echecs_par_file' => true]),
            $cle => array_slice($groupes, 0, $auPlus),
            'tronque' => ($liste['tronque'] ?? false) === true || count($groupes) > $auPlus,
            'sur_7_jours' => new ComptesSur7Jours()->de($source, $fenetre->jusqua),
        ];
    }
}
