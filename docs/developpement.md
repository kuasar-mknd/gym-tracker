# Développement

## Qualité

Chaque seuil ci-dessous est **appliqué par la CI**, pas déclaratif. Ils sont posés au niveau mesuré et montent par cliquets — un seuil qu'on n'atteint pas finit désactivé.

| Contrôle | Seuil | Où |
| --- | --- | --- |
| **PHPStan** | `level: max` + strict-rules, deprecation-rules, détecteur de code mort | bloquant par PR |
| **Tests backend** | 5 963 tests, couverture ≥ **94 %**, sur MySQL 8.4 et 9.7.4, et 16 contre un vrai Redis à l'image de la production (groupe `redis`, étape à part) | bloquant par PR |
| **Tests frontend** | 4 292 tests, ≥ **95 %** statements / 92 branches / 92 functions / 95 lines | bloquant par PR |
| **Tests navigateur** | 192 parcours Dusk sous Chrome headless, sur MySQL 8.4 et 9.7.4 | bloquant par PR |
| **PHP Insights** | ≥ 90 en qualité, complexité, architecture et style | bloquant par PR |
| **Rector / Pint** | aucun changement en attente | bloquant par PR |
| **Mutation testing** | ≥ 82 % `App\Services\PersonalRecordService`, 94 % le reste d'`App\Services`, 83 % `App\Services\Stats`, 88 % le reste d'`App\Services\Diagnostic`, 91 % `App\Services\Diagnostic\Projections`, 95 % `App\Actions`, 99 % `App\Policies` | nocturne, **bloque la release** |

S'y ajoutent plus de quarante **gardes de convention** — des tests qui protègent une règle plutôt qu'un comportement : sous-ensemble de police d'icônes, frontières de propriété des policies, absence d'oracle de divulgation sur l'API, zoom des champs sur iOS, identifiants provisoires qui ne doivent jamais atteindre le serveur, variables d'environnement documentées.

