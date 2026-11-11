<?php

declare(strict_types=1);

use App\Support\Sante\LecteurDeRedis;
use App\Support\Sante\LecteurDesVersionsDesServices;
use App\Support\Sante\VersionsDesServicesCheck;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Support\RedisQuiRepond;

/*
 * Ce qui tourne en production pour db et redis n'était lu nulle part (#2152) :
 * sans `pull_policy`, une étiquette flottante reste sur la version tirée la
 * première fois, et une ligne `image:` changée dans le dépôt n'agit que si
 * la pile reçoit la composition de la release (#1668). « Versions des
 * services » lit la version que rend chaque serveur et la compare à celle
 * que la composition de la pile attend (`VERSION_ATTENDUE_DE_MYSQL`,
 * `VERSION_ATTENDUE_DE_REDIS`, que `LesVersionsAttenduesSuiventLesImagesTest`
 * lie aux étiquettes `image:`).
 */

/**
 * Remplace la lecture des deux serveurs : chaque version est rendue, ou
 * l'erreur donnée est levée.
 */
function versionsDesServicesServies(string|Throwable|null $mysql, string|Throwable|null $redis): void
{
    app()->instance(LecteurDesVersionsDesServices::class, new class($mysql, $redis) extends LecteurDesVersionsDesServices
    {
        public function __construct(private readonly string|Throwable|null $versionDeMysql, private readonly string|Throwable|null $versionDeRedis)
        {
        }

        #[\Override]
        public function mysql(): ?string
        {
            return $this->versionDeMysql instanceof Throwable ? throw $this->versionDeMysql : $this->versionDeMysql;
        }

        #[\Override]
        public function redis(): ?string
        {
            return $this->versionDeRedis instanceof Throwable ? throw $this->versionDeRedis : $this->versionDeRedis;
        }
    });
}

/**
 * Les versions qu'attend la composition, comme la pile les transmet.
 */
function versionsDesServicesAttendues(?string $mysql, ?string $redis): void
{
    Config::set('health.versions_attendues.mysql', $mysql);
    Config::set('health.versions_attendues.redis', $redis);
}

/**
 * Le contrôle, jugé comme en production.
 */
function versionsDesServicesEnProduction(): VersionsDesServicesCheck
{
    app()->detectEnvironment(fn (): string => 'production');

    return VersionsDesServicesCheck::new();
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-11 10:00:00');
    versionsDesServicesAttendues('8.4', '8.10.3');
});

it('ne juge pas les versions hors production, sans lire les serveurs', function (): void {
    versionsDesServicesServies(new LogicException('MySQL lu hors production.'), new LogicException('Redis lu hors production.'));

    $resultat = VersionsDesServicesCheck::new()->run();

    expect($resultat->status->value)->toBe('ok')
        ->and($resultat->shortSummary)->toBe('Non jugé hors production')
        ->and($resultat->notificationMessage)->toContain('ne juge que la production');
});

it('porte un nom stable et un libellé français', function (): void {
    $controle = VersionsDesServicesCheck::new();

    expect($controle->getName())->toBe('VersionsDesServices')
        ->and($controle->getLabel())->toBe('Versions des services');
});

/*
 * Tant que db porte `mysql:8.4`, la composition attend la ligne : toute 8.4.x
 * y répond, rang de mise à jour compris (`@@version_with_update`).
 */
it('met les versions au vert quand chaque serveur rend la version que la composition attend', function (): void {
    versionsDesServicesServies('8.4.13.0', '8.10.3');

    $resultat = versionsDesServicesEnProduction()->run();

    expect($resultat->status->value)->toBe('ok', $resultat->notificationMessage)
        ->and($resultat->shortSummary)->toBe('MySQL 8.4.13.0 · Redis 8.10.3')
        ->and($resultat->meta)->toBe([
            'mysql' => ['attendue' => '8.4', 'servie' => '8.4.13.0'],
            'redis' => ['attendue' => '8.10.3', 'servie' => '8.10.3'],
        ]);
});

it('compare une version à l’étiquette attendue, partie par partie', function (string $attendue, string $servie, bool $correspond): void {
    versionsDesServicesAttendues($attendue, '8.10.3');
    versionsDesServicesServies($servie, '8.10.3');

    expect(versionsDesServicesEnProduction()->run()->status->value)->toBe($correspond ? 'ok' : 'warning');
})->with([
    'la ligne, à son correctif' => ['8.4', '8.4.13', true],
    'la ligne, au rang de mise à jour' => ['8.4', '8.4.13.0', true],
    'la version exacte' => ['9.7.4', '9.7.4', true],
    'la version exacte, au rang de mise à jour' => ['9.7.4', '9.7.4.0', true],
    'un suffixe du serveur' => ['8.4', '8.4.13-log', true],
    'la version exacte, suffixe du serveur' => ['9.7.4', '9.7.4-log', true],
    'une autre ligne au même début' => ['8.4', '8.40.1', false],
    'la version suivante' => ['8.4', '9.7.4', false],
    'un correctif plus ancien' => ['9.7.4', '9.7.2', false],
    'un correctif au même début' => ['9.7.4', '9.7.40', false],
]);

/*
 * Pendant une mise à jour de la pile, les services sont recréés l'un après
 * l'autre : un écart plus jeune que dix minutes est peut-être une mise à
 * jour en cours, comme pour « Versions des conteneurs ». Au-delà, rouge.
 */
