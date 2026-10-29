<?php

declare(strict_types=1);

use App\Jobs\SyncPersonalRecord;
use App\Jobs\SyncUserAchievements;
use App\Jobs\SyncUserGoals;
use App\Models\Achievement;
use App\Models\Set;
use App\Models\User;
use App\Notifications\AchievementUnlocked;
use App\Notifications\PersonalRecordAchieved;
use App\Services\PersonalRecordService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Mail\Mailable as MailableContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\ReadsClassAttributes;
use Symfony\Component\Finder\Finder;
use Tests\Support\ParenteQuiSeSupprimeSansSonModele;
use Tests\Support\ReprendSeSupprimeSansSonModele;
use Tests\Support\SeSupprimeSansSonModele;

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
 * `ShouldQueue` : travaux, avis, courriels et écouteurs. Une propriété peut
 * porter un modèle quand son type en admet un : un modèle Eloquent, une
 * interface ou une classe qu'un modèle de `app/Models` remplit
 * (`Authenticatable`, `Arrayable`…), `object` ou `mixed`, nullable ou dans une
 * union. `SerializesModels` sérialise en identifiant toute instance de modèle,
 * quel que soit le type déclaré. Un écouteur en file reçoit l'évènement sous
 * lequel le répartiteur de l'application l'inscrit (découverte de
 * `app/Listeners` comprise) : Laravel met l'évènement dans `CallQueuedListener`
 * et le restaure avant de lancer l'écouteur. Les propriétés de l'évènement
 * comptent donc pour l'écouteur, et l'une d'elles sans type, comme le
 * framework en déclare, compte comme un modèle possible.
 *
 * Une classe qui peut porter un modèle exige la déclaration, lue comme la file
 * la lit : `ReadsClassAttributes` sur ce que Laravel met en file. C'est
 * `SendQueuedNotifications` pour un avis, `SendQueuedMailable` pour un
 * courriel, qui recopient la déclaration de la classe (le second seulement
 * dans les versions récentes de Laravel) ; l'écouteur lui-même, que
 * `Dispatcher::propagateListenerOptions()` lit pour `CallQueuedListener` ; le
 * travail tel quel. L'attribut compte sur la classe, l'une de ses parentes ou
 * l'un de leurs traits, pas sur le trait d'un trait ; `$deleteWhenMissingModels`
 * à vrai par défaut aussi, et une sous-classe qui le remet à faux dément
 * l'attribut d'une parente.
 *
 * Une collection de modèles ne compte pas : Laravel la restaure sans les
 * modèles disparus, sans lever. Une propriété sans type que la classe en file
 * déclare est refusée : la garde ne saurait pas ce qu'elle porte. Celles des
 * classes et des traits du framework (`Queueable`, `Notification`) n'en sont
 * pas. Un travail qui doit échouer sans son modèle s'inscrit dans
 * `sansSonModeleExceptions()`, avec sa raison ; une exception qui ne porte plus
 * de modèle, ou qui le déclare, fait échouer la garde. Restent hors de vue un
 * modèle tenu sous l'interface d'un paquet qu'aucun modèle de `app/Models` ne
 * remplit, et un écouteur inscrit sous une interface ou un motif (`*`).
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

    return array_any(class_uses_recursive($classe->getName()), fn (string $trait): bool => ! str_starts_with($trait, 'App\\') && property_exists($trait, $propriete->getName()));
}

/**
 * Les modèles Eloquent de `app/Models`, abstraits compris.
 *
 * @return list<class-string<Model>>
 */
function sansSonModeleModelesDeLApplication(): array
{
    $modeles = [];

    foreach (Finder::create()->files()->in(app_path('Models'))->name('*.php')->sortByName() as $fichier) {
        $classe = 'App\\Models\\'.str_replace(['/', '.php'], ['\\', ''], $fichier->getRelativePathname());

        if (class_exists($classe) && is_subclass_of($classe, Model::class)) {
            $modeles[] = $classe;
        }
    }

    if ($modeles === []) {
        throw new RuntimeException('Aucun modèle trouvé sous app/Models : la garde ne verrait aucune interface qu’un modèle remplit.');
    }

    return $modeles;
}

/**
 * Le type admet-il un modèle ? Un modèle Eloquent, une interface ou une classe
 * qu'un modèle de `app/Models` remplit, `object` ou `mixed` ; dans une union,
 * l'un de ses membres ; dans une intersection, chacun.
 */
