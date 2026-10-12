<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\TachePlanifiee;
use App\Support\Sante\TachesPlanifieesCheck;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use ShuvroRoy\FilamentSpatieLaravelHealth\Pages\HealthCheckResults;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Result;
use Spatie\Health\Facades\Health;
use Spatie\Health\HealthServiceProvider;
use Spatie\Health\Notifications\CheckFailedNotification;
use Spatie\Health\Notifications\Notifiable;
use Spatie\Permission\Models\Role;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Le courriel tel que le destinataire le lit : le texte visible du rendu HTML
 * (balises ôtées, entités décodées) et le rendu texte.
 *
 * @return array{html: string, texte: string}
 */
function santeRendusDuCourriel(MailMessage $courriel): array
{
    $html = (string) $courriel->render();
    $corps = Str::after($html, '<body');

    return [
        'html' => html_entity_decode(strip_tags($corps), ENT_QUOTES | ENT_HTML5),
        'texte' => html_entity_decode(
            (string) app(Markdown::class)->renderText((string) $courriel->markdown, $courriel->data()),
            ENT_QUOTES | ENT_HTML5,
        ),
    ];
}

/**
 * Les messages littéraux que les contrôles enregistrés du paquet passent à
 * `failed()`, `warning()` ou `handleFailure()`, lus dans leur source : ceux
 * que `lang/fr.json` peut traduire, puisque le paquet les passe à `trans()`.
 * Un message bâti avec une valeur (`"… {$pourcentage}% …"`) n'en est pas.
 *
 * @return list<string>
 */
function santeMessagesFixesDuPaquet(): array
{
    $messages = [];

    foreach (Health::registeredChecks() as $controle) {
        if (! str_starts_with($controle::class, 'Spatie\\Health\\')) {
            continue;
        }

        $jetons = array_values(array_filter(
            token_get_all((string) file_get_contents((string) new ReflectionClass($controle)->getFileName())),
            static fn (array|string $jeton): bool => ! is_array($jeton) || $jeton[0] !== T_WHITESPACE,
        ));

        foreach ($jetons as $position => $jeton) {
            $argument = $jetons[$position + 2] ?? null;

            if (! is_array($jeton) || $jeton[0] !== T_STRING || ! in_array($jeton[1], ['failed', 'warning', 'handleFailure'], true)
                || ($jetons[$position + 1] ?? null) !== '(' || ! is_array($argument) || $argument[0] !== T_CONSTANT_ENCAPSED_STRING
                || ($jetons[$position + 3] ?? null) !== ')') {
                continue;
            }

            $litteral = substr($argument[1], 1, -1);
            $messages[] = str_starts_with($argument[1], "'")
                ? str_replace(["\\'", '\\\\'], ["'", '\\'], $litteral)
                : stripcslashes($litteral);
        }
    }

    return array_values(array_unique($messages));
}

/**
 * La page « Santé » du panneau : le pendant en production de ce que Doctor
 * regarde dans la CI. Les résultats vivent dans le cache, pas en base : la
 * production paie chaque écriture SQL entre 350 ms et 1,7 s, et neuf lignes toutes
 * les cinq minutes n'apprendraient rien que le dernier passage ne dise déjà.
 */
it('ouvre la page de santé au super administrateur, et à lui seul', function (): void {
    $superAdmin = Admin::factory()->create();
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'admin'));

    $this->actingAs($superAdmin, 'admin')
        ->get(HealthCheckResults::getUrl(panel: 'admin'))
        ->assertOk();

    $this->actingAs(Admin::factory()->create(), 'admin')
        ->get(HealthCheckResults::getUrl(panel: 'admin'))
        ->assertForbidden();
});

/**
 * Trois contrôles disent ce que le dépôt ne peut pas corriger seul sur le
 * serveur de production : les réglages de MySQL (#1668), les versions des conteneurs (#1813) et
 * le dossier des sauvegardes (#1812).
 */
