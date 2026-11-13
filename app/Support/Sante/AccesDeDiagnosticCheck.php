<?php

declare(strict_types=1);

namespace App\Support\Sante;

use App\Models\JetonDeDiagnostic;
use App\Support\AlertesDeDiagnostic;
use App\Support\MagasinDurable;
use Illuminate\Support\Carbon;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * L'état de l'accès de diagnostic (#2032) : « Fermé », « N jeton(s) actif(s)
 * jusqu'au … » (la plus lointaine des expirations), ou « Ouvert, aucun jeton
 * actif » quand l'accès est ouvert sans jeton.
 *
 * Orange pendant vingt-quatre heures après la présentation d'un jeton révoqué
 * ou expiré (la trace que pose `AlertesDeDiagnostic::jetonInvalidePresente()`,
 * dans le cache : une trace évincée finit l'orange plus tôt), quand l'accès
 * est ouvert sans `HEALTH_TO_ADDRESS` (aucune alerte ne partirait), et quand
 * il reste des jetons actifs alors que l'accès est fermé. Ni rouge ni
 * courriel : l'orange se lit sur la page « Santé ». Une lecture de la base,
 * aucune écriture, et le texte des jetons n'y paraît pas.
 */
final class AccesDeDiagnosticCheck extends Check
{
    /**
     * Le libellé de la page « Santé » et du courriel d'alerte, qui
     * découperaient sinon le nom de classe.
     */
    #[\Override]
    protected ?string $label = 'Accès de diagnostic';

    #[\Override]
    public function getName(): string
    {
        return 'AccesDeDiagnostic';
    }

    public function run(): Result
    {
        $maintenant = now();
        $ouvert = config('app.diagnostic_enabled') === true;
        $expirations = JetonDeDiagnostic::query()->actifs($maintenant)->pluck('expire_le');
        $actifs = $expirations->count();

        $resume = $ouvert
            ? ($actifs === 0 ? 'Ouvert, aucun jeton actif' : $this->resumeDesJetons($actifs, $expirations->max()))
            : 'Fermé';

        $result = Result::make()
            ->meta(['ouvert' => $ouvert, 'jetons_actifs' => $actifs])
            ->shortSummary($resume);

        $constats = [];

        if ($this->unJetonMortAEtePresenteDepuisVingtQuatreHeures($maintenant)) {
            $constats[] = 'un jeton révoqué ou expiré a été présenté depuis vingt-quatre heures';
        }

        if ($ouvert && AlertesDeDiagnostic::adresses() === []) {
            $constats[] = 'l\'accès est ouvert sans HEALTH_TO_ADDRESS : aucune alerte ne partirait';
        }

        if (! $ouvert && $actifs > 0) {
            $constats[] = 'des jetons sont actifs alors que l\'accès est fermé';
        }

        if ($constats !== []) {
            return $result->warning($resume.' : '.implode(' ; ', $constats));
        }

        return $result->ok($resume);
    }

    private function resumeDesJetons(int $actifs, mixed $derniereExpiration): string
    {
        $jusqu = $derniereExpiration instanceof Carbon
            ? $derniereExpiration->copy()->timezone(config()->string('app.timezone'))->format('d/m/Y à H:i')
            : '?';

        return "{$actifs} jeton(s) actif(s) jusqu'au {$jusqu}";
    }

    private function unJetonMortAEtePresenteDepuisVingtQuatreHeures(Carbon $maintenant): bool
    {
        $presente = MagasinDurable::depot()->get(AlertesDeDiagnostic::CLE_DE_LA_DERNIERE_PRESENTATION);

        return is_string($presente) && Carbon::parse($presente)->gt($maintenant->copy()->subDay());
    }
}