function sansSonModeleTypeAdmetUnModele(ReflectionType $type): bool
{
    if ($type instanceof ReflectionUnionType) {
        return array_any($type->getTypes(), static fn (ReflectionType $membre): bool => sansSonModeleTypeAdmetUnModele($membre));
    }

    if ($type instanceof ReflectionIntersectionType) {
        return array_all($type->getTypes(), static fn (ReflectionType $membre): bool => sansSonModeleTypeAdmetUnModele($membre));
    }

    if (! $type instanceof ReflectionNamedType) {
        return false;
    }

    $nom = $type->getName();

    if ($type->isBuiltin()) {
        return in_array($nom, ['mixed', 'object'], true);
    }

    return is_a($nom, Model::class, true)
        || array_any(sansSonModeleModelesDeLApplication(), static fn (string $modele): bool => is_a($modele, $nom, true));
}

/**
 * Les propriétés que la classe tient d'elle-même, ou de ses parentes et de
 * ses traits de l'application : ni statiques, ni du framework.
 *
 * @param  ReflectionClass<object>  $classe
 * @return list<ReflectionProperty>
 */
function sansSonModeleProprietesTenues(ReflectionClass $classe): array
{
    return array_values(array_filter(
        $classe->getProperties(),
        static fn (ReflectionProperty $propriete): bool => ! $propriete->isStatic() && ! sansSonModeleVientDuFramework($classe, $propriete),
    ));
}

/**
 * Les évènements que reçoit un écouteur en file, chacun avec le nom du
 * paramètre qui le reçoit : ceux sous lesquels le répartiteur de
 * l'application l'inscrit, découverte de `app/Listeners` comprise, la méthode
 * se lisant comme Laravel la lit (`handle` par défaut, `__invoke` à défaut).
 * Un travail n'en reçoit aucun : les paramètres de son `handle()` sont des
 * services que le conteneur construit au lancement, hors de la charge. Un
 * évènement nommé par une chaîne (`eloquent.saved: …`) n'est pas une classe.
 *
 * @param  ReflectionClass<object>  $classe
 * @return list<array{0: string, 1: ReflectionClass<object>}>
 */
function sansSonModeleEvenementsRecus(ReflectionClass $classe): array
{
    $recus = [];

    foreach (Event::getRawListeners() as $evenement => $ecouteurs) {
        if (! is_string($evenement) || ! class_exists($evenement) || ! is_array($ecouteurs)) {
            continue;
        }

        foreach ($ecouteurs as $ecouteur) {
            [$nom, $methode] = match (true) {
                is_string($ecouteur) => Str::parseCallback($ecouteur, 'handle'),
                is_array($ecouteur) && is_string($ecouteur[0] ?? null) => [$ecouteur[0], $ecouteur[1] ?? 'handle'],
                default => [null, null],
            };

            if ($nom !== $classe->getName()) {
                continue;
            }

            $methode = is_string($methode) && $classe->hasMethod($methode) ? $methode : '__invoke';
            $parametre = $classe->hasMethod($methode) ? ($classe->getMethod($methode)->getParameters()[0] ?? null)?->getName() : null;
            $recus[] = [$parametre ?? 'evenement', new ReflectionClass($evenement)];
        }
    }

    return $recus;
}

/**
 * Ce que la classe porte qui se restaure en levant quand il a disparu : ses
 * propriétés dont le type admet un modèle, celles, sans type, qu'elle déclare,
 * et, pour un écouteur, les propriétés de l'évènement qu'il reçoit qui en
 * admettent un ou n'ont pas de type (`evenement->serie`).
 *
 * @param  ReflectionClass<object>  $classe
 * @return array{modeles: list<string>, sansType: list<string>}
 */
function sansSonModelePortee(ReflectionClass $classe): array
{
    $portee = ['modeles' => [], 'sansType' => []];

    foreach (sansSonModeleProprietesTenues($classe) as $propriete) {
        $type = $propriete->getType();

        if ($type === null) {
            $portee['sansType'][] = $propriete->getName();
        } elseif (sansSonModeleTypeAdmetUnModele($type)) {
            $portee['modeles'][] = $propriete->getName();
        }
    }

    foreach (sansSonModeleEvenementsRecus($classe) as [$parametre, $evenement]) {
        foreach (sansSonModeleProprietesTenues($evenement) as $propriete) {
            $type = $propriete->getType();

            if ($type === null || sansSonModeleTypeAdmetUnModele($type)) {
                $portee['modeles'][] = $parametre.'->'.$propriete->getName();
            }
        }
    }

    $portee['modeles'] = array_values(array_unique($portee['modeles']));

    return $portee;
}