it('regarde la base et ses réglages, le cache, la file, le planificateur, Horizon, les versions, le disque, les sauvegardes et la configuration', function (): void {
    $noms = Health::registeredChecks()
        ->map(fn (Check $check): string => $check->getName())
        ->all();

    expect($noms)->toBe([
        'Database',
        'ReglagesDeLaBase',
        'Redis',
        'Cache',
        'Queue',
        'Schedule',
        'TachesPlanifiees',
        'Horizon',
        'VersionsDesConteneurs',
        'UsedDiskSpace',
        'DossierDesSauvegardes',
        'Backups',
        'DebugMode',
        'Environment',
        'OptimizedApp',
    ]);
});

/**
 * Sans libellé, laravel-health découpe le nom du contrôle : la page et le
 * courriel d'alerte disaient « Taches Planifiees », « Used Disk Space » ou
 * « Backups » (#2034). Le libellé se pose par `->label()` dans le provider
 * ou par `$label` dans la classe ; le nom, lui, ne change pas.
 */
it('donne à chaque contrôle un libellé français, jamais celui que le paquet tirerait de son nom', function (): void {
    $controles = Health::registeredChecks();

    foreach ($controles as $controle) {
        $sansLibelle = clone $controle;
        new ReflectionProperty(Check::class, 'label')->setValue($sansLibelle, null);
        $tireDeLaClasse = (string) Str::of(class_basename($controle))->beforeLast('Check')->snake()->replace('_', ' ')->title();

        expect($controle->getLabel())->not->toBeIn(
            [$sansLibelle->getLabel(), $tireDeLaClasse],
            $controle->getName().' garde le libellé tiré de son nom',
        );
    }

    expect($controles->map(fn (Check $controle): string => $controle->getLabel())->all())->toBe([
        'Connexion à la base',
        'Réglages de la base',
        'Connexion à Redis',
        "Cache de l'application",
        "File d'attente",
        'Planificateur',
        'Tâches planifiées',
        'Travailleurs Horizon',
        'Versions des conteneurs',
        'Espace disque utilisé',
        'Dossier des sauvegardes',
        'Archives des sauvegardes',
        'Mode debug',
        'Environnement',
        'Application optimisée',
    ]);
});

/**
 * Le README nomme les contrôles comme la page et le courriel : un libellé
 * changé dans le provider se change aussi là-bas.
 */
it('nomme dans le README chaque contrôle par son libellé', function (): void {
    $readme = (string) file_get_contents(base_path('README.md'));

    foreach (Health::registeredChecks() as $controle) {
        expect($readme)->toContain('« '.$controle->getLabel().' »');
    }
});

/**
 * Le courriel d'alerte cite chaque contrôle au rouge par son libellé, suivi
 * d'une espace insécable et du deux-points (#2034). Les deux rendus, HTML et
 * texte, sont lus tels que le destinataire les reçoit.
 */
it('cite chaque contrôle par son libellé français dans le courriel d’alerte', function (): void {
    $controles = Health::registeredChecks();
    $courriel = new CheckFailedNotification(
        $controles->map(fn (Check $controle): Result => Result::make()->check($controle)->failed('Au rouge.'))->all(),
    )->toMail();

    foreach (santeRendusDuCourriel($courriel) as $contenu) {
        expect($contenu)->toContain('Tâches planifiées');
        expect($contenu)->not->toContain('Taches Planifiees');

        foreach ($controles as $controle) {
            expect($contenu)->toContain($controle->getLabel()."\u{00A0}: Au rouge.");
        }
    }
});

/**
 * L'objet et l'introduction venaient de la traduction française du paquet,
 * qui parlait de « tests » et collait le deux-points ; le titre, « Laravel
 * Health », de sa vue (#2034). `lang/vendor/health/fr` surcharge la première,
 * `resources/views/vendor/health` la seconde.
 */
