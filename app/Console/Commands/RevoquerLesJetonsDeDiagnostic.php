<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Diagnostic\RevoqueLesJetonsDeDiagnosticAction;
use App\Enums\RaisonDeRevocationDuJeton;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Révoque des jetons de diagnostic (#2032), en console : le retour arrière le
 * plus court quand le panneau ne répond plus.
 *
 * Une seule désignation, exigée : `--tous`, `--identifiant=` ou `--si-coupe` ;
 * deux ensemble sont refusés. `--si-coupe` est celle du démarrage (`entrypoint.sh`) : il ne fait
 * rien tant que `DIAGNOSTIC_ENABLED` est vrai, et révoque tout, avec la
 * raison `interrupteur`, quand il est faux. La commande n'affiche jamais un
 * jeton : elle n'en connaît que l'identifiant.
 */
#[Signature('diagnostic:revoquer {--tous : Révoque tous les jetons actifs} {--identifiant= : Révoque le jeton de cet identifiant} {--si-coupe : Ne révoque que si l\'accès est coupé}')]
#[Description('Révoque les jetons de diagnostic actifs')]
final class RevoquerLesJetonsDeDiagnostic extends Command
{
    public function handle(RevoqueLesJetonsDeDiagnosticAction $revocation): int
    {
        $designations = $this->designations();

        if (count($designations) !== 1) {
            $this->components->error(match (count($designations)) {
                0 => 'Désigne ce qui se révoque : --tous ou --identifiant=.',
                default => 'Choisis une seule désignation : --tous, --identifiant= ou --si-coupe.',
            });

            return self::INVALID;
        }

        if ($designations === ['si-coupe'] && config('app.diagnostic_enabled') === true) {
            return self::SUCCESS;
        }

        $revoques = match ($designations[0]) {
            'si-coupe' => $revocation->execute(RaisonDeRevocationDuJeton::Interrupteur),
            'tous' => $revocation->execute(RaisonDeRevocationDuJeton::Manuelle),
            default => $revocation->execute(RaisonDeRevocationDuJeton::Manuelle, null, (string) $this->option('identifiant')),
        };

        $this->components->info(sprintf('%d jeton(s) révoqué(s).', count($revoques)));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function designations(): array
    {
        $identifiant = $this->option('identifiant');

        return array_keys(array_filter([
            'si-coupe' => $this->option('si-coupe') === true,
            'tous' => $this->option('tous') === true,
            'identifiant' => is_string($identifiant) && $identifiant !== '',
        ]));
    }
}