/**
 * La classe se supprime-t-elle quand un modèle qu'elle porte a disparu ? La
 * réponse est celle de la charge que Laravel écrirait : `deleteWhenMissingModels`
 * lu par `ReadsClassAttributes` sur ce que la file reçoit
 * (`Queue::createObjectPayload()`). Un avis y part dans
 * `SendQueuedNotifications`, un courriel dans `SendQueuedMailable`, qui
 * recopient à leur construction ce qu'ils lisent sur la classe ; un écouteur
 * dans `CallQueuedListener`, sur lequel `Dispatcher::propagateListenerOptions()`
 * recopie ce qu'il lit sur l'écouteur : lire l'écouteur revient au même. La
 * classe n'est pas construite, comme le répartiteur ne construit pas
 * l'écouteur qu'il lit.
 *
 * @param  ReflectionClass<object>  $classe
 */
function sansSonModeleSeSupprime(ReflectionClass $classe): bool
{
    $instance = $classe->newInstanceWithoutConstructor();
    $misEnFile = match (true) {
        $instance instanceof Notification => new SendQueuedNotifications(new Collection(), $instance),
        $instance instanceof MailableContract => new SendQueuedMailable($instance),
        default => $instance,
    };

    $file = new class()
    {
        use ReadsClassAttributes;

        public function supprimeSansSonModele(object $misEnFile): bool
        {
            return (bool) ($this->getAttributeValue($misEnFile, DeleteWhenMissingModels::class, 'deleteWhenMissingModels') ?? false);
        }
    };

    return $file->supprimeSansSonModele($misEnFile);
}

/**
 * Les classes en file qui portent un modèle, et les propriétés qui le portent.
 *
 * @return array<class-string, list<string>>
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

/**
 * Inscrit l'écouteur sous l'évènement, comme la découverte de `app/Listeners`
 * ou un `Event::listen()` d'un fournisseur, et le rend.
 */
function sansSonModeleEcouteurInscrit(object $ecouteur, object $evenement): object
{
    Event::listen($evenement::class, [$ecouteur::class, 'handle']);

    return $ecouteur;
}

it('voit les travaux et les avis en file qui portent un modèle, et eux seuls', function (): void {
    // La carte entière : une classe de plus, même déclarée, fait tomber le cas.
    // Les autres (`TraiterUnChangementDAdresse`, `EnvoyerLeLienDeReinitialisation`,
    // `TrainingReminder`…) portent un identifiant, des chaînes, ou leur seul
    // destinataire : rien à restaurer qui lève.
    expect(sansSonModelePorteurs())->toBe([
        SyncPersonalRecord::class => ['set', 'user'],
        SyncUserAchievements::class => ['user'],
        SyncUserGoals::class => ['user'],
        AchievementUnlocked::class => ['achievement'],
        PersonalRecordAchieved::class => ['personalRecord'],
    ]);
});

