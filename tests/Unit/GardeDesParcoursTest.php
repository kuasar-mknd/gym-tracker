<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\DuskTestCase;
use Tests\Support\GardeDesParcours;

/**
 * `artisan dusk` lance Pest avec l'environnement du .env : sous Sail, la base
 * de développement et `APP_URL=http://localhost`. Les parcours qui vident leur
 * base au premier test (`DatabaseTruncation`, donc `migrate:fresh`) vidaient
 * alors celle du développeur, et le navigateur du conteneur Selenium cherchait
 * l'application chez lui (#1909).
 */
it('refuse une base dont le nom ne finit pas par _dusk', function (string $base): void {
    $motifs = GardeDesParcours::motifsDeRefus($base, 'http://laravel.test', 'http://selenium:4444/wd/hub');

    expect($motifs)->toHaveCount(1)
        ->and($motifs[0])->toContain("« {$base} »")
        ->and($motifs[0])->toContain('.env.dusk.local');
})->with([
    'la base de développement de Sail' => 'gym_tracker',
    'la base de la suite backend' => 'gym_tracker_testing',
    'un nom qui contient _dusk sans finir par lui' => 'gym_tracker_dusk_old',
    'une base SQLite en mémoire' => ':memory:',
]);

it('refuse une APP_URL locale quand le navigateur tourne sur une autre machine', function (string $urlDeLApplication): void {
    $motifs = GardeDesParcours::motifsDeRefus('gym_tracker_dusk', $urlDeLApplication, 'http://selenium:4444/wd/hub');

    expect($motifs)->toHaveCount(1)
        ->and($motifs[0])->toContain('selenium')
        ->and($motifs[0])->toContain('http://laravel.test');
})->with([
    'le gabarit de Sail' => 'http://localhost',
    'l’ancienne valeur de phpunit.dusk.xml' => 'http://127.0.0.1',
    'une boucle locale avec un port' => 'http://127.0.0.1:8000',
    'un sous-domaine de localhost' => 'http://gym.localhost',
    'la boucle locale en IPv6' => 'http://[::1]:8000',
]);

it('refuse une APP_URL que le navigateur ne peut pas ouvrir', function (string $urlDeLApplication): void {
    expect(GardeDesParcours::motifsDeRefus('gym_tracker_dusk', $urlDeLApplication, 'http://127.0.0.1:9515'))
        ->toHaveCount(1);
})->with([
    'vide' => '',
    'sans schéma' => 'laravel.test',
    'un autre schéma que http' => 'ftp://laravel.test',
]);

it('refuse un pilote dont l’adresse ne dit pas où tourne le navigateur', function (): void {
    expect(GardeDesParcours::motifsDeRefus('gym_tracker_dusk', 'http://laravel.test', 'selenium'))
        ->toHaveCount(1);
});

it('accepte les dispositions qui fonctionnent', function (string $urlDeLApplication, string $urlDuPilote): void {
    expect(GardeDesParcours::motifsDeRefus('gym_tracker_dusk', $urlDeLApplication, $urlDuPilote))->toBe([]);
})->with([
    'la CI : serveur et ChromeDriver sur le même exécuteur' => ['http://127.0.0.1:8000', 'http://127.0.0.1:9515'],
    'Sail : le service laravel.test vu du conteneur selenium' => ['http://laravel.test', 'http://selenium:4444/wd/hub'],
    'un ChromeDriver local devant localhost' => ['http://localhost:8000', 'http://localhost:9515'],
    'un ChromeDriver local devant un domaine de développement' => ['https://gym-tracker.test', 'http://127.0.0.1:9515'],
]);

it('dit tout ce qui ne va pas en une fois', function (): void {
    expect(GardeDesParcours::motifsDeRefus('gym_tracker', 'http://localhost', 'http://selenium:4444/wd/hub'))
        ->toHaveCount(2);
});

it('lève une exception qui cite chaque motif, et laisse passer la disposition de la CI', function (): void {
    expect(fn () => GardeDesParcours::verifier('gym_tracker', 'http://localhost', 'http://selenium:4444/wd/hub'))
        ->toThrow(RuntimeException::class, '#1909');

    GardeDesParcours::verifier('gym_tracker_dusk', 'http://127.0.0.1:8000', 'http://127.0.0.1:9515');
});

/**
 * La garde ne sert que si elle passe avant les traits : `DatabaseTruncation`
 * lance `migrate:fresh` dès le premier test. Le parcours fictif vise une base
 * SQLite en mémoire, si bien qu'une garde débranchée ne détruirait rien — le
 * test échouerait seulement, sur le témoin de la troncature.
 */
it('arrête un parcours avant que DatabaseTruncation ne touche à la base', function (): void {
    $parcours = new class('parcoursFictif') extends DuskTestCase
    {
        use DatabaseTruncation;

        public bool $troncatureCommencee = false;

        public function parcoursFictif(): void
        {
        }

        public function demarrerLeParcours(): void
        {
            $this->setUpTheTestEnvironment();
        }

        public function arreterLeParcours(): void
        {
            $this->tearDownTheTestEnvironment();
        }

        #[\Override]
        public function createApplication(): Application
        {
            $application = parent::createApplication();

            $application->make(Repository::class)->set([
                'database.default' => 'sqlite',
                'database.connections.sqlite.database' => ':memory:',
                'app.url' => 'http://127.0.0.1:8000',
            ]);

            return $application;
        }

        #[\Override]
        protected function urlDuPilote(): string
        {
            return 'http://127.0.0.1:9515';
        }

        /**
         * Remplace la méthode vide du trait : le témoin d'une troncature commencée.
         */
        protected function beforeTruncatingDatabase(): void
        {
            $this->troncatureCommencee = true;
        }
    };

    $migreeAvant = RefreshDatabaseState::$migrated;

    try {
        expect(fn () => $parcours->demarrerLeParcours())
            ->toThrow(RuntimeException::class, '« :memory: »');

        expect($parcours->troncatureCommencee)->toBeFalse();
    } finally {
        $parcours->arreterLeParcours();
        RefreshDatabaseState::$migrated = $migreeAvant;
    }
});