it('écrit en français l’objet, le titre et l’introduction du courriel d’alerte', function (): void {
    Config::set('app.name', 'GymTracker');

    $courriel = new CheckFailedNotification([
        Result::make()->check(TachesPlanifieesCheck::new())->failed('Échouée : app:remind-training'),
    ])->toMail();

    expect($courriel->subject)->toBe('Certains contrôles de santé de GymTracker ont échoué');

    $rendus = santeRendusDuCourriel($courriel);

    expect($rendus['texte'])->toContain("# Contrôles de santé\n")
        ->and(html_entity_decode((string) $courriel->render(), ENT_QUOTES | ENT_HTML5))->toMatch('#<h1[^>]*>Contrôles de santé</h1>#u');

    foreach ($rendus as $contenu) {
        expect($contenu)->toContain(
            "Les contrôles suivants signalent des avertissements ou des erreurs\u{00A0}:",
            "Tâches planifiées\u{00A0}: Échouée : app:remind-training",
        );

        foreach (['Laravel Health', 'tests', 'erreurs:', 'planifiées:'] as $reste) {
            expect($contenu)->not->toContain($reste);
        }
    }
});

/**
 * Le paquet passe le message de chaque résultat à `trans()` : un message fixe
 * se traduit par sa clé dans `lang/fr.json`, dans le courriel comme sur la
 * page, qui range le message traduit. La liste est lue dans la source des
 * contrôles enregistrés : un message ajouté ou reformulé par une mise à jour
 * du paquet la change, et ce test le dit. Une traduction se suffit : elle ne
 * renvoie pas aux métadonnées du résultat, comme le fait l'anglais de la file,
 * puisque ni le courriel ni la page ne les montrent.
 */
it('traduit chaque message fixe des contrôles du paquet', function (): void {
    $messages = santeMessagesFixesDuPaquet();

    expect($messages)->toEqualCanonicalizing([
        'Redis returned a falsy response when try to connection to it.',
        'Could not set or retrieve an application cache value.',
        'Queue jobs running failed. Check meta for more information.',
        'The schedule did not run yet.',
        'Horizon does not seem to be installed correctly.',
        'Horizon is not running.',
        'Horizon is running, but the status is paused.',
        'No backups found',
        'Not enough backups found',
        'Too many backups found',
        'The youngest backup was too old',
        'The oldest backup was too young',
        'Backups are not large enough',
        'The environment was expected to be `:expected`, but actually was `:actual`',
        'Configs are not cached.',
        'Routes are not cached.',
        'The events are not cached.',
    ]);

    foreach ($messages as $message) {
        $traduction = Result::make()->failed($message)->getNotificationMessage();

        expect($traduction)->not->toBe($message, "« {$message} » n'a pas de traduction dans lang/fr.json");

        foreach (['métadonnées', 'meta'] as $renvoi) {
            expect(Str::lower($traduction))->not->toContain($renvoi);
        }
    }
});

/**
 * Le chemin réel : `health:check` fait échouer ici quatre contrôles du
 * paquet tels que le provider les configure, et leurs messages arrivent en
 * français dans le courriel envoyé comme sur la page. Celui du mode debug
 * est bâti avec deux valeurs, mais le provider attend `false` : une seule
 * phrase peut sortir, et `lang/fr.json` la traduit.
 */