it('fait supprimer, quand son modèle a disparu, chaque travail en file qui en porte un', function (): void {
    $enEchec = [];

    foreach (sansSonModelePorteurs() as $classe => $proprietes) {
        if (! array_key_exists($classe, sansSonModeleExceptions()) && ! sansSonModeleSeSupprime(new ReflectionClass($classe))) {
            $enEchec[] = $classe.' ($'.implode(', $', $proprietes).')';
        }
    }

    expect($enEchec)->toBe([], implode("\n", [
        'Ces classes en file peuvent porter un modèle (le leur, ou celui de l’évènement qu’un écouteur reçoit), et partiraient dans failed_jobs s’il disparaissait avant leur tour :',
        '  '.implode("\n  ", $enEchec),
        '',
        'Leur poser #[DeleteWhenMissingModels] (Illuminate\Queue\Attributes), sur l’écouteur pour un évènement : Laravel les supprime alors sans les lancer (#2109).',
        'Un type plus précis qu’une interface, object ou mixed suffit quand la propriété ne tient jamais de modèle.',
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
    'un modèle sous une interface qu’un modèle remplit' => [
        fn (): object => new class(null) implements ShouldQueue
        {
            public function __construct(public ?Authenticatable $compte)
            {
            }
        },
        ['modeles' => ['compte'], 'sansType' => []],
        false,
    ],
    'un modèle sous mixed' => [
        fn (): object => new class() implements ShouldQueue
        {
            public function __construct(public mixed $sujet = null)
            {
            }
        },
        ['modeles' => ['sujet'], 'sansType' => []],
        false,
    ],
    'un modèle sous object, sous l’attribut' => [
        fn (): object => new #[DeleteWhenMissingModels] class(null) implements ShouldQueue
        {
            public function __construct(public ?object $sujet)
            {
            }
        },
        ['modeles' => ['sujet'], 'sansType' => []],
        true,
    ],
    'une interface qu’aucun modèle ne remplit' => [
        fn (): object => new class(null) implements ShouldQueue
        {
            public function __construct(public ?DateTimeInterface $instant)
            {
            }
        },
        ['modeles' => [], 'sansType' => []],
        false,
    ],
    // Un travail reçoit dans handle() des services que le conteneur construit
    // au lancement : ils ne voyagent pas dans la charge.
    'un travail dont handle() reçoit un service' => [
        fn (): object => new class() implements ShouldQueue
        {
            public function handle(PersonalRecordService $service): void
            {
            }
        },
        ['modeles' => [], 'sansType' => []],
        false,
    ],
    'un écouteur en file d’un évènement qui porte un modèle' => [
        fn (): object => sansSonModeleEcouteurInscrit(
            new class() implements ShouldQueue
            {
                public function handle(object $evenement): void
                {
                }
            },
            new class(null)
            {
                use SerializesModels;

                public function __construct(public ?Set $serie)
                {
                }
            },
        ),
        ['modeles' => ['evenement->serie'], 'sansType' => []],
        false,
    ],
    'un écouteur en file d’un évènement qui porte un modèle, sous l’attribut' => [
        fn (): object => sansSonModeleEcouteurInscrit(
            new #[DeleteWhenMissingModels] class() implements ShouldQueue
            {
                public function handle(object $evenement): void
                {
                }
            },
            new class(null)
            {
                use SerializesModels;

                public function __construct(public ?Set $serie)
                {
                }
            },
        ),
        ['modeles' => ['evenement->serie'], 'sansType' => []],
        true,
    ],
    // Une propriété d'évènement sans type ne se refuse pas : l'évènement peut
    // venir du framework, que l'application ne type pas. Elle peut tenir un modèle.
    'un écouteur en file d’un évènement dont une propriété n’a pas de type' => [
        fn (): object => sansSonModeleEcouteurInscrit(
            new class() implements ShouldQueue
            {
                public function handle(object $recu): void
                {
                }
            },
            new class()
            {
                use SerializesModels;

                /** @var mixed */
                public $quelqueChose;
            },
        ),
        ['modeles' => ['recu->quelqueChose'], 'sansType' => []],
        false,
    ],
    'un modèle, l’attribut sur une classe parente' => [
        fn (): object => new class(null) extends ParenteQuiSeSupprimeSansSonModele
        {
            public function __construct(public ?User $compte)
            {
            }
        },
        ['modeles' => ['compte'], 'sansType' => []],
        true,
    ],
    'un modèle, l’attribut sur un trait' => [
        fn (): object => new class(null) implements ShouldQueue
        {
            use SeSupprimeSansSonModele;

            public function __construct(public ?User $compte)
            {
            }
        },
        ['modeles' => ['compte'], 'sansType' => []],
        true,
    ],
    'un modèle, l’attribut sur le trait d’un trait, que Laravel ne lit pas' => [
        fn (): object => new class(null) implements ShouldQueue
        {
            use ReprendSeSupprimeSansSonModele;

            public function __construct(public ?User $compte)
            {
            }
        },
        ['modeles' => ['compte'], 'sansType' => []],
        false,
    ],
    'un modèle, l’attribut d’une parente démenti par la propriété à faux' => [
        fn (): object => new class(null) extends ParenteQuiSeSupprimeSansSonModele
        {
            public bool $deleteWhenMissingModels = false;

            public function __construct(public ?User $compte)
            {
            }
        },
        ['modeles' => ['compte'], 'sansType' => []],
        false,
    ],
    'un avis qui porte un modèle, sous l’attribut' => [
        fn (): object => new #[DeleteWhenMissingModels] class(null) extends Notification implements ShouldQueue
        {
            public function __construct(public ?Set $serie)
            {
            }
        },
        ['modeles' => ['serie'], 'sansType' => []],
        true,
    ],
    // Laravel lit la déclaration d'un courriel sur l'enveloppe que la file
    // reçoit, `SendQueuedMailable`, qui ne la recopie du courriel que dans ses
    // versions récentes : la garde suit la version installée.
    'un courriel qui porte un modèle, sous l’attribut' => [
        fn (): object => new #[DeleteWhenMissingModels] class(null) extends Mailable implements ShouldQueue
        {
            public function __construct(public ?Set $serie)
            {
            }
        },
        ['modeles' => ['serie'], 'sansType' => []],
        property_exists(SendQueuedMailable::class, 'deleteWhenMissingModels'),
    ],
]);
