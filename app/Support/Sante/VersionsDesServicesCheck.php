<?php

declare(strict_types=1);

namespace App\Support\Sante;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Throwable;

/**
 * Compare la version que rendent MySQL et Redis à celle que la composition
 * de la pile attend (#2152) : `VERSION_ATTENDUE_DE_MYSQL` et
 * `VERSION_ATTENDUE_DE_REDIS`, que `docker-compose.prod.yml` transmet à app,
 * worker et scheduler et qui suivent l'étiquette `image:` de db et de redis.
 * Sans `pull_policy`, une étiquette flottante reste sur la version tirée la
 * première fois, et une ligne `image:` changée dans le dépôt n'agit que si la
 * pile reçoit la composition de la release (#1668) : rien ne lisait la
 * version servie.
 *
 * Une étiquette de ligne (`8.4`) admet chacun de ses correctifs ; une
 * étiquette exacte (`8.10.3`), elle seule, rang de mise à jour compris
 * (`9.7.4.0`). Rouge quand un écart dure plus de dix minutes, orange avant :
 * pendant une mise à jour, les services sont recréés l'un après l'autre,
 * comme dans « Versions des conteneurs ». Orange aussi quand la pile n'a pas
 * transmis les versions attendues (elle n'a pas reçu la composition de la
 * release) ou qu'un serveur ne rend pas la sienne : « Connexion à la base »
 * et « Connexion à Redis » disent déjà une panne. Production seulement ; la
 * lecture se fait dans run(), et rien ne s'écrit en base.
 */
final class VersionsDesServicesCheck extends Check
{
    /**
     * Le délai d'une mise à jour de la pile : un écart plus jeune n'écrit
     * pas de courriel.
     */
    private const int MINUTES_DE_MISE_A_JOUR = 10;

    /** L'écart en cours et le moment où il est apparu. */
    private const string CLE_DE_L_ECART = 'sante:versions-des-services:ecart';

    /** @var array<string, array{nom: string, variable: string}> */
    private const array SERVICES = [
        'mysql' => ['nom' => 'MySQL', 'variable' => 'VERSION_ATTENDUE_DE_MYSQL'],
        'redis' => ['nom' => 'Redis', 'variable' => 'VERSION_ATTENDUE_DE_REDIS'],
    ];

    private const string QUOI_FAIRE = 'Un service garde l\'image avec laquelle il a été créé, et retélécharger l\'image de l\'application ne le change pas : donner à la pile la composition de la release (le docker-compose.prod.yml de son tag), puis la mettre à jour par la marche que nomme le CHANGELOG.';

    /**
     * Le libellé de la page « Santé » et du courriel d'alerte, qui
     * découperaient sinon le nom de classe.
     */
    #[\Override]
    protected ?string $label = 'Versions des services';

    public function __construct(private readonly LecteurDesVersionsDesServices $lecteur)
    {
        parent::__construct();
    }

    #[\Override]
    public function getName(): string
    {
        return 'VersionsDesServices';
    }

    public function run(): Result
    {
        if (! app()->isProduction()) {
            return Result::make()
                ->shortSummary('Non jugé hors production')
                ->ok('Ce contrôle ne juge que la production : seule sa composition transmet les versions attendues de db et de redis.');
        }

        $meta = [];
        $ecarts = [];
        $absentes = [];
        $illisibles = [];

        foreach (self::SERVICES as $service => ['nom' => $nom, 'variable' => $variable]) {
            $attendue = config("health.versions_attendues.{$service}");
            $attendue = is_string($attendue) && $attendue !== '' ? $attendue : null;
            [$servie, $erreur] = $this->lire($service);
            $meta[$service] = ['attendue' => $attendue, 'servie' => $servie];

            if ($attendue === null) {
                $absentes[] = $variable;
            }

            if ($servie === null) {
                $illisibles[] = sprintf('Non vérifié : %s n\'a pas rendu sa version%s.', $nom, $erreur === null ? '' : " ({$erreur})");
            } elseif ($attendue !== null && ! self::correspond($attendue, $servie)) {
                $ecarts[] = sprintf('%s rend %s, la composition attend %s', $nom, $servie, $attendue);
            }
        }

        $result = Result::make()
            ->meta($meta)
            ->shortSummary(implode(' · ', array_map(
                fn (string $service): string => self::SERVICES[$service]['nom'].' '.($meta[$service]['servie'] ?? '?'),
                array_keys(self::SERVICES),
            )));

        $oranges = $illisibles;

        if ($absentes !== []) {
            $oranges[] = sprintf(
                '%s %s pas %s : la pile n\'a pas reçu la composition de la release, et rien ne dit quelle version elle devrait servir. Lui donner le docker-compose.prod.yml du tag, puis la mettre à jour.',
                implode(' et ', $absentes),
                count($absentes) > 1 ? 'ne sont' : 'n\'est',
                count($absentes) > 1 ? 'posées' : 'posée',
            );
        }

        if ($ecarts === []) {
            Cache::forget(self::CLE_DE_L_ECART);

            return $oranges === [] ? $result->ok() : $result->warning(implode(' ', $oranges));
        }

        $depuis = $this->depuis(implode(' ; ', $ecarts));
        $message = sprintf('%s depuis le %s. %s', implode(' ; ', $ecarts), $this->date($depuis), self::QUOI_FAIRE);

        if ($depuis->greaterThan(now()->subMinutes(self::MINUTES_DE_MISE_A_JOUR))) {
            return $result->warning(implode(' ', ['Peut-être une mise à jour en cours : '.$message, ...$oranges]));
        }

        return $result->failed(implode(' ', [$message, ...$oranges]));
    }

    /**
     * La version servie correspond à l'attendue quand elle est cette version
     * ou l'un de ses correctifs ou rangs (`8.4` admet `8.4.13`, pas `8.40.1`).
     * Un suffixe du serveur (`-log`) ne compte pas.
     */
    public static function correspond(string $attendue, string $servie): bool
    {
        $version = explode('-', $servie, 2)[0];

        return $version === $attendue || str_starts_with($version, $attendue.'.');
    }

    /**
     * @return array{0: string|null, 1: string|null} la version, ou ce qui l'a empêchée
     */
    private function lire(string $service): array
    {
        try {
            $version = $service === 'mysql' ? $this->lecteur->mysql() : $this->lecteur->redis();

            return [$version === '' ? null : $version, null];
        } catch (Throwable $erreur) {
            return [null, sprintf('%s : %s', class_basename($erreur), Str::limit($erreur->getMessage(), 200))];
        }
    }

    /**
     * Le moment où l'écart donné est apparu : gardé dans le cache tant qu'il
     * dure, remis à maintenant quand il change.
     */
    private function depuis(string $ecart): Carbon
    {
        $garde = Cache::get(self::CLE_DE_L_ECART);

        if (is_array($garde) && ($garde['ecart'] ?? null) === $ecart && is_string($garde['depuis'] ?? null)) {
            return Carbon::parse($garde['depuis']);
        }

        $maintenant = now();
        Cache::forever(self::CLE_DE_L_ECART, ['ecart' => $ecart, 'depuis' => $maintenant->toIso8601String()]);

        return $maintenant;
    }

    private function date(Carbon $instant): string
    {
        $date = $instant->copy()->setTimezone(config()->string('app.timezone'));

        return $date->format('d/m/Y').' à '.$date->format('H:i');
    }
}
