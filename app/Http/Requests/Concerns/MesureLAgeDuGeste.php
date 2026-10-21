<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Support\HeureDeLAppareil;

/**
 * L'âge d'un geste fait sur l'appareil, au départ de la requête (#2049).
 *
 * Un instant pris sur l'appareil (le début ou la fin d'une séance) ne se lit
 * pas tel quel : son horloge peut retarder ou avancer. La requête n'en garde
 * que l'âge, lu sur la seule horloge de l'appareil (`HeureDeLAppareil`), que
 * le contrôleur passe à l'action ; l'action le place sur sa propre lecture de
 * l'horloge (`.ai/rules/horloge.md`). La requête ne lit jamais l'heure du
 * serveur.
 */
trait MesureLAgeDuGeste
{
    /**
     * L'âge du geste en secondes, mesuré avant la validation ; null sans heure
     * de l'appareil au départ, ou quand l'une des deux ne se lit pas.
     */
    private ?int $ageDuGeste = null;

    /**
     * L'âge du geste au départ de la requête, en secondes ; null quand le
     * serveur doit prendre maintenant.
     */
    public function ageDuGeste(): ?int
    {
        return $this->ageDuGeste;
    }

    /**
     * Mesure l'âge de l'instant `$champ` au départ de la requête. Appelée en
     * tête de `prepareForValidation()`, avant que le champ ne soit ramené à
     * l'heure murale de l'application : l'instant reçu, avec son décalage,
     * reste exact dans l'heure que le passage à l'heure d'hiver répète.
     */
    protected function mesurerLAgeDuGeste(string $champ): void
    {
        $this->ageDuGeste = HeureDeLAppareil::ageDe($this->input($champ), $this->header(HeureDeLAppareil::ENTETE));
    }
}
