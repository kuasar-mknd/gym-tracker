<?php

declare(strict_types=1);

namespace App\Support\Sante;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Rouge en production quand la clé de l'application est l'une de celles dont
 * `config('app.cles_publiees')` garde l'empreinte SHA-256 : une clé lisible dans
 * l'historique du dépôt permet de forger un lien signé. La comparaison se fait
 * sur l'empreinte, en temps constant, pour la clé telle quelle et sans son
 * préfixe `base64:`. Le message ne cite jamais la clé. Hors production, le
 * contrôle ne juge rien.
 */
final class CleDeLApplicationCheck extends Check
{
    private const string CONSEIL = 'Faire tourner APP_KEY (php artisan key:generate), en plaçant l\'ancienne dans APP_PREVIOUS_KEYS si les liens déjà émis doivent survivre. Toutes les sessions se fermeront.';

    /**
     * Le libellé de la page « Santé » et du courriel d'alerte, qui
     * découperaient sinon le nom de classe.
     */
    #[\Override]
    protected ?string $label = 'Clé de l\'application';

    #[\Override]
    public function getName(): string
    {
        return 'CleDeLApplication';
    }

    public function run(): Result
    {
        if (! app()->isProduction()) {
            return Result::make()
                ->shortSummary('Non jugé hors production')
                ->ok('Ce contrôle ne juge que la production.');
        }

        if ($this->estPubliee(config()->string('app.key', ''))) {
            return Result::make()
                ->shortSummary('Clé publiée')
                ->failed('La clé de l\'application figure dans l\'historique du dépôt. '.self::CONSEIL);
        }

        return Result::make()
            ->shortSummary('Clé non publiée')
            ->ok('La clé de l\'application n\'est pas l\'une des clés publiées.');
    }

    private function estPubliee(string $cle): bool
    {
        $sansPrefixe = str_starts_with($cle, 'base64:') ? substr($cle, 7) : $cle;
        $publiees = array_filter((array) config('app.cles_publiees'), is_string(...));

        return array_any(
            [$cle, $sansPrefixe],
            fn (string $forme): bool => array_any(
                $publiees,
                fn (string $publiee): bool => hash_equals(strtolower($publiee), hash('sha256', $forme)),
            ),
        );
    }
}
