<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Admin;
use App\Models\BodyMeasurement;
use App\Models\BodyPartMeasurement;
use App\Models\DailyJournal;
use App\Models\Exercise;
use App\Models\Fast;
use App\Models\Goal;
use App\Models\Habit;
use App\Models\IntervalTimer;
use App\Models\MacroCalculation;
use App\Models\Plate;
use App\Models\Set;
use App\Models\Supplement;
use App\Models\User;
use App\Models\WaterLog;
use App\Models\WilksScore;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Models\WorkoutTemplate;
use App\Notifications\PersonalRecordAchieved;
use Closure;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route as Routeur;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Attributes\Url;
use LogicException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionFunction;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Ce que partagent les gardes des requêtes mal formées (#2071) : les routes
 * de l'application, leurs paramètres remplis, les formes essayées, les noms
 * qu'une requête peut lire, et ce qui ne va pas dans une réponse.
 *
 * Une route « de l'application » est une route dont l'action vit hors de
 * vendor/, comme `route:list --except-vendor` les liste : les pages du
 * panneau en sont, leurs classes vivant dans app/Filament ; Pulse, Horizon,
 * le lecteur de journaux, les rôles et les exceptions n'en sont pas.
 */
final class RequetesMalFormees
{
    /**
     * Les formes d'une chaîne de requête : pour un nom, son morceau.
     *
     * @return array<string, Closure(string): string>
     */
    public static function formesDeChaine(): array
    {
        return [
            'tableau' => static fn (string $nom): string => $nom.'[]=7j',
            'chaîne vide' => static fn (string $nom): string => $nom.'=',
            'chaîne énorme' => static fn (string $nom): string => $nom.'='.str_repeat('a', 4096),
            'nombre hors bornes' => static fn (string $nom): string => $nom.'='.PHP_INT_MAX,
            'pas de l’UTF-8' => static fn (string $nom): string => $nom.'=%FF%FE',
        ];
    }

    /**
     * Les formes d'un corps JSON porté par un GET, chaque nom lu prenant la
     * valeur : `input()` cherche dans le corps avant la chaîne de requête, et
     * un corps JSON y porte un nombre, ce qu'une chaîne de requête ne fait
     * pas (la pagination prenait une page démesurée). Une seule forme, pour
     * une requête de plus par page.
     *
     * @return array<string, mixed>
     */
    public static function formesDeCorpsSurGet(): array
    {
        return [
            'corps JSON, nombre hors bornes' => PHP_INT_MAX,
        ];
    }

    /**
     * Les variables de serveur d'une visite : ses en-têtes, et le type d'un
     * corps JSON quand elle en porte un. Un en-tête en liste arrive répété
     * (deux lignes), comme Symfony le range.
     *
     * @param  array<string, string|list<string>>  $entetes
     * @return array<string, string|list<string>>
     */
    public static function serveur(array $entetes, ?string $corps): array
    {
        $serveur = [];

        foreach ($entetes as $nom => $valeur) {
            $serveur['HTTP_'.strtoupper(str_replace('-', '_', $nom))] = $valeur;
        }

        if ($corps !== null) {
            $serveur['CONTENT_TYPE'] = 'application/json';
        }

        return $serveur;
    }

    /**
     * Les formes d'un champ du corps, envoyé en JSON (qui ne porte pas un
     * octet hors UTF-8). Un tableau imbriqué d'au moins deux éléments passe
     * `array_unique()` d'`exists`, puis lève dans `whereIn()` ; un flottant
     * démesuré avertit à la conversion en entier, là où un entier démesuré
     * n'avertit pas ; la liste d'objets porte, à chaque profondeur, chaque
     * nom qu'une requête valide sous un champ (`exercises.*.id`,
     * `exercises.*.sets.*.reps`…), ce qu'un lecteur hors des règles lit aussi ;
     * une date suivie de mots démesurés se lit comme une date, dont Carbon
     * applique le sens (#2094, l'instant d'un geste avant sa validation).
     *
     * @return array<string, mixed>
     */
    public static function formesDeCorps(): array
    {
        return [
            'tableau' => ['7j'],
            'tableau imbriqué' => [['7j', '7j']],
            'chaîne vide' => '',
            'chaîne énorme' => str_repeat('a', 4096),
            'nombre hors bornes' => PHP_INT_MAX,
            'flottant hors bornes' => 1e30,
            'liste d’objets' => self::listeDObjets(1e30),
            'date suivie de mots démesurés' => '2026-10-21T10:00:00Z -290000000000 years',
        ];
    }

    /**
     * Les formes d'un champ d'un formulaire (`application/x-www-form-urlencoded`),
     * qui peut porter ce que JSON ne porte pas.
     *
     * @return array<string, string>
     */
    public static function formesDeFormulaire(): array
    {
        return [
            'formulaire, pas de l’UTF-8' => "\xFF\xFE",
        ];
    }

    /**
     * Une liste d'un objet qui porte, à chaque profondeur, chaque nom qu'une
     * requête de app/Http/Requests valide dans les éléments d'une liste, la
     * feuille valant `$feuille` : `exercises.*.id` et `exercises.*.sets.*.reps`
     * donnent `[['id' => $feuille, 'sets' => [['reps' => $feuille]]]]`.
     *
     * @return list<array<mixed>>
     */
    public static function listeDObjets(mixed $feuille): array
    {
        $chemins = [];

        foreach (array_keys(ReglesDesRequetes::toutes()) as $classe) {
            foreach (array_keys(ReglesDesRequetes::de($classe)) as $champ) {
                $dansLaListe = Str::after($champ, '.');

                if (str_starts_with($dansLaListe, '*.')) {
                    $chemins[] = str_replace('*', '0', Str::after($dansLaListe, '*.'));
                }
            }
        }

        $chemins = array_values(array_unique($chemins));
        usort($chemins, static fn (string $a, string $b): int => substr_count($a, '.') <=> substr_count($b, '.'));

        $objet = [];

        foreach ($chemins as $chemin) {
            Arr::set($objet, $chemin, $feuille);
        }

        return [$objet];
    }

    /**
     * Des noms courants, et ceux que le code de app/ et routes/ lit dans la
     * requête par une de ses méthodes (`query('period')`, `input('page')`…).
     *
     * @return list<string>
     */
    public static function nomsLus(): array
    {
        $noms = ['period', 'page', 'search', 'sort', 'filter', 'date', 'month', 'year', 'type', 'tab', 'jours', 'portees'];
        $methodes = 'query|input|string|str|integer|float|boolean|date|enum|collect|array|has|filled';

        foreach (Finder::create()->files()->in([app_path(), base_path('routes')])->name('*.php') as $fichier) {
            preg_match_all("/(?:request\\(\\)|\\\$(?:request|requete|this))->(?:{$methodes})\\(\\s*'([A-Za-z_][A-Za-z0-9_]*)'/", $fichier->getContents(), $trouves);
            array_push($noms, ...$trouves[1]);
        }

        return array_values(array_unique($noms));
    }

    /**
     * Les noms que Livewire lie à la chaîne de requête sur un composant
     * (`#[Url]`, sous son alias s'il en a un), parents et traits compris.
     *
     * @return list<string>
     */
    public static function nomsLiesParLivewire(string $classe): array
    {
        if (! class_exists($classe)) {
            return [];
        }

        $noms = [];

        foreach (new ReflectionClass($classe)->getProperties() as $propriete) {
            foreach ($propriete->getAttributes(Url::class, ReflectionAttribute::IS_INSTANCEOF) as $attribut) {
                $alias = $attribut->newInstance()->as;
                $noms[] = is_string($alias) ? $alias : $propriete->getName();
            }
        }

        return $noms;
    }

    /**
     * Le chemin de chaque champ que valide une requête de app/Http/Requests,
     * jusqu'à sa première liste : `keys.auth` reste `keys.auth`,
     * `exercises.*.id` donne `exercises` (la liste d'objets porte ce qui la
     * suit).
     *
     * @return list<string>
     */
    public static function champsValides(): array
    {
        $champs = [];

        foreach (array_keys(ReglesDesRequetes::toutes()) as $classe) {
            foreach (array_keys(ReglesDesRequetes::de($classe)) as $champ) {
                $champs[] = Str::before($champ, '.*');
            }
        }

        return array_values(array_unique($champs));
    }

    /**
     * Un corps où chaque chemin validé prend la valeur, un chemin imbriqué
     * hors liste (`keys.auth`) à sa place, son parent devenant l'objet qui le
     * porte.
     *
     * @return array<mixed>
     */
    public static function corpsMalForme(mixed $valeur): array
    {
        $chemins = self::champsValides();
        usort($chemins, static fn (string $a, string $b): int => substr_count($a, '.') <=> substr_count($b, '.'));
        $corps = [];

        foreach ($chemins as $chemin) {
            Arr::set($corps, $chemin, $valeur);
        }

        return $corps;
    }

    /**
     * La classe de l'action d'une route, ou une chaîne vide pour une
     * fermeture.
     */
    public static function classeDeLAction(Route $route): string
    {
        $action = $route->getAction('uses');

        return is_string($action) ? Str::before($action, '@') : '';
    }

    /**
     * Les routes de l'application qui répondent à l'une de ces méthodes.
     *
     * @param  list<string>  $methodes
     * @return list<Route>
     */
    public static function routes(array $methodes): array
    {
        $vendor = (string) realpath(base_path('vendor'));

        return array_values(array_filter(
            Routeur::getRoutes()->getRoutes(),
            static function (Route $route) use ($methodes, $vendor): bool {
                $fichier = self::fichierDeLAction($route);

                return array_intersect($methodes, $route->methods()) !== []
                    && $fichier !== ''
                    && ! str_starts_with($fichier, $vendor);
            },
        ));
    }

    /**
     * Une route du panneau ?
     */
    public static function duPanneau(Route $route): bool
    {
        return $route->uri() === 'backoffice' || str_starts_with($route->uri(), 'backoffice/');
    }

    /**
     * Une route réservée aux invités, qu'un invité visite ? Les rappels et
     * les départs vers les fournisseurs de connexion n'en sont pas : un
     * rappel appellerait le fournisseur. Un compte connecté s'y voit
     * renvoyer, comme toute page d'invité.
     */
    public static function pourUnInvite(Route $route): bool
    {
        return in_array('guest', $route->gatherMiddleware(), true)
            && ! str_starts_with((string) $route->getName(), 'social.');
    }

    /**
     * Le compte reçoit une ligne dans chaque liste paginée : `forPage()` ne
     * calcule pas le décalage d'une liste vide, et une page trop grande n'y
     * fait rien.
     */
    public static function remplirLesListes(User $compte): void
    {
        Workout::withoutEvents(static fn (): Workout => Workout::factory()->create(['user_id' => $compte->id, 'ended_at' => now()]));
        Fast::factory()->create(['user_id' => $compte->id, 'status' => 'completed', 'end_time' => now()]);
        $compte->notifications()->create([
            'id' => Str::uuid()->toString(),
            'type' => PersonalRecordAchieved::class,
            'data' => ['message' => 'Record'],
            'read_at' => null,
        ]);
    }

    /**
     * La valeur d'un paramètre de route, créée pour le compte à la demande :
     * un nom inconnu fait échouer la garde, pour qu'il soit ajouté ici plutôt
     * que la route oubliée. Gardée d'une demande à l'autre, sauf `$neuve`,
     * pour une écriture qui supprime ce qu'une autre lirait ensuite.
     *
     * @return Closure(string): string
     */
    public static function valeursDeRoute(User $compte, bool $neuve = false): Closure
    {
        $proprietaire = ['user_id' => $compte->id];
        $seance = static fn (): Workout => Workout::withoutEvents(static fn (): Workout => Workout::factory()->create($proprietaire));
        $ligne = static fn (): WorkoutLine => WorkoutLine::factory()->create(['workout_id' => $seance()->id]);
        $fabriques = [
            'workout' => static fn (): int => $seance()->id,
            'workoutLine' => static fn (): int => $ligne()->id,
            'workout_line' => static fn (): int => $ligne()->id,
            'set' => static fn (): int => Set::factory()->create(['workout_line_id' => $ligne()->id])->id,
            'template' => static fn (): int => WorkoutTemplate::factory()->create($proprietaire)->id,
            'exercise' => static fn (): int => Exercise::factory()->create($proprietaire)->id,
            'goal' => static fn (): int => Goal::factory()->create($proprietaire)->id,
            'habit' => static fn (): int => Habit::factory()->create($proprietaire)->id,
            'supplement' => static fn (): int => Supplement::factory()->create($proprietaire)->id,
            'plate' => static fn (): int => Plate::factory()->create($proprietaire)->id,
            'daily_journal' => static fn (): int => DailyJournal::factory()->create($proprietaire)->id,
            'body_measurement' => static fn (): int => BodyMeasurement::factory()->create($proprietaire)->id,
            'bodyPartMeasurement' => static fn (): int => BodyPartMeasurement::factory()->create([...$proprietaire, 'part' => 'Biceps'])->id,
            'part' => static fn (): string => BodyPartMeasurement::factory()->create([...$proprietaire, 'part' => 'Biceps'])->part,
            'fast' => static fn (): int => Fast::factory()->create($proprietaire)->id,
            'intervalTimer' => static fn (): int => IntervalTimer::factory()->create($proprietaire)->id,
            'macroCalculation' => static fn (): int => MacroCalculation::factory()->create($proprietaire)->id,
            'waterLog' => static fn (): int => WaterLog::factory()->create($proprietaire)->id,
            'wilksScore' => static fn (): int => WilksScore::factory()->create($proprietaire)->id,
            'id' => static fn (): string => (string) $compte->notifications()->create([
                'id' => Str::uuid()->toString(),
                'type' => PersonalRecordAchieved::class,
                'data' => ['message' => 'Record'],
                'read_at' => null,
            ])->id,
            'hash' => static fn (): string => sha1($compte->email),
            'token' => static fn (): string => 'jeton',
            'provider' => static fn (): string => 'google',
            // La clé d'une séance démarrée sans réseau (`workouts.en-attente`).
            'cle' => static fn (): string => Str::uuid()->toString(),
        ];
        $gardees = [];

        return static function (string $nom) use ($fabriques, $neuve, &$gardees): string {
            $fabrique = $fabriques[$nom] ?? throw new LogicException("Paramètre de route inconnu : {$nom}. Ajoute-le à RequetesMalFormees::valeursDeRoute().");

            if ($neuve) {
                return (string) $fabrique();
            }

            return $gardees[$nom] ??= (string) $fabrique();
        };
    }

    /**
     * L'adresse d'une route, ses paramètres remplis ; l'enregistrement d'une
     * page du panneau est créé par la fabrique du modèle de sa ressource.
     *
     * @param  Closure(string): string  $valeur
     */
    public static function adresse(Route $route, Closure $valeur): string
    {
        $adresse = preg_replace_callback('/\{([^}?]+)\??\}/', static function (array $parametre) use ($route, $valeur): string {
            $modele = self::modeleDeLaPage(self::classeDeLAction($route));

            if ($parametre[1] === 'record' && $modele !== null) {
                $cle = Factory::factoryForModel($modele)->createOne()->getKey();

                return is_scalar($cle) ? (string) $cle : throw new LogicException("Clef illisible : {$modele}.");
            }

            return $valeur($parametre[1]);
        }, $route->uri());

        return '/'.ltrim((string) $adresse, '/');
    }

    /**
     * Un administrateur qui ouvre chaque page du panneau : le rôle de super
     * administrateur (les capacités des outils) et les permissions Shield de
     * chaque ressource que ces routes nomment.
     *
     * @param  list<Route>  $routes
     */
    public static function administrateur(array $routes): Admin
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Admin::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'admin'));

        foreach ($routes as $route) {
            $modele = self::modeleDeLaPage(self::classeDeLAction($route));

            foreach ($modele === null ? [] : FilamentAdminPanel::crudPermissions(class_basename($modele)) as $permission) {
                $admin->givePermissionTo(Permission::findOrCreate($permission, 'admin'));
            }
        }

        Filament::setCurrentPanel('admin');

        return $admin;
    }

    /**
     * Le témoin (la forme « chaîne vide ») a-t-il atteint la page ? Une route
     * signée répond 403, sa signature couvrant la chaîne de requête ;
     * `templates.show` répond toujours 404 (`WorkoutTemplateController::show()`,
     * un modèle se consulte depuis la liste) ; toute autre route répond moins
     * de 400.
     */
    public static function temoinAtteint(Route $route, int $statut): bool
    {
        $signee = array_filter($route->gatherMiddleware(), static fn (mixed $maillon): bool => is_string($maillon) && str_starts_with($maillon, 'signed')) !== [];

        return match (true) {
            $route->getName() === 'templates.show' => $statut === 404,
            $signee => $statut === 403,
            default => $statut < 400,
        };
    }

    /**
     * Le JSON d'une page Inertia rendue en entier : `null` si la réponse
     * n'en est pas une, `false` si son JSON manque ou ne se lit pas (une
     * page blanche, à 200).
     *
     * @param  TestResponse<Response>  $reponse
     * @return array<array-key, mixed>|false|null
     */
    public static function pageInertia(TestResponse $reponse): array|false|null
    {
        if (preg_match('#<script data-page="app" type="application/json">(.*?)</script>#s', (string) $reponse->getContent(), $script) !== 1) {
            return null;
        }

        $page = json_decode(html_entity_decode($script[1]), true);

        return is_array($page) ? $page : false;
    }

    /**
     * Les en-têtes qui demandent toutes les props différées d'une page,
     * comme le client Inertia ; `null` si elle n'en a pas.
     *
     * @param  array<array-key, mixed>  $page
     * @return array<string, string>|null
     */
    public static function entetesDesDifferees(array $page): ?array
    {
        $groupes = is_array($page['deferredProps'] ?? null) ? $page['deferredProps'] : [];
        $props = [];

        foreach ($groupes as $groupe) {
            array_push($props, ...(is_array($groupe) ? array_filter($groupe, is_string(...)) : []));
        }

        if ($props === []) {
            return null;
        }

        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => is_string($page['version'] ?? null) ? $page['version'] : '',
            'X-Inertia-Partial-Component' => is_string($page['component'] ?? null) ? $page['component'] : '',
            'X-Inertia-Partial-Data' => implode(',', $props),
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'text/html, application/xhtml+xml',
        ];
    }

    /**
     * Ce qui ne va pas dans une réponse : une 500, une exception rapportée
     * (une erreur rattrapée puis rapportée ne se voit pas au statut).
     * `Exceptions::fake()` doit avoir été posé avant la requête.
     *
     * @param  TestResponse<Response>  $reponse
     * @return list<string>
     */
    public static function defauts(TestResponse $reponse, string $cas): array
    {
        $defauts = [];

        if ($reponse->getStatusCode() >= 500) {
            $defauts[] = "{$cas} : {$reponse->getStatusCode()}";
        }

        $rapportees = Exceptions::reported();

        foreach (is_array($rapportees) ? $rapportees : [] as $exception) {
            if ($exception instanceof Throwable) {
                $defauts[] = "{$cas} : ".$exception::class.' rapportée ('.Str::limit($exception->getMessage(), 120).')';
            }
        }

        return $defauts;
    }

    /**
     * Demande chaque page une fois par forme de chaîne de requête, avec tous
     * les noms qu'elle peut lire sous cette forme, et, sauf `$corpsJson` à
     * faux, une fois par forme d'un corps JSON qui les porte, puis ses props
     * différées si c'est une page Inertia. Rend ce qui ne va pas : une 500,
     * une exception rapportée, une page Inertia sans JSON lisible, un témoin
     * qui n'atteint pas la page.
     *
     * @param  list<Route>  $routes
     * @param  Closure(string): string  $valeurs
     * @param  Closure(Route, string, array<string, string>, ?string): TestResponse<Response>  $visiter
     * @return array{defauts: list<string>, requetes: int}
     */
    public static function visiterChaquePage(array $routes, Closure $valeurs, Closure $visiter, bool $corpsJson = true): array
    {
        $nomsLus = self::nomsLus();
        $defauts = [];
        $requetes = 0;

        foreach ($routes as $route) {
            $adresse = self::adresse($route, $valeurs);
            $noms = array_values(array_unique([...$nomsLus, ...self::nomsLiesParLivewire(self::classeDeLAction($route))]));
            $essais = [];

            foreach (self::formesDeChaine() as $forme => $morceau) {
                $essais[$forme] = [$adresse.'?'.implode('&', array_map($morceau, $noms)), null];
            }

            foreach ($corpsJson ? self::formesDeCorpsSurGet() : [] as $forme => $valeur) {
                $essais[$forme] = [$adresse, json_encode(array_fill_keys($noms, $valeur), JSON_THROW_ON_ERROR)];
            }

            foreach ($essais as $forme => [$url, $corps]) {
                $cas = "GET {$route->uri()} ({$forme})";
                $reponse = $visiter($route, $url, [], $corps);
                $requetes++;
                $defauts = [...$defauts, ...self::defauts($reponse, $cas)];

                if ($forme === 'chaîne vide' && ! self::temoinAtteint($route, $reponse->getStatusCode())) {
                    $defauts[] = "{$cas} : témoin à {$reponse->getStatusCode()}, la page n'est pas atteinte";
                }

                $page = self::pageInertia($reponse);

                if ($page === false) {
                    $defauts[] = "{$cas} : page Inertia sans JSON lisible (page blanche)";
                }

                $entetes = is_array($page) ? self::entetesDesDifferees($page) : null;

                if ($entetes !== null) {
                    $defauts = [...$defauts, ...self::defauts($visiter($route, $url, $entetes, $corps), "{$cas}, props différées")];
                    $requetes++;
                }
            }
        }

        return ['defauts' => $defauts, 'requetes' => $requetes];
    }

    /**
     * Le modèle de la ressource d'une page du panneau, `null` pour une autre
     * classe.
     *
     * @return class-string<Model>|null
     */
    private static function modeleDeLaPage(string $classe): ?string
    {
        $ressource = method_exists($classe, 'getResource') ? $classe::getResource() : null;

        return is_string($ressource) && is_subclass_of($ressource, Resource::class) ? $ressource::getModel() : null;
    }

    /**
     * Le fichier où vit l'action d'une route, comme `route:list` le cherche.
     */
    private static function fichierDeLAction(Route $route): string
    {
        $action = $route->getAction('uses');
        $classe = self::classeDeLAction($route);

        $fichier = match (true) {
            $action instanceof Closure => new ReflectionFunction($action)->getFileName(),
            class_exists($classe) => new ReflectionClass($classe)->getFileName(),
            default => false,
        };

        return is_string($fichier) ? (string) realpath($fichier) : '';
    }
}