it('envoie et affiche en français les messages des contrôles du paquet qui échouent', function (): void {
    Notification::fake();
    Config::set('health.notifications.enabled', true);
    Config::set('health.notifications.mail.to', 'sam@exemple.test');
    Config::set('app.debug', true);

    $echouentIci = Health::registeredChecks()
        ->filter(fn (Check $controle): bool => in_array($controle->getName(), ['Schedule', 'DebugMode', 'Environment', 'OptimizedApp'], true))
        ->values()
        ->all();
    Health::clearChecks();
    Health::checks($echouentIci);

    $this->artisan('health:check')->assertSuccessful();

    $attendus = [
        "Planificateur\u{00A0}: Le planificateur n'a pas encore tourné.",
        "Mode debug\u{00A0}: Le mode debug devait être `false`, il est `true`",
        "Environnement\u{00A0}: L'environnement devait être `production`, il est `testing`",
        "Application optimisée\u{00A0}: La configuration n'est pas en cache.",
    ];

    Notification::assertSentTo(
        app(Notifiable::class),
        CheckFailedNotification::class,
        function (CheckFailedNotification $avis) use ($attendus): bool {
            $rendus = santeRendusDuCourriel($avis->toMail());

            expect($rendus['texte'])->toContain(...$attendus)
                ->and($rendus['html'])->toContain(...str_replace('`', '', $attendus));

            foreach ($rendus as $contenu) {
                foreach (['did not run', 'expected to be', 'actually was', 'not cached'] as $anglais) {
                    expect($contenu)->not->toContain($anglais);
                }
            }

            return true;
        },
    );

    $superAdmin = Admin::factory()->create();
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'admin'));

    $this->actingAs($superAdmin, 'admin')
        ->get(HealthCheckResults::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee("Le planificateur n'a pas encore tourné.")
        ->assertSee("La configuration n'est pas en cache.")
        ->assertDontSee('did not run')
        ->assertDontSee('not cached');
});

/**
 * La vue publiée remplace celle du paquet pour le courriel d'alerte. Elle ne
 * s'en écarte que par le titre traduit et l'espace insécable : ramenée à ces
 * deux écarts près, elle doit être identique à l'originale, sans quoi une
 * mise à jour du paquet a changé celle-ci et la copie est à refaire.
 */
it('ne s’écarte de la vue du courriel du paquet que par son titre et ses deux-points', function (): void {
    $cheminPublie = resource_path('views/vendor/health/mail/checkFailedNotification.blade.php');

    expect(View::getFinder()->find('health::mail.checkFailedNotification'))->toBe($cheminPublie);

    $publiee = (string) preg_replace('/\A\{\{--.*?--\}\}\n/s', '', (string) file_get_contents($cheminPublie));
    $ramenee = str_replace(
        ["# {{ __('health::notifications.laravel_health') }}\n", '}}&nbsp;: {{'],
        ["# Laravel Health\n", '}}: {{'],
        $publiee,
    );

    expect($ramenee)->not->toBe($publiee)
        ->and($ramenee)->toBe(file_get_contents(base_path('vendor/spatie/laravel-health/resources/views/mail/checkFailedNotification.blade.php')));
});

/**
 * Laravel fusionne la surcharge avec la traduction du paquet clé par clé :
 * une clé qui recopie celle du paquet, ou qu'aucun courriel envoyé ne lit
 * (message Slack, page de résultats du paquet, qu'aucune route ne sert), ne
 * changerait rien qu'on puisse voir. La surcharge ne garde donc que les
 * textes du courriel, chacun autrement que le paquet.
 */
it('ne surcharge du paquet que les textes du courriel envoyé, chacun autrement que lui', function (): void {
    Config::set('app.name', 'GymTracker');

    $surcharge = require lang_path('vendor/health/fr/notifications.php');
    $duPaquet = require base_path('vendor/spatie/laravel-health/resources/lang/fr/notifications.php');
    $courriel = new CheckFailedNotification([
        Result::make()->check(TachesPlanifieesCheck::new())->failed('Échouée : app:remind-training'),
    ])->toMail();
    $lu = $courriel->subject."\n".santeRendusDuCourriel($courriel)['texte'];

    expect($surcharge)->not->toBeEmpty()->toBeArray();

    foreach ((array) $surcharge as $cle => $valeur) {
        expect($valeur)->not->toBe(data_get($duPaquet, (string) $cle), "« {$cle} » recopie la traduction du paquet")
            ->toBeString()
            ->and($lu)->toContain(str_replace(':application_name', 'GymTracker', is_string($valeur) ? $valeur : ''));
    }
});

/**
 * `.gitignore` ne rouvre, de tout ce que laravel-health publie sous
 * lang/vendor et resources/views/vendor, que les deux fichiers que
 * l'application surcharge : les autres langues et les autres vues restent
 * ignorées, et `git add -A` ne les emporte pas après un vendor:publish ; les
 * dossiers publiés d'un autre paquet aussi. Le dépôt est rejoué dans un
 * dossier temporaire et lu comme Git le lirait, par Finder, sans git.
 */
