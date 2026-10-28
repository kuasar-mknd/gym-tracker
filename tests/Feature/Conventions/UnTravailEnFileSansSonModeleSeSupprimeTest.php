<?php

declare(strict_types=1);

use App\Jobs\EnvoyerLeLienDeReinitialisation;
use App\Jobs\SyncPersonalRecord;
use App\Jobs\SyncUserAchievements;
use App\Jobs\SyncUserGoals;
use App\Jobs\TraiterUnChangementDAdresse;
use App\Models\Achievement;
use App\Models\User;
use App\Notifications\AchievementUnlocked;
use App\Notifications\PersonalRecordAchieved;
use App\Notifications\TrainingReminder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Symfony\Component\Finder\Finder;

/*
 * Un travail en file qui porte un modèle se supprime quand ce modèle a disparu,
 * au lieu d'échouer (#2109).
 *
 * Laravel restaure les modèles d'un travail avant de le lancer. Un modèle
 * supprimé entre l'envoi et le tour du travail (une série supprimée avant la
 * synchronisation de ses records, un compte supprimé alors qu'un recalcul
 * attend) levait `ModelNotFoundException`, et le travail partait dans
 * `failed_jobs`, au milieu des vrais échecs, pour un comportement normal.
 * `#[DeleteWhenMissingModels]` le fait supprimer sans le lancer. Pour un avis
 * en file, Laravel lit la déclaration sur la classe de l'avis
 * (`SendQueuedNotifications`), et le destinataire, restauré comme une
 * collection, ne lève pas : seul un modèle que l'avis porte lui-même compte.
 *
 * Cette garde lit chaque classe concrète de `app/` qui implémente
 * `ShouldQueue` (travaux, avis, et tout écouteur ou courriel mis en file plus
 * tard). Une propriété typée par un modèle Eloquent, nullable ou dans une
 * union, exige la déclaration : l'attribut sur la classe, l'une de ses
 * parentes ou l'un de leurs traits, ou `$deleteWhenMissingModels` à vrai par
 * défaut, là où Laravel les lit (`ReadsClassAttributes`). Une collection de
 * modèles ne compte pas : Laravel la restaure sans les modèles disparus, sans
 * lever. Une propriété sans type que l'application déclare est refusée : la
 * garde ne saurait pas ce qu'elle porte. Celles des classes et des traits du
 * framework (`Queueable`, `Notification`) n'en sont pas. Un travail qui doit
 * échouer sans son modèle s'inscrit dans `sansSonModeleExceptions()`, avec sa
 * raison ; une exception qui ne porte plus de modèle, ou qui le déclare, fait
 * échouer la garde.
 */

/**
 * Les classes en file qui portent un modèle sans se supprimer quand il
 * disparaît, chacune avec sa raison. Aucune aujourd'hui.
 *
 * @return array<class-string, string>
 */
function sansSonModeleExceptions(): array
{
    return [];
}

/**
 * Les classes concrètes de `app/` qui implémentent `ShouldQueue`.
 *
 * @return list<ReflectionClass<object>>
 */
function sansSonModeleClassesEnFile(): array
{
    $classes = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php')->sortByName() as $fichier) {
        $classe = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $fichier->getRelativePathname());

        if (! class_exists($classe)) {
            continue;
        }

        $reflexion = new ReflectionClass($classe);

        if ($reflexion->isInstantiable() && $reflexion->implementsInterface(ShouldQueue::class)) {
            $classes[] = $reflexion;
        }
    }

    if ($classes === []) {
        throw new RuntimeException('Aucune classe en file trouvée sous app/ : sans elles, la garde passerait sans rien lire.');
    }

    return $classes;
}

/**
 * La propriété vient-elle d'une classe parente ou d'un trait du framework ?
 *
 * @param  ReflectionClass<object>  $classe
 */
