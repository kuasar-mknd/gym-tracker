<?php

declare(strict_types=1);

namespace App\Services\Diagnostic;

use App\Enums\PorteeDeDiagnostic;
use App\Models\ErreurNavigateur;
use App\Models\ExceptionEnregistree;
use App\Services\Diagnostic\Projections\LecturesBornees;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Ce que `GET /diagnostic/v1/etat` compte sur sept jours, une source à la fois
 * (#2032) : les exceptions, les jobs échoués et les erreurs du navigateur (les échecs
 * des tâches planifiées se tirent de la liste des tâches, dans
 * `EtatDuDiagnostic`). Chaque nombre est lu sous la borne de temps et
 * de lignes des autres lectures (`LecturesBornees::compter()`), et gardé
 * soixante secondes sous une clef par source : jamais par jeton, ni par
 * réponse. Le cache ne reçoit que des nombres.
 */
final class ComptesSur7Jours
{
    public const int JOURS = 7;

    public const int SECONDES_EN_CACHE = 60;

    public const string CLEF = 'diagnostic.etat.comptes.';

    /**
     * @return array{total: int, plafonne: bool}
     */
    public function de(PorteeDeDiagnostic $source, CarbonInterface $maintenant): array
    {
        return Cache::remember(
            self::CLEF.$source->value,
            self::SECONDES_EN_CACHE,
            fn (): array => LecturesBornees::compter($this->requete($source, $maintenant->toImmutable()->subDays(self::JOURS))),
        );
    }

    private function requete(PorteeDeDiagnostic $source, CarbonInterface $depuis): Builder
    {
        return match ($source) {
            PorteeDeDiagnostic::Exceptions => ExceptionEnregistree::query()->toBase()->where('created_at', '>=', $depuis),
            PorteeDeDiagnostic::ErreursNavigateur => ErreurNavigateur::query()->toBase()->where('created_at', '>=', $depuis),
            PorteeDeDiagnostic::Files => $this->echecsDeFiles($depuis),
            PorteeDeDiagnostic::Sante, PorteeDeDiagnostic::TexteBrut => throw new LogicException('Cette portée n\'a pas de compte en cache.'),
        };
    }

    private function echecsDeFiles(CarbonInterface $depuis): Builder
    {
        $connexion = config('queue.failed.database');

        return DB::connection(is_string($connexion) ? $connexion : null)
            ->table(config()->string('queue.failed.table', 'failed_jobs'))
            ->where('failed_at', '>=', $depuis);
    }
}