it('ne rouvre dans .gitignore, de ce que publie laravel-health, que ses deux surcharges', function (): void {
    $publies = ['lang/vendor/filament/fr/actions.php', 'resources/views/vendor/pulse/autre.blade.php'];

    foreach (ServiceProvider::pathsToPublish(HealthServiceProvider::class) as $source => $destination) {
        if (! is_string($source) || ! is_string($destination) || ! is_dir($source)) {
            continue;
        }

        $relatif = Str::after($destination, base_path().'/');

        if (! Str::startsWith($relatif, ['lang/vendor/', 'resources/views/vendor/'])) {
            continue;
        }

        foreach (File::allFiles($source) as $fichier) {
            $publies[] = $relatif.'/'.$fichier->getRelativePathname();
        }
    }

    $racine = sys_get_temp_dir().'/sante-gitignore-'.Str::random(16);

    try {
        File::ensureDirectoryExists($racine.'/.git');
        File::copy(base_path('.gitignore'), $racine.'/.gitignore');

        foreach ($publies as $chemin) {
            File::ensureDirectoryExists(dirname($racine.'/'.$chemin));
            File::put($racine.'/'.$chemin, '');
        }

        $nonIgnores = array_map(
            static fn (SplFileInfo $fichier): string => $fichier->getRelativePathname(),
            iterator_to_array(Finder::create()->files()->in($racine)->ignoreVCSIgnored(true), false),
        );
    } finally {
        File::deleteDirectory($racine);
    }

    sort($nonIgnores);

    expect($publies)->toContain('lang/vendor/health/en/notifications.php', 'resources/views/vendor/health/list.blade.php')
        ->and($nonIgnores)->toBe([
            'lang/vendor/health/fr/notifications.php',
            'resources/views/vendor/health/mail/checkFailedNotification.blade.php',
        ]);
});

it('trouve la base en bonne santé', function (): void {
    expect(DatabaseCheck::new()->run()->status->value)->toBe('ok');
});

/**
 * Les deux battements sont ce que `ScheduleCheck` et `QueueCheck` attendent ;
 * le contrôle lui-même tourne toutes les cinq minutes et range ses résultats
 * pour la page.
 */
it('planifie les deux battements et le contrôle', function (): void {
    $commandes = collect(app(Schedule::class)->events())
        ->map(fn ($evenement): string => (string) $evenement->command)
        ->all();

    foreach (['health:schedule-check-heartbeat', 'health:queue-check-heartbeat', 'health:check'] as $commande) {
        expect(array_filter($commandes, fn (string $ligne): bool => str_contains($ligne, $commande)))
            ->not->toBeEmpty("{$commande} n'est pas planifiée");
    }
});

/**
 * Ce que Sentry surveillait par ses moniteurs de tâches, lu ici dans le
 * moniteur local (#1511).
 */
it('met les tâches planifiées au vert, à l’orange quand l’une est en retard, au rouge quand l’une a échoué', function (): void {
    $this->travelTo('2026-09-05 20:00:00');

    $quotidienne = TachePlanifiee::query()->create([
        'name' => 'app:remind-training',
        'type' => 'command',
        'cron_expression' => '0 18 * * *',
        'grace_time_in_minutes' => 5,
        'last_started_at' => '2026-09-05 18:00:00',
        'last_finished_at' => '2026-09-05 18:00:03',
    ]);

    $verte = TachesPlanifieesCheck::new()->run();
    expect($verte->status->value)->toBe('ok')
        ->and($verte->shortSummary)->toBe('1 suivies, 0 échouées, 0 en retard');

    TachePlanifiee::query()->create([
        'name' => 'backup:run',
        'type' => 'command',
        'cron_expression' => '30 2 * * *',
        'grace_time_in_minutes' => 5,
        'created_at' => '2026-09-03 09:00:00',
    ]);

    $orange = TachesPlanifieesCheck::new()->run();
    expect($orange->status->value)->toBe('warning')
        ->and($orange->notificationMessage)->toBe('En retard : backup:run')
        ->and($orange->meta['en_retard'])->toBe(['backup:run']);

    $quotidienne->forceFill(['last_failed_at' => '2026-09-05 18:00:02'])->save();

    $rouge = TachesPlanifieesCheck::new()->run();
    expect($rouge->status->value)->toBe('failed')
        ->and($rouge->notificationMessage)->toBe('Échouée : app:remind-training')
        ->and($rouge->meta['echouees'])->toBe(['app:remind-training'])
        ->and($rouge->shortSummary)->toBe('2 suivies, 1 échouées, 1 en retard');
});

