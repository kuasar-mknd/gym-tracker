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
 * `--tous` ou `--identifiant=` désigne ce qui se révoque, l'un des deux est
 * exigé. `--si-coupe` est celui du démarrage (`entrypoint.sh`) : il ne fait
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
        $identifiant = $this->option('identifiant');
        $aUnIdentifiant = is_string($identifiant) && $identifiant !== '';

        $designations = array_filter([$this->option('si-coupe') === true, $this->option('tous') === true, $aUnIdentifiant]);

        if (count($designations) > 1) {
            $this->components->error('Choisis une seule désignation : --tous, --identifiant= ou --si-coupe.');

            return self::INVALID;
        }

        if ($this->option('si-coupe') === true) {
            if (config('app.diagnostic_enabled') === true) {
                return self::SUCCESS;
            }

            $revoques = $revocation->execute(RaisonDeRevocationDuJeton::Interrupteur);
        } elseif ($this->option('tous') === true) {
            $revoques = $revocation->execute(RaisonDeRevocationDuJeton::Manuelle);
        } elseif ($aUnIdentifiant) {
            $revoques = $revocation->execute(RaisonDeRevocationDuJeton::Manuelle, null, $identifiant);
        } else {
            $this->components->error('Désigne ce qui se révoque : --tous ou --identifiant=.');

            return self::INVALID;
        }

        $this->components->info(sprintf('%d jeton(s) révoqué(s).', count($revoques)));

        return self::SUCCESS;
    }
}