function sansSonModeleVientDuFramework(ReflectionClass $classe, ReflectionProperty $propriete): bool
{
    $declarante = $propriete->getDeclaringClass()->getName();

    if ($declarante !== $classe->getName() && ! str_starts_with($declarante, 'App\\')) {
        return true;
    }

    foreach (class_uses_recursive($classe->getName()) as $trait) {
        if (! str_starts_with($trait, 'App\\') && new ReflectionClass($trait)->hasProperty($propriete->getName())) {
            return true;
        }
    }

    return false;
}

/**
 * Les noms des classes que nomme le type d'une propriété, unions comprises.
 *
 * @return list<string>
 */
function sansSonModeleClassesDuType(ReflectionType $type): array
{
    if ($type instanceof ReflectionNamedType) {
        return $type->isBuiltin() ? [] : [$type->getName()];
    }

    $classes = [];

    if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
        foreach ($type->getTypes() as $membre) {
            $classes = [...$classes, ...sansSonModeleClassesDuType($membre)];
        }
    }

    return $classes;
}

/**
 * Ce que la classe porte qui se restaure en levant quand il a disparu : ses
 * propriétés typées par un modèle, et celles, sans type, qu'elle déclare.
 *
 * @param  ReflectionClass<object>  $classe
 * @return array{modeles: list<string>, sansType: list<string>}
 */
function sansSonModelePortee(ReflectionClass $classe): array
{
    $portee = ['modeles' => [], 'sansType' => []];

    foreach ($classe->getProperties() as $propriete) {
        if ($propriete->isStatic() || sansSonModeleVientDuFramework($classe, $propriete)) {
            continue;
        }

        $type = $propriete->getType();

        if ($type === null) {
            $portee['sansType'][] = $propriete->getName();

            continue;
        }

        foreach (sansSonModeleClassesDuType($type) as $nom) {
            if (is_a($nom, Model::class, true)) {
                $portee['modeles'][] = $propriete->getName();

                break;
            }
        }
    }

    return $portee;
}

/**
 * La classe se supprime-t-elle quand un modèle qu'elle porte a disparu ?
 * L'attribut se lit sur elle, ses parentes et leurs traits, la propriété par
 * sa valeur par défaut, comme `ReadsClassAttributes` les lit.
 *
 * @param  ReflectionClass<object>  $classe
 */