/**
 * Une adresse suffit à allumer les courriels ; sans adresse rien ne part, et
 * seul un contrôle au rouge écrit : un orange qui dure écrirait chaque heure.
 */
it('n’écrit un courriel que si une adresse est posée, et seulement pour un rouge', function (): void {
    expect(config('health.notifications.enabled'))->toBeFalse()
        ->and(config('health.notifications.mail.to'))->toBe('')
        ->and(config('health.notifications.only_on_failure'))->toBeTrue();

    Notification::fake();
    Config::set('health.notifications.enabled', true);
    Config::set('health.notifications.mail.to', 'sam@exemple.test');
    Health::clearChecks();
    Health::checks([TachesPlanifieesCheck::new()]);

    TachePlanifiee::query()->create([
        'name' => 'backup:run',
        'type' => 'command',
        'cron_expression' => '30 2 * * *',
        'grace_time_in_minutes' => 5,
        'created_at' => '2026-09-03 09:00:00',
    ]);

    $this->artisan('health:check')->assertSuccessful();
    Notification::assertNothingSent();

    TachePlanifiee::query()->create([
        'name' => 'app:remind-training',
        'type' => 'command',
        'cron_expression' => '0 18 * * *',
        'grace_time_in_minutes' => 5,
        'last_started_at' => '2026-09-05 18:00:00',
        'last_failed_at' => '2026-09-05 18:00:02',
    ]);

    $this->artisan('health:check')->assertSuccessful();
    Notification::assertSentTo(app(Notifiable::class), CheckFailedNotification::class);
});

/**
 * Le chemin que le propriétaire a suivi : `health:check` voit une tâche
 * échouée, écrit le courriel et range le résultat que la page « Santé » lit.
 * Les deux disaient « Taches Planifiees » (#2034).
 */
it('écrit « Tâches planifiées » dans le courriel envoyé et sur la page', function (): void {
    Notification::fake();
    Config::set('health.notifications.enabled', true);
    Config::set('health.notifications.mail.to', 'sam@exemple.test');
    Health::clearChecks();
    Health::checks([TachesPlanifieesCheck::new()]);

    TachePlanifiee::query()->create([
        'name' => 'app:remind-training',
        'type' => 'command',
        'cron_expression' => '0 18 * * *',
        'grace_time_in_minutes' => 5,
        'last_started_at' => '2026-09-05 18:00:00',
        'last_failed_at' => '2026-09-05 18:00:02',
    ]);

    $this->artisan('health:check')->assertSuccessful();

    Notification::assertSentTo(
        app(Notifiable::class),
        CheckFailedNotification::class,
        function (CheckFailedNotification $avis): bool {
            $rendu = html_entity_decode((string) $avis->toMail()->render(), ENT_QUOTES | ENT_HTML5);

            return str_contains($rendu, "Tâches planifiées\u{00A0}: Échouée : app:remind-training");
        },
    );

    $superAdmin = Admin::factory()->create();
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'admin'));

    $this->actingAs($superAdmin, 'admin')
        ->get(HealthCheckResults::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee('Tâches planifiées')
        ->assertDontSee('Taches Planifiees');
});
