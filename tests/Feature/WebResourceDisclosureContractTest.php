<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Le meme contrat que `tests/Feature/Api/ResourceDisclosureContractTest.php`,
 * pour les routes servies par le routeur web.
 *
 * #1418 a ferme l'oracle sur `api/v1/*` et son correctif s'y est borne
 * volontairement. Les memes ressources sont pourtant atteignables par le web,
 * ou une demande sur le bien d'autrui repondait 403 quand un identifiant
 * inconnu repondait 404 : la meme distinction, sur la meme base, par une autre
 * porte. #1432 l'a releve ; la sonde ci-dessous l'a retrouve sur 33 des 34
 * routes web liees a un modele.
 *
 * Ce fichier enumere les routes plutot que d'en reciter la liste, pour la meme
 * raison que son homologue API : une liste ecrite a la main cesse d'etre vraie
 * des la route suivante, sans que rien ne le dise.
 */

/**
 * Le modele qu'une route resout, lu sur la signature du controleur.
 *
 * La liaison de modele se declare par annotation de type et nulle part
 * ailleurs, donc l'annotation est ce que la route entend par son parametre.
 *
 * @return class-string<Model>|null
 */
function webBoundModelClass(\Illuminate\Routing\Route $route): ?string
{
    foreach (webActionParameters($route) as $parameter) {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            continue;
        }

        /** @var class-string $candidate */
        $candidate = $type->getName();

        if (is_subclass_of($candidate, Model::class)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Les parametres de la methode de controleur que la route invoque.
 *
 * Les controleurs invocables n'ont pas de `@methode` dans leur action ; les
 * omettre ferait sortir du balayage toute route ainsi ecrite, en silence.
 *
 * @return list<ReflectionParameter>
 */
function webActionParameters(\Illuminate\Routing\Route $route): array
{
    $action = $route->getAction('uses');

    if (! is_string($action)) {
        return [];
    }

    if (str_contains($action, '@')) {
        [$controller, $method] = explode('@', $action);
    } else {
        $controller = $action;
        $method = '__invoke';
    }

    if (! class_exists($controller) || ! method_exists($controller, $method)) {
        return [];
    }

    return new ReflectionMethod($controller, $method)->getParameters();
}

/**
 * Toute route non-API qui resout exactement un modele de l'application.
 *
 * Le filtre sur `App\Models\` ecarte les routes de paquets — Filament en
 * declare qui lient leurs propres modeles, sans fabrique et sans rapport avec
 * les donnees de l'utilisateur.
 *
 * @return list<array{route: \Illuminate\Routing\Route, name: string, method: string, uri: string, model: class-string<Model>}>
 */
function boundWebRoutes(): array
{
    $found = [];

    /** @var \Illuminate\Routing\RouteCollectionInterface $collection */
    $collection = Route::getRoutes();

    foreach ($collection->getRoutes() as $route) {
        $uri = $route->uri();

        if (str_starts_with($uri, 'api/') || str_starts_with($route->getName() ?? '', 'api.')) {
            continue;
        }

        if (count($route->parameterNames()) !== 1) {
            continue;
        }

        $model = webBoundModelClass($route);

        if ($model === null || ! str_starts_with($model, 'App\\Models\\')) {
            continue;
        }

        $verbs = [];

        foreach ($route->methods() as $verb) {
            // HEAD est enregistre avec chaque GET et ne sonderait rien que le
            // GET ne sonde deja.
            if (is_string($verb) && $verb !== 'HEAD') {
                $verbs[] = $verb;
            }
        }

        $found[] = [
            'route' => $route,
            'name' => $route->getName() ?? $uri,
            'method' => $verbs[0] ?? 'GET',
            'uri' => $uri,
            'model' => $model,
        ];
    }

    return $found;
}

/**
 * Une charge que la validation de cette route refusera.
 *
 * Les cles sont lues sur le FormRequest de la route plutot que devinees : une
 * cle absente des regles est ignoree par le validateur, donc une liste fixe ne
 * declencherait rien. La valeur est un tableau, ce qui echoue a la fois sur
 * `string`, `integer`, `numeric`, `boolean`, `date` et `email`.
 *
 * Sans elle, la sonde ne verrait pas la famille signalee par #1432 : un
 * FormRequest valide AVANT que le controleur autorise, si bien qu'une charge
 * malformee visant le bien d'autrui repond 302 avec un sac d'erreurs la ou un
 * identifiant inconnu repond 404.
 *
 * @return array<string, array<int, string>>
 */
function webHostilePayload(\Illuminate\Routing\Route $route): array
{
    foreach (webActionParameters($route) as $parameter) {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            continue;
        }

        /** @var class-string $candidate */
        $candidate = $type->getName();

        if (! is_subclass_of($candidate, \Illuminate\Foundation\Http\FormRequest::class)) {
            continue;
        }

        try {
            $request = new $candidate();

            // `rules()` est une convention, pas une methode de FormRequest.
            if (! method_exists($request, 'rules')) {
                return [];
            }

            $rules = $request->rules();
        } catch (\Throwable) {
            // Certaines regles lisent la route pour se construire, ce qu'une
            // instance nue ne peut pas fournir. La charge vide reste testee.
            return [];
        }

        if (! is_array($rules)) {
            return [];
        }

        $fields = array_values(array_filter(array_keys($rules), is_string(...)));

        return array_fill_keys($fields, ['invalide']);
    }

    return [];
}

/**
 * Une ressource qui appartient vraiment a quelqu'un d'autre.
 *
 * La fabrique seule ne suffit pas : `ExerciseFactory` cree un exercice de
 * bibliotheque, `user_id` a null, que tout le monde peut voir. Sonde ainsi,
 * `stats.exercise` repondait 200 et sortait du balayage — l'endpoint que #1432
 * cite nommement n'aurait pas ete teste. Le proprietaire est donc impose quand
 * le modele en porte un.
 *
 * @param  class-string<Model>  $model
 */
function foreignResource(string $model, User $owner): Model
{
    /** @var Model $resource */
    $resource = Factory::factoryForModel($model)->createOne();

    if (! $resource instanceof User && Schema::hasColumn($resource->getTable(), 'user_id')) {
        $resource->forceFill(['user_id' => $owner->id])->save();
    }

    return $resource;
}

/**
 * Les en-tetes dont la valeur ne depend pas de la requete.
 *
 * Trois familles bougent d'un appel a l'autre sans rien dire de la ressource,
 * et les garder ferait echouer le contrat sur du bruit : l'horodatage, les
 * cookies de session, le compteur de `throttle` — qui ne depend que du rang
 * d'appel — et le nonce du CSP, tire au hasard a chaque reponse. Le nonce est
 * gomme plutot que l'en-tete entier : c'est precisement dans le CSP que
 * l'oracle vivait, et jeter la ligne pour sa partie variable rendrait le
 * contrat aveugle a l'endroit ou il a deja ete pris en defaut.
 *
 * @param  \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
 * @return array<string, string>
 */
function stableHeaders(\Illuminate\Testing\TestResponse $response): array
{
    $headers = [];

    foreach ($response->headers->all() as $name => $values) {
        if (in_array($name, ['date', 'set-cookie'], true) || str_starts_with($name, 'x-ratelimit-')) {
            continue;
        }

        $headers[$name] = (string) preg_replace(
            "/'nonce-[^']+'/",
            "'nonce-*'",
            implode('; ', array_map(strval(...), $values)),
        );
    }

    ksort($headers);

    return $headers;
}

/**
 * Ce qu'une reponse laisse voir d'elle, du point de vue de l'appelant.
 *
 * Le statut ne suffit pas : deux 404 rediges differemment auraient seulement
 * deplace l'oracle du statut vers le corps. La destination d'une redirection
 * compte au meme titre, puisqu'un 302 vers la page d'edition de la ressource
 * nomme la ressource.
 *
 * Les en-tetes non plus ne sont pas facultatifs, et cette empreinte-ci les a
 * ignores le temps d'une revue : le correctif de #1432 egalisait statut, corps
 * et `Location`, le contrat passait au vert, et `curl -I` distinguait toujours
 * les deux reponses. `Content-Security-Policy` et `Vary: X-Inertia` etaient
 * poses par des intergiciels interieurs a la liaison de modele, donc absents
 * quand la liaison echouait et presents quand la policy refusait. Une empreinte
 * qui ne lit qu'une partie de la reponse ne mesure pas l'indiscernabilite ; elle
 * mesure la partie qu'elle a choisi de lire.
 *
 * @param  \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
 */
function responseFingerprint(\Illuminate\Testing\TestResponse $response): string
{
    $location = $response->headers->get('Location');
    $headers = stableHeaders($response);

    return sprintf(
        '%d %s%s [%s]',
        $response->getStatusCode(),
        substr(md5((string) $response->getContent()), 0, 12),
        $location === null ? '' : ' -> '.$location,
        implode(' ', array_map(
            static fn (string $name, string $value): string => $name.'='.substr(md5($value), 0, 6),
            array_keys($headers),
            $headers,
        )),
    );
}

/**
 * Toutes les routes sont parcourues dans un seul test : elles ne peuvent etre
 * enumerees qu'une fois l'application demarree, donc apres que Pest a resolu
 * ses jeux de donnees. Rassembler les fautives vaut aussi mieux que s'arreter a
 * la premiere, pour un contrat cense decrire toute une surface.
 */
it('answers the same on the web for a resource owned by someone else as for one that does not exist', function (): void {
    $routes = boundWebRoutes();

    // Garde-fou contre une enumeration qui ne trouverait rien : une liste vide
    // ferait passer ce test sans verifier une seule route.
    expect($routes)->not->toBeEmpty();

    $intruder = User::factory()->create();
    $owner = User::factory()->create();
    $discloses = [];
    $checked = 0;

    foreach ($routes as $route) {
        $foreign = foreignResource($route['model'], $owner);

        /** @var int|string $key */
        $key = $foreign->getKey();

        $path = fn (string $id): string => '/'.(preg_replace('/\{[^}]+\}/', $id, $route['uri']));

        /*
         * Trois facons d'appeler la meme route, parce que la reponse depend de
         * ce que l'appelant declare accepter :
         *
         * - le navigateur, qui recoit la page d'erreur ;
         * - Inertia, que #1432 cite parce que l'application n'installe aucun
         *   rendu d'erreur Inertia et que le client recoit donc du HTML brut ;
         * - axios, qui appelle `stats.exercise` et `exercises/{id}` — des
         *   routes web qui repondent en JSON.
         */
        $callers = [
            'navigateur' => ['Accept' => 'text/html,application/xhtml+xml'],
            'inertia' => ['Accept' => 'text/html,application/xhtml+xml', 'X-Inertia' => 'true', 'X-Inertia-Version' => '1'],
            'axios' => ['Accept' => 'application/json, text/plain, */*', 'X-Requested-With' => 'XMLHttpRequest'],
        ];

        $bodies = ['sans corps' => [], 'charge invalide' => webHostilePayload($route['route'])];

        foreach ($callers as $caller => $headers) {
            foreach ($bodies as $description => $body) {
                /*
                 * La session est vidée entre chaque appel : `redirect()->back()`
                 * lit l'URL precedente dans la session, si bien qu'une sonde qui
                 * enchaine les requetes compare des destinations heritees de
                 * l'appel d'avant plutot que celles de l'appel mesure.
                 */
                $this->flushSession();
                $this->actingAs($intruder);
                /** @var \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response> $present */
                $present = $this->withHeaders($headers)->call($route['method'], $path((string) $key), $body);

                $this->flushSession();
                $this->actingAs($intruder);
                // Un identifiant tres au-dela de ce que les fabriques produisent.
                /** @var \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response> $absent */
                $absent = $this->withHeaders($headers)->call($route['method'], $path('999999999'), $body);

                /*
                 * Une ressource que l'appelant peut simplement lire n'est pas
                 * cachee, donc rien d'elle ne fuit : se voir refuser une action
                 * sur elle parle de l'action, pas de l'existence. Le succes est
                 * observe de l'exterieur, sur la reponse, et non en interrogeant
                 * la policy que le correctif consulte.
                 */
                if ($present->getStatusCode() < 300) {
                    continue;
                }

                $checked++;

                if (responseFingerprint($present) !== responseFingerprint($absent)) {
                    $discloses[] = sprintf(
                        '%s (%s, %s, %s): %s pour un %s appartenant a autrui, %s pour un identifiant inconnu',
                        $route['name'],
                        $route['method'],
                        $caller,
                        $description,
                        responseFingerprint($present),
                        class_basename($route['model']),
                        responseFingerprint($absent),
                    );
                }
            }
        }
    }

    expect($checked)->toBeGreaterThan(0);

    expect($discloses)->toBe([], sprintf(
        "%d appels sur %d apprennent a un intrus qu'une ressource existe :\n- %s",
        count($discloses),
        $checked,
        implode("\n- ", $discloses),
    ));
});

/**
 * Et le refus ordinaire doit survivre.
 *
 * Sur le web, une redirection avec un sac d'erreurs est ce que rend un
 * formulaire refuse : c'est ainsi que l'utilisateur voit ce qu'il a mal saisi.
 * Un correctif qui repondrait 404 a toute validation echouee tiendrait le test
 * precedent en cassant l'application, et le tiendrait silencieusement.
 *
 * Ce test envoie donc la meme charge malformee sur la ressource de l'appelant
 * lui-meme, et exige que la reponse ne soit pas la reponse discrete.
 */
it('still shows an owner why their own submission was refused', function (): void {
    $owner = User::factory()->create();
    $swallowed = [];
    $checked = 0;

    foreach (boundWebRoutes() as $route) {
        $body = webHostilePayload($route['route']);

        if ($body === []) {
            continue;
        }

        $own = foreignResource($route['model'], $owner);

        if (! $own instanceof User && ! Schema::hasColumn($own->getTable(), 'user_id')) {
            // Sans colonne de proprietaire, la ressource n'est celle de
            // personne et ce test ne saurait pas dire ce qu'elle devrait rendre.
            continue;
        }

        /** @var int|string $key */
        $key = $own->getKey();

        $this->flushSession();
        $this->actingAs($owner);

        /** @var \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response> $response */
        $response = $this->withHeaders(['Accept' => 'text/html,application/xhtml+xml'])
            ->call($route['method'], '/'.(preg_replace('/\{[^}]+\}/', (string) $key, $route['uri'])), $body);

        $checked++;

        if ($response->getStatusCode() === 404) {
            $swallowed[] = sprintf('%s (%s) rend 404 au proprietaire de la ressource', $route['name'], $route['method']);
        }
    }

    expect($checked)->toBeGreaterThan(0);

    expect($swallowed)->toBe([], sprintf(
        "%d routes sur %d cachent sa propre ressource a son proprietaire :\n- %s",
        count($swallowed),
        $checked,
        implode("\n- ", $swallowed),
    ));
});

/**
 * Et le troisieme canal : le travail.
 *
 * Statut, corps, `Location` et en-tetes peuvent concorder pendant que les deux
 * chemins ne coutent pas la meme chose. C'est ce que #1433 a mesure sur l'API —
 * la ressource d'autrui payait la traversee des relations que l'identifiant
 * inconnu ne payait pas — et le chemin web n'avait jamais ete compte du tout.
 *
 * Le compte de requetes est un substitut de la duree, et un bien meilleur
 * temoin : il est deterministe, il ne demande aucune statistique, et il ne
 * depend pas de la machine qui l'execute. Une duree mesuree en processus de
 * test ne se transpose pas au reseau ; un ecart de requetes, lui, est la meme
 * dette partout.
 */
it('does the same work on the web whether the resource exists or not', function (): void {
    $routes = boundWebRoutes();

    expect($routes)->not->toBeEmpty();

    $intruder = User::factory()->create();
    $owner = User::factory()->create();
    $discloses = [];
    $checked = 0;

    /**
     * @param  array<string, mixed>  $body
     * @return array{status: int, queries: int}
     */
    $measure = function (string $method, string $path, array $body) use ($intruder): array {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->flushSession();
        $this->actingAs($intruder);
        $response = $this->withHeaders(['Accept' => 'text/html,application/xhtml+xml'])
            ->call($method, $path, $body);

        // Le rappel reste attache pour la duree du test ; on le neutralise en
        // remettant le compteur a zero plutot qu'en essayant de le detacher,
        // ce que `DB::listen` ne permet pas.
        $mesure = $queries;
        $queries = 0;

        return ['status' => $response->getStatusCode(), 'queries' => $mesure];
    };

    foreach ($routes as $route) {
        $foreign = foreignResource($route['model'], $owner);

        /** @var int|string $key */
        $key = $foreign->getKey();

        $path = fn (string $id): string => '/'.(preg_replace('/\{[^}]+\}/', $id, $route['uri']));
        $body = webHostilePayload($route['route']);

        /*
         * Une passe de chauffe sur chacun des deux chemins avant de mesurer :
         * le premier appel d'une route resout son controleur et compile ses
         * regles une fois pour toutes, et ce cout initial tomberait entierement
         * sur celui des deux qu'on mesure en premier — inventant un ecart la ou
         * il n'y en a pas, ou en masquant un.
         */
        $this->flushSession();
        $this->actingAs($intruder);
        $this->call($route['method'], $path('999999999'), $body);
        $this->flushSession();
        $this->actingAs($intruder);
        $this->call($route['method'], $path((string) $key), $body);

        $absent = $measure($route['method'], $path('999999999'), $body);
        $present = $measure($route['method'], $path((string) $key), $body);

        if ($present['status'] < 300) {
            continue;
        }

        $checked++;

        if ($present['queries'] !== $absent['queries']) {
            $discloses[] = sprintf(
                '%s (%s): %d requete(s) pour un %s appartenant a autrui, %d pour un identifiant inconnu',
                $route['name'],
                $route['method'],
                $present['queries'],
                class_basename($route['model']),
                $absent['queries'],
            );
        }
    }

    expect($checked)->toBeGreaterThan(0);

    expect($discloses)->toBe([], sprintf(
        "%d des %d routes web travaillent plus pour une ressource qui existe :\n- %s",
        count($discloses),
        $checked,
        implode("\n- ", $discloses),
    ));
});