function sansSonModeleSeSupprime(ReflectionClass $classe): bool
{
    if (($classe->getDefaultProperties()['deleteWhenMissingModels'] ?? false) === true) {
        return true;
    }

    for ($niveau = $classe; $niveau !== false; $niveau = $niveau->getParentClass()) {
        if ($niveau->getAttributes(DeleteWhenMissingModels::class) !== []) {
            return true;
        }

        foreach ($niveau->getTraits() as $trait) {
            if ($trait->getAttributes(DeleteWhenMissingModels::class) !== []) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Les classes en file qui portent un modèle, et les propriétés qui le portent.
 *
 * @return array<string, list<string>>
 */
function sansSonModelePorteurs(): array
{
    $porteurs = [];

    foreach (sansSonModeleClassesEnFile() as $classe) {
        $modeles = sansSonModelePortee($classe)['modeles'];

        if ($modeles !== []) {
            $porteurs[$classe->getName()] = $modeles;
        }
    }

    return $porteurs;
}

it('voit les travaux et les avis en file qui portent un modèle, et eux seuls', function (): void {
    $porteurs = sansSonModelePorteurs();

    expect($porteurs)->toMatchArray([
        SyncPersonalRecord::class => ['set', 'user'],
        SyncUserAchievements::class => ['user'],
        SyncUserGoals::class => ['user'],
        AchievementUnlocked::class => ['achievement'],
        PersonalRecordAchieved::class => ['personalRecord'],
    ])
        // Un identifiant, des chaînes, un avis qui ne porte que son
        // destinataire : rien à restaurer qui lève.
        ->and($porteurs)->not->toHaveKeys([TraiterUnChangementDAdresse::class, EnvoyerLeLienDeReinitialisation::class, TrainingReminder::class]);
});

it('fait supprimer, quand son modèle a disparu, chaque travail en file qui en porte un', function (): void {
    $enEchec = [];

    foreach (sansSonModelePorteurs() as $classe => $proprietes) {
        if (! array_key_exists($classe, sansSonModeleExceptions()) && ! sansSonModeleSeSupprime(new ReflectionClass($classe))) {
            $enEchec[] = $classe.' ($'.implode(', $', $proprietes).')';
        }
    }

    expect($enEchec)->toBe([], implode("\n", [
        'Ces classes en file portent un modèle, et partiraient dans failed_jobs s’il disparaissait avant leur tour :',
        '  '.implode("\n  ", $enEchec),
        '',
        'Leur poser #[DeleteWhenMissingModels] (Illuminate\Queue\Attributes) : Laravel les supprime alors sans les lancer (#2109).',
        "Si l'échec doit rester visible, les inscrire dans sansSonModeleExceptions(), avec leur raison.",
    ]));
});

it('type chaque propriété qu’une classe en file déclare', function (): void {
    $sansType = [];

    foreach (sansSonModeleClassesEnFile() as $classe) {
        foreach (sansSonModelePortee($classe)['sansType'] as $propriete) {
            $sansType[] = $classe->getName().'::$'.$propriete;
        }
    }

    expect($sansType)->toBe([], implode("\n", [
        'Ces propriétés de classes en file n’ont pas de type :',
        '  '.implode("\n  ", $sansType),
        '',
        'La garde ne sait pas si elles portent un modèle, que Laravel restaurerait en levant s’il avait disparu. Les typer.',
    ]));
});

it('n’excepte que des classes qui portent encore un modèle sans se supprimer', function (): void {
    $porteurs = sansSonModelePorteurs();
    $sansObjet = array_values(array_filter(
        array_keys(sansSonModeleExceptions()),
        static fn (string $classe): bool => ! array_key_exists($classe, $porteurs) || sansSonModeleSeSupprime(new ReflectionClass($classe)),
    ));

    expect($sansObjet)->toBe([], implode("\n", [
        "Ces exceptions n'ont plus d'objet : la classe a disparu, ne porte plus de modèle, ou se supprime déjà sans lui.",
        '  '.implode("\n  ", $sansObjet),
        '',
        'Les retirer de sansSonModeleExceptions() : une exception qui ne sert plus couvrirait d’avance une classe future.',
    ]));
});

it('lit un modèle dans le type d’une propriété, et la suppression là où Laravel la lit', function (object $sonde, array $portee, bool $seSupprime): void {
    $reflexion = new ReflectionClass($sonde);

    expect(sansSonModelePortee($reflexion))->toBe($portee)
        ->and(sansSonModeleSeSupprime($reflexion))->toBe($seSupprime);
})->with([
    'un modèle, sans déclaration' => [
        fn (): object => new class(null) implements ShouldQueue
        {
            public function __construct(public ?User $compte)
            {
            }
        },
        ['modeles' => ['compte'], 'sansType' => []],
        false,
    ],
    'un modèle dans une union, sous l’attribut' => [
        fn (): object => new #[DeleteWhenMissingModels] class(null) implements ShouldQueue
        {
            public function __construct(public User|Achievement|null $sujet)
            {
            }
        },
        ['modeles' => ['sujet'], 'sansType' => []],
        true,
    ],
    'un modèle, sous la propriété à vrai' => [
        fn (): object => new class(null) implements ShouldQueue
        {
            public bool $deleteWhenMissingModels = true;

            public function __construct(public ?User $compte)
            {
            }
        },
        ['modeles' => ['compte'], 'sansType' => []],
        true,
    ],
    'une collection de modèles et un identifiant' => [
        fn (): object => new class(new Collection(), 0) implements ShouldQueue
        {
            /**
             * @param  Collection<int, User>  $comptes
             */
            public function __construct(public Collection $comptes, public int $idDuCompte)
            {
            }
        },
        ['modeles' => [], 'sansType' => []],
        false,
    ],
    'une propriété sans type' => [
        fn (): object => new class() implements ShouldQueue
        {
            /** @var mixed */
            public $quelqueChose;
        },
        ['modeles' => [], 'sansType' => ['quelqueChose']],
        false,
    ],
]);