it('met un écart à l’orange pendant le délai d’une mise à jour, puis au rouge', function (): void {
    versionsDesServicesServies('8.4.13.0', '8.10.2');

    $debut = versionsDesServicesEnProduction()->run();

    expect($debut->status->value)->toBe('warning')
        ->and($debut->shortSummary)->toBe('MySQL 8.4.13.0 · Redis 8.10.2')
        ->and($debut->notificationMessage)
        ->toStartWith('Peut-être une mise à jour en cours : ')
        ->toContain('Redis rend 8.10.2, la composition attend 8.10.3')
        ->toContain('composition de la release');

    Carbon::setTestNow('2026-11-11 10:09:59');
    expect(versionsDesServicesEnProduction()->run()->status->value)->toBe('warning');

    Carbon::setTestNow('2026-11-11 10:10:00');
    $tard = versionsDesServicesEnProduction()->run();

    expect($tard->status->value)->toBe('failed')
        ->and($tard->notificationMessage)
        ->not->toContain('Peut-être')
        ->toContain('Redis rend 8.10.2, la composition attend 8.10.3 depuis le 11/11/2026 à 10:00');
});

/*
 * Le même écart qui revient après un retour au vert (un retour en arrière,
 * une mise à jour refaite) rouvre lui aussi ses dix minutes.
 */
it('rouvre le délai quand l’écart change ou disparaît', function (): void {
    versionsDesServicesServies('8.4.13.0', '8.10.2');
    versionsDesServicesEnProduction()->run();

    Carbon::setTestNow('2026-11-11 10:20:00');
    versionsDesServicesServies('8.4.13.0', '8.10.3');
    expect(versionsDesServicesEnProduction()->run()->status->value)->toBe('ok');

    Carbon::setTestNow('2026-11-11 10:30:00');
    versionsDesServicesServies('8.4.13.0', '8.10.2');
    $revenu = versionsDesServicesEnProduction()->run();
    expect($revenu->status->value)->toBe('warning')
        ->and($revenu->notificationMessage)->toContain('depuis le 11/11/2026 à 10:30');

    Carbon::setTestNow('2026-11-11 10:41:00');
    expect(versionsDesServicesEnProduction()->run()->status->value)->toBe('failed');

    Carbon::setTestNow('2026-11-11 10:45:00');
    versionsDesServicesServies('9.7.4.0', '8.10.2');
    expect(versionsDesServicesEnProduction()->run()->status->value)->toBe('warning');

    Carbon::setTestNow('2026-11-11 10:56:00');
    expect(versionsDesServicesEnProduction()->run()->status->value)->toBe('failed');
});

/*
 * Une pile qui a reçu l'image de la release sans sa composition n'a pas les
 * versions attendues : c'est le défaut même que le contrôle doit dire (#1668).
 */
it('met les versions à l’orange quand la pile n’a pas reçu la composition de la release', function (): void {
    versionsDesServicesAttendues(null, '');
    versionsDesServicesServies('8.4.13.0', '8.10.3');

    $resultat = versionsDesServicesEnProduction()->run();

    expect($resultat->status->value)->toBe('warning')
        ->and($resultat->shortSummary)->toBe('MySQL 8.4.13.0 · Redis 8.10.3')
        ->and($resultat->notificationMessage)
        ->toContain('VERSION_ATTENDUE_DE_MYSQL et VERSION_ATTENDUE_DE_REDIS')
        ->toContain('composition de la release');
});

it('met les versions à l’orange quand un serveur ne rend pas la sienne, sans planter', function (): void {
    versionsDesServicesServies(null, new RuntimeException('Connection refused'));

    $resultat = versionsDesServicesEnProduction()->run();

    expect($resultat->status->value)->toBe('warning')
        ->and($resultat->shortSummary)->toBe('MySQL ? · Redis ?')
        ->and($resultat->notificationMessage)
        ->toContain('Non vérifié : MySQL n\'a pas rendu sa version.')
        ->toContain('Non vérifié : Redis n\'a pas rendu sa version (RuntimeException : Connection refused).');
});

it('lit la version complète de MySQL, rang de mise à jour compris quand le serveur le donne, sans rien écrire', function (): void {
    DB::enableQueryLog();

    $version = app(LecteurDesVersionsDesServices::class)->mysql();
    $requetes = array_map(fn (array $requete): string => strtolower(ltrim((string) $requete['query'])), DB::getQueryLog());
    $variables = [];

    foreach (DB::select("SHOW GLOBAL VARIABLES WHERE Variable_name IN ('version', 'version_with_update')") as $ligne) {
        $colonnes = (array) $ligne;
        $nom = $colonnes['Variable_name'] ?? null;

        if (is_string($nom)) {
            $variables[$nom] = $colonnes['Value'] ?? null;
        }
    }

    expect($version)->toBe($variables['version_with_update'] ?? $variables['version'] ?? null)
        ->and($version)->toMatch('/^\d+\.\d+\.\d+/')
        ->and($requetes)->toHaveCount(1)
        ->and($requetes[0])->toStartWith('show global variables');
});

it('lit la version de Redis par le lecteur d’INFO, sur l’instance des données', function (): void {
    RedisQuiRepond::repond(['default' => RedisQuiRepond::info(['redis_version' => '8.10.3'])]);

    expect(app(LecteurDesVersionsDesServices::class)->redis())->toBe('8.10.3')
        ->and(app(LecteurDesVersionsDesServices::class))->toBeInstanceOf(LecteurDesVersionsDesServices::class)
        ->and(app(LecteurDeRedis::class)->instances())->toHaveKey('donnees', 'default');
});
