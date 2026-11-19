# Développement

Cette page dit comment développer et vérifier le code : la pile technique, les commandes, les seuils de qualité, les bases de test, le crochet de commit, les parcours navigateur et la charte graphique. L'installation est dans le [README](../README.md#installation).

| Je veux… | Section |
| --- | --- |
| Savoir avec quoi l'application est écrite | [Pile technique](#pile-technique) |
| Lancer les tests et les outils | [Commandes courantes](#commandes-courantes) |
| Connaître les seuils que la CI applique | [Qualité](#qualité) |
| Comprendre les bases de test | [Bases de test](#bases-de-test) |
| Comprendre le refus d'un commit | [Crochet de commit](#crochet-de-commit) |
| Lancer les parcours navigateur (Dusk) | [Parcours navigateur](#parcours-navigateur) |
| Mesurer le score de mutation | [Tests de mutation](tests-de-mutation.md) |
| Changer une couleur ou un style | [Charte graphique](#charte-graphique) |
| Proposer une contribution | [Contribuer](#contribuer) |

## Pile technique

| Catégorie | Technologies |
| --- | --- |
| **Backend** | Laravel 13, PHP 8.5 (8.5.12 au moins, que l'image et `composer.json` exigent, Strict Types), Laravel Octane sur FrankenPHP, MySQL 8.4 (la CI teste aussi 9.7.4, la LTS suivante), Redis 8.10.3 et Laravel Horizon |
| **Frontend** | Vue 3, Inertia.js 3, Tailwind CSS 4, Vite 8 |
| **Backoffice** | Filament 5 |
| **Testing** | Pest 5, PHPUnit 13, Laravel Dusk 8 |
| **DevOps** | Docker (image multi-architecture sur ghcr.io), Laravel Sail en développement, GitHub Actions |
| **Monitoring** | Le panneau (santé, exceptions, tâches planifiées, journaux, erreurs navigateur), Horizon, Laravel Pulse (coupé par défaut en production) ; Telescope en développement seulement |

## Commandes courantes

| Commande | Description |
| --- | --- |
| `./vendor/bin/sail up -d` | Lance les conteneurs (App, MySQL, Redis, Mailpit, Selenium) |
| `./vendor/bin/sail composer dev` | Lance Vite avec rechargement à chaud, la file (`queue:listen`) et les journaux (`pail`) |
| `./vendor/bin/sail artisan test -p` | Suite backend en parallèle |
| `./vendor/bin/sail exec -e CACHE_STORE=redis -e SESSION_DRIVER=redis -e QUEUE_CONNECTION=redis -e REDIS_REEL_JETABLE=1 laravel.test php artisan test --group=redis` | Tests contre un vrai Redis (`tests/Feature/Redis`), que la suite écarte. Voir ci-dessous. |
| `./vendor/bin/sail npx vitest run` | Suite frontend |
| `./vendor/bin/sail artisan dusk` | Parcours navigateur, sur la base `gym_tracker_dusk`. Demande `.env.dusk.local` : voir [Parcours navigateur](#parcours-navigateur). |
| `./vendor/bin/sail bin pint` | Formate le code |
| `./vendor/bin/sail php vendor/bin/phpstan analyse --memory-limit=2G` | Analyse statique, `level: max` |
| `./vendor/bin/sail php vendor/bin/rector process --dry-run` | Modernisation en attente |
| `./vendor/bin/sail php vendor/bin/phpinsights analyse --no-interaction --min-quality=90 --min-complexity=90 --min-architecture=90 --min-style=90` | Qualité aux seuils de la CI |

**Les tests contre un vrai Redis** vident le Redis de Sail, sessions et cache compris. `REDIS_REEL_JETABLE=1` le déclare jetable : faute de quoi la garde refuse un serveur hors de la boucle locale.

## Qualité

Chaque seuil ci-dessous est **appliqué par la CI**, pas déclaratif. Les seuils sont posés au niveau mesuré et montent par cliquets : un seuil qu'on n'atteint pas finit désactivé.

| Contrôle | Seuil | Où |
| --- | --- | --- |
| **PHPStan** | `level: max` + strict-rules, deprecation-rules, détecteur de code mort | bloquant par PR |
| **Tests backend** | 5 963 tests, couverture ≥ **94 %**, sur MySQL 8.4 et 9.7.4, et 16 contre un vrai Redis à l'image de la production (groupe `redis`, étape à part) | bloquant par PR |
| **Tests frontend** | 4 292 tests, ≥ **95 %** statements / 92 branches / 92 functions / 95 lines | bloquant par PR |
| **Tests navigateur** | 192 parcours Dusk sous Chrome headless, sur MySQL 8.4 et 9.7.4 | bloquant par PR |
| **PHP Insights** | ≥ 90 en qualité, complexité, architecture et style | bloquant par PR |
| **Rector / Pint** | aucun changement en attente | bloquant par PR |
| **Mutation testing** | ≥ 82 % `App\Services\PersonalRecordService`, 94 % le reste d'`App\Services`, 83 % `App\Services\Stats`, 88 % le reste d'`App\Services\Diagnostic`, 91 % `App\Services\Diagnostic\Projections`, 95 % `App\Actions`, 99 % `App\Policies` | nocturne, **bloque la release** |

S'y ajoutent plus de quarante **gardes de convention** : des tests qui protègent une règle plutôt qu'un comportement. Exemples :

- sous-ensemble de police d'icônes ;
- frontières de propriété des policies ;
- absence d'oracle de divulgation sur l'API ;
- zoom des champs sur iOS ;
- identifiants provisoires qui ne doivent jamais atteindre le serveur ;
- variables d'environnement documentées.

Voir aussi les [décisions d'architecture](adr/) et la [charte graphique](charte.html). La feuille de route vit dans les issues GitHub et le journal des modifications, pas dans un document qui vieillit.

## Bases de test

Le premier démarrage du MySQL de Sail crée aussi les bases des tests : `gym_tracker_testing` et `gym_tracker_dusk`.

Sur un volume plus ancien, rejouer le script d'initialisation, sans toucher aux données de développement :

```bash
./vendor/bin/sail exec mysql bash /docker-entrypoint-initdb.d/20-create-parallel-testing-databases.sh
```

## Crochet de commit

`git commit` formate les fichiers indexés : Prettier pour le front, Pint pour le PHP.

Pint lit chaque fichier avec le PHP qui le lance, et un PHP plus ancien que celui du projet échoue en erreur de syntaxe sur son code. `scripts/formater-le-php.sh` lance donc Pint, dans cet ordre de préférence :

1. dans le conteneur de Sail, quand il tourne pour ce dossier ;
2. sinon avec le PHP de l'hôte, s'il atteint la contrainte `php` de `composer.json` ;
3. sinon le commit est refusé, avec la marche à suivre.

Si le commit est refusé :

- lancer Sail (`./vendor/bin/sail up -d`) ;
- ou désigner un PHP assez récent par `PINT_PHP` :

  ```bash
  PINT_PHP=/chemin/vers/php git commit
  ```

Deux précisions :

- Compose nomme le projet d'après le nom du dossier. Le conteneur d'une autre copie du dépôt qui porte le même nom est écarté, puisque Pint y formaterait l'autre copie.
- Le service cherché est celui de `APP_SERVICE` (défaut `laravel.test`), lu dans l'environnement seulement : Sail le lit aussi dans le `.env`, le script non.

## Parcours navigateur

Les parcours (Dusk) tournent dans un navigateur sur une base à eux, `gym_tracker_dusk`.

### Pourquoi il faut les préparer

`artisan dusk` lance les parcours avec le `.env`. Tel quel, sous Sail :

- ils viseraient la base de développement, et ceux qui vident leur base au premier test la videraient ;
- le navigateur, qui tourne dans le conteneur `selenium`, chercherait l'application sur `localhost`, c'est-à-dire chez lui.

Une garde refuse les deux avant toute écriture : la base doit finir par `_dusk`, et `APP_URL` ne peut viser ni `localhost` ni `127.0.0.1` quand le navigateur tourne ailleurs.

### Préparer (une seule fois)

Dériver du `.env` la configuration des parcours :

```bash
sed -e 's#^APP_URL=.*#APP_URL=http://laravel.test#' \
    -e 's#^DB_HOST=.*#DB_HOST=mysql#' \
    -e 's#^DB_DATABASE=.*#DB_DATABASE=gym_tracker_dusk#' \
    -e 's#^DUSK_DRIVER_URL=.*#DUSK_DRIVER_URL=http://selenium:4444/wd/hub#' \
    .env > .env.dusk.local
```

### Lancer (à chaque passe)

```bash
./vendor/bin/sail npm run build   # Selenium ne joint pas le serveur de Vite
./vendor/bin/sail artisan dusk
```

### Ce que fait `artisan dusk`

- Il met `.env.dusk.local` à la place du `.env` le temps de la passe, puis le remet.
- Il cherche `.env.dusk.` suivi de l'`APP_ENV` du `.env` : `local` sous Sail.
- Les parcours et le serveur de Sail, qui relit le `.env` à chaque requête, visent ainsi ensemble `gym_tracker_dusk`. Pendant la passe, `http://localhost` sert donc cette base.

### Le pilote du navigateur

La suite ne démarre aucun pilote : elle se branche sur `DUSK_DRIVER_URL`.

| Où | Pilote |
| --- | --- |
| Sail | le Selenium de Sail |
| CI | le ChromeDriver que la CI lance dans une étape à part |
| Ailleurs | un ChromeDriver à lancer soi-même |

### La CI

La CI tourne autrement : serveur et ChromeDriver sur le même exécuteur, `APP_URL=http://127.0.0.1:8000`, dans le `.env` qu'écrit le job `browser-shard`.

Avant les parcours, la CI ouvre puis ferme une session sur le pilote et en écrit les durées dans le journal du job : le premier démarrage de Chrome, lent sur un exécuteur neuf, se paie ainsi hors des parcours.

### Parcours sautés sous Sail

Servis en http sur `laravel.test`, hors de la boucle locale, les parcours voient un historique en clair et aucun service worker. Ceux-ci s'y déclarent sautés, et ne tournent qu'en CI, sur 127.0.0.1, ou en local sur la même boucle :

| Sujet | Parcours |
| --- | --- |
| Chiffrement de l'historique | `HistoriqueApresDeconnexionTest`, `HistoriqueDeDeuxOngletsTest` |
| Ouverture réseau coupé | `OuvertureHorsLigneTest` |
| Copies chiffrées des pages de salle | `CopiesHorsLigneTest` (lecture), `NavigationHorsLigneTest` (ouverture réseau coupé depuis la séance et à froid) |
| Ouverture sans réseau | `SeanceRouverteHorsLigneTest`, `CopiesDUnAutreCompteTest`, `FinDeSessionCoteServeurTest`, `DeconnexionHorsLigneTest`, `ReseauLentTest` |
| Démarrer, terminer ou lancer un modèle réseau coupé | `SeanceDemarreeSansReseauTest`, `ModeleLanceSansReseauTest` |

### Ce qui ne perturbe pas un parcours

- Sous les parcours aussi, l'application demande d'elle-même les pages de salle. Ces requêtes en arrière-plan ne touchent pas à la session, et ne croisent donc pas la connexion du parcours suivant.
- Entre deux parcours d'une même classe, le navigateur gardé quitte l'application, sa file d'écritures et ses brouillons de séries vidés. Une écriture dont la réponse n'est pas revenue ne repart donc pas sous le compte du parcours suivant, qui porte le même identifiant quand la classe vide sa base.

## Charte graphique

Toutes les couleurs de l'application vivent dans **`resources/css/app.css`**, et nulle part ailleurs. Un composant y nomme un **rôle** (un accent, un danger, une catégorie), jamais une couleur.

La page [charte.html](charte.html) montre les jetons, les surfaces appariées et leurs contrastes mesurés. Elle est générée : `php artisan charte:publier`.

Deux règles à connaître avant de toucher au style :

1. **Ne choisissez pas la couleur du texte posé sur un fond.** Employez un utilitaire apparié (`accent-fill`, `state-fill`, `category-fill-*`…) : il pose les deux, et sa valeur est calculée. Un jeton de texte unique ne peut pas convenir : l'orange porte du blanc à 4,7:1 et de l'encre à 3,8:1, le vert d'état exactement l'inverse.
2. **Une nuance Tailwind brute est refusée par les tests.** `bg-slate-800`, `#ff5500`, `rgba(…)` : neuf gardes dans `tests/Feature/Conventions/` les interdisent et vérifient les contrastes à chaque exécution.

## Contribuer

Les contributions sont les bienvenues. Avant d'ouvrir une PR :

1. S'assurer que les tests passent : `./vendor/bin/sail artisan test`.
2. Vérifier la qualité aux seuils de la CI : `./vendor/bin/sail php vendor/bin/phpinsights analyse --no-interaction --min-quality=90 --min-complexity=90 --min-architecture=90 --min-style=90`.
3. Formater le code : `./vendor/bin/sail bin pint`.

Le [guide du contributeur](../CONTRIBUTING.md) donne le détail.