Voir aussi les [décisions d'architecture](docs/adr/) et la [charte graphique](docs/charte.html). La feuille de route vit dans les issues GitHub et le journal des modifications, pas dans un document qui vieillit.

## Pile technique

| Catégorie | Technologies |
| --- | --- |
| **Backend** | Laravel 13, PHP 8.5 (8.5.12 au moins, que l'image et `composer.json` exigent, Strict Types), Laravel Octane sur FrankenPHP, MySQL 8.4 (la CI teste aussi 9.7.4, la LTS suivante), Redis 8.10.3 et Laravel Horizon |
| **Frontend** | Vue 3, Inertia.js 3, Tailwind CSS 4, Vite 8 |
| **Backoffice** | Filament 5 |
| **Testing** | Pest 5, PHPUnit 13, Laravel Dusk 8 |
| **DevOps** | Docker (image multi-architecture sur ghcr.io), Laravel Sail en développement, GitHub Actions |
| **Monitoring** | Le panneau (santé, exceptions, tâches planifiées, journaux, erreurs navigateur), Horizon, Laravel Pulse (coupé par défaut en production) ; Telescope en développement seulement |

## Charte graphique

Toutes les couleurs de l'application vivent dans **`resources/css/app.css`**, et nulle part ailleurs.
Un composant y nomme un **rôle** — un accent, un danger, une catégorie — jamais une couleur.

📄 **[docs/charte.html](docs/charte.html)** — les jetons, les surfaces appariées et leurs contrastes mesurés.
La page est générée : `php artisan charte:publier`.

Deux règles valent d'être connues avant de toucher au style :

- **ne choisissez pas la couleur du texte posé sur un fond.** Employez un utilitaire apparié
  (`accent-fill`, `state-fill`, `category-fill-*`…) : il pose les deux, et sa valeur est calculée.
  Un jeton de texte unique ne peut pas convenir — l'orange porte du blanc à 4,7:1 et de l'encre à
  3,8:1, le vert d'état exactement l'inverse ;
- **une nuance Tailwind brute est refusée par les tests.** `bg-slate-800`, `#ff5500`, `rgba(…)` :
  neuf gardes dans `tests/Feature/Conventions/` les interdisent et vérifient les contrastes à chaque
  exécution.

## Bases de test

`migrate --seed` crée un compte de démonstration, `test@example.com` / `password` ; en local, `/__dev-login` s'y connecte d'un clic. Le premier démarrage du MySQL de Sail crée aussi les bases des tests, `gym_tracker_testing` et `gym_tracker_dusk`. Sur un volume plus ancien, le script d'initialisation se rejoue sans toucher aux données de développement :

```bash
./vendor/bin/sail exec mysql bash /docker-entrypoint-initdb.d/20-create-parallel-testing-databases.sh
```

## Commandes courantes
| Commande | Description |
| --- | --- |
| `./vendor/bin/sail up -d` | Lance les conteneurs (App, MySQL, Redis, Mailpit, Selenium) |
| `./vendor/bin/sail composer dev` | Lance Vite avec rechargement à chaud, la file (`queue:listen`) et les journaux (`pail`) |
| `./vendor/bin/sail artisan test -p` | Suite backend en parallèle |
| `./vendor/bin/sail exec -e CACHE_STORE=redis -e SESSION_DRIVER=redis -e QUEUE_CONNECTION=redis -e REDIS_REEL_JETABLE=1 laravel.test php artisan test --group=redis` | Tests contre un vrai Redis (tests/Feature/Redis), que la suite écarte : ils vident le Redis de Sail, sessions et cache compris ; `REDIS_REEL_JETABLE=1` le déclare jetable, faute de quoi la garde refuse un serveur hors de la boucle locale |
| `./vendor/bin/sail npx vitest run` | Suite frontend |
| `./vendor/bin/sail artisan dusk` | Parcours navigateur, sur la base `gym_tracker_dusk` : demande `.env.dusk.local`, voir ci-dessous |
| `./vendor/bin/sail bin pint` | Formate le code |
| `./vendor/bin/sail php vendor/bin/phpstan analyse --memory-limit=2G` | Analyse statique, `level: max` |
| `./vendor/bin/sail php vendor/bin/rector process --dry-run` | Modernisation en attente |

## Crochet de commit

`git commit` formate les fichiers indexés : Prettier pour le front, Pint pour le PHP. Pint lit chaque fichier avec le PHP qui le lance, et un PHP plus ancien que celui du projet échoue en erreur de syntaxe sur son code. `scripts/formater-le-php.sh` lance donc Pint dans le conteneur de Sail quand il tourne pour ce dossier, sinon avec le PHP de l'hôte s'il atteint la contrainte `php` de `composer.json`. Sans l'un ni l'autre, le commit est refusé avec la marche à suivre : lancer Sail (`./vendor/bin/sail up -d`), ou désigner un PHP assez récent par `PINT_PHP` (`PINT_PHP=/chemin/vers/php git commit`). Compose nomme le projet d'après le nom du dossier : le conteneur d'une autre copie du dépôt qui porte le même nom est écarté, puisque Pint y formaterait l'autre copie. Le service cherché est celui de `APP_SERVICE` (défaut `laravel.test`), lu dans l'environnement seulement : Sail le lit aussi dans le `.env`, le script non.

## Parcours navigateur

`artisan dusk` lance les parcours avec le `.env`. Tel quel, sous Sail, ils viseraient la base de développement, et ceux qui vident leur base au premier test la videraient ; le navigateur, qui tourne dans le conteneur `selenium`, chercherait l'application sur `localhost`, c'est-à-dire chez lui. Une garde refuse les deux avant toute écriture : la base doit finir par `_dusk`, et `APP_URL` ne peut viser ni `localhost` ni `127.0.0.1` quand le navigateur tourne ailleurs. Une fois, dériver du `.env` la configuration des parcours :

```bash
sed -e 's#^APP_URL=.*#APP_URL=http://laravel.test#' \
    -e 's#^DB_HOST=.*#DB_HOST=mysql#' \
    -e 's#^DB_DATABASE=.*#DB_DATABASE=gym_tracker_dusk#' \
    -e 's#^DUSK_DRIVER_URL=.*#DUSK_DRIVER_URL=http://selenium:4444/wd/hub#' \
    .env > .env.dusk.local
```

Puis, à chaque passe :

```bash
./vendor/bin/sail npm run build   # Selenium ne joint pas le serveur de Vite
./vendor/bin/sail artisan dusk
```

`artisan dusk` met `.env.dusk.local` à la place du `.env` le temps de la passe, puis le remet ; il cherche `.env.dusk.` suivi de l'`APP_ENV` du `.env`, `local` sous Sail. Les parcours et le serveur de Sail, qui relit le `.env` à chaque requête, visent ainsi ensemble `gym_tracker_dusk` ; pendant la passe, `http://localhost` sert donc cette base. La CI tourne autrement : serveur et ChromeDriver sur le même exécuteur, `APP_URL=http://127.0.0.1:8000`, dans le `.env` qu'écrit le job `browser-shard`. Dans les deux cas, la suite ne démarre aucun pilote : elle se branche sur `DUSK_DRIVER_URL`, le Selenium de Sail ou le ChromeDriver que la CI lance dans une étape à part ; ailleurs, ChromeDriver est à lancer soi-même. Avant les parcours, la CI ouvre puis ferme une session sur ce pilote et en écrit les durées dans le journal du job : le premier démarrage de Chrome, lent sur un exécuteur neuf, se paie ainsi hors des parcours.

Servis en http sur `laravel.test`, hors de la boucle locale, les parcours voient un historique en clair et aucun service worker : `HistoriqueApresDeconnexionTest` et `HistoriqueDeDeuxOngletsTest`, qui vérifient le chiffrement de l'historique, `OuvertureHorsLigneTest`, qui ouvre l'application réseau coupé, `CopiesHorsLigneTest`, qui lit les copies chiffrées des pages de salle, `NavigationHorsLigneTest`, qui les ouvre réseau coupé depuis la séance et à froid, les parcours de l'ouverture sans réseau (`SeanceRouverteHorsLigneTest`, `CopiesDUnAutreCompteTest`, `FinDeSessionCoteServeurTest`, `DeconnexionHorsLigneTest`, `ReseauLentTest`), et ceux qui démarrent, terminent ou lancent un modèle réseau coupé (`SeanceDemarreeSansReseauTest`, `ModeleLanceSansReseauTest`) s'y déclarent sautés, et ne tournent qu'en CI, sur 127.0.0.1, ou en local sur la même boucle. Sous les parcours aussi, l'application demande d'elle-même les pages de salle : ces requêtes en arrière-plan ne touchent pas à la session, et ne croisent donc pas la connexion du parcours suivant. Entre deux parcours d'une même classe, le navigateur gardé quitte l'application, sa file d'écritures et ses brouillons de séries vidés : une écriture dont la réponse n'est pas revenue ne repart pas sous le compte du parcours suivant, qui porte le même identifiant quand la classe vide sa base.

## Mutation testing

Le seuil n'est appliqué que par `vendor/bin/pest` : `artisan test --mutate --min` accepte l'option et l'ignore — c'est le `--min` de la **couverture**, silencieusement absorbé.

```bash
./vendor/bin/sail php vendor/bin/pest --mutate --parallel --covered-only \
  --class='App\Policies' --min=99
```

`--parallel` suppose que les bases par processus existent ; `artisan test -p` les crée au passage, un `artisan test -p` préalable suffit donc.

**En local, `--parallel` sert à itérer, pas à conclure.** Pest accorde à chaque mutant la durée de la passe de référence plus 20 % (au moins 5 s), et compte un dépassement comme un mutant tué. Cette référence est mesurée hors contention : une machine de dev qui lance dix processus la dépasse d'elle-même dès que l'ensemble couvrant est gros — le cas de tout service branché sur un observateur, couvert par des centaines de tests.

Mesuré sur `App\Services\StreakService`, même code, mêmes mutations :

| mode | verdicts | score |
| --- | --- | --- |
| `--parallel` (10 processus) | 19 timeout, 21 tués, **0 survivant** | 100,00 % |
| séquentiel | 0 timeout, 37 tués, **3 survivants** | 92,50 % |

L'erreur ne va que dans un sens : le parallèle **cache** des survivants, il n'en invente pas. Il reste donc bon pour trouver du travail — mais « cette classe est propre » demande une mesure séquentielle :

```bash
./vendor/bin/sail php vendor/bin/pest --mutate --covered-only --class='App\Services\StreakService'
```

Le nocturne n'est pas concerné : le runner ne lance qu'un processus par cœur (« Parallel: 4 processes » dans chaque rapport), sans autre charge, et sa passe de référence se mesure dans les mêmes conditions que ses mutants. Mesure sur trois nuits consécutives — 1 timeout sur les 841 mutations de `App\Services`, 4 sur les 887 de `App\Actions`, 0 sur `App\Policies`.

Sa **durée**, elle, suit le runner : un runner lent exécute chaque test plus lentement, et chaque mutant, qui rejoue les tests couvrant sa ligne, le paie. Le 07/10, la passe de référence de la part `App\Services` a pris 126 s, contre 96 et 82 pour les deux autres parts du même run, et la part, qui tenait en 34 minutes la veille, a été annulée à son délai de 60. Elle a été coupée en deux, `PersonalRecordService` et le reste. Du 16 au 24/10, la passe a de nouveau été annulée chaque nuit : le diagnostic avait presque doublé la part, et la suite était passée de 3 489 à 4 998 tests. À code égal, `App\Policies` a pris 26 minutes sur un runner et 39 sur un autre. `App\Services` compte désormais cinq parts, pour sept en tout ; la limite de chacune est le double de la plus longue de ses mesures, et chaque rapport nomme ses dix mutants les plus lents (`--profile`). Les mesures sont en tête du job dans `.github/workflows/mutation.yml`.

## Contribution

Les contributions sont les bienvenues !
1. Assure-toi que les tests passent : `./vendor/bin/sail artisan test`
2. Vérifie la qualité aux seuils de la CI : `./vendor/bin/sail php vendor/bin/phpinsights analyse --no-interaction --min-quality=90 --min-complexity=90 --min-architecture=90 --min-style=90`
3. Formate ton code : `./vendor/bin/sail bin pint`
4. Voir le [Guide de Contribution](CONTRIBUTING.md) pour plus de détails.
