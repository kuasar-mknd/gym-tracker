<?php

declare(strict_types=1);

namespace App\Actions\Diagnostic;

use App\Enums\RaisonDeRevocationDuJeton;
use App\Models\Admin;
use App\Models\JetonDeDiagnostic;

/**
 * Révoque des jetons de diagnostic (#2032), toujours immédiatement : la
 * ligne est relue à chaque appel. Un jeton déjà révoqué garde sa première
 * raison ; l'action ne rend que ceux qu'elle vient de révoquer.
 */
final readonly class RevoqueLesJetonsDeDiagnosticAction
{
    /**
     * Les jetons actifs, tous, ceux d'un identifiant ou ceux d'un créateur.
     *
     * @return list<JetonDeDiagnostic> ceux que cet appel a révoqués
     */
    public function execute(
        RaisonDeRevocationDuJeton $raison,
        ?Admin $par = null,
        ?string $identifiant = null,
        ?int $creeParAdminId = null,
    ): array {
        $instant = now();
        $revoques = [];

        $requete = JetonDeDiagnostic::query()->actifs($instant);

        if ($identifiant !== null) {
            $requete->where('identifiant', $identifiant);
        }

        if ($creeParAdminId !== null) {
            $requete->where('cree_par_admin_id', $creeParAdminId);
        }

        foreach ($requete->get() as $jeton) {
            if ($jeton->revoquer($raison, $par, $instant)) {
                $revoques[] = $jeton;
            }
        }

        return $revoques;
    }
}
