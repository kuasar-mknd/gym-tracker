<p align="center">
  <img src="https://img.shields.io/badge/Laravel-13-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel 13" />
  <img src="https://img.shields.io/badge/Vue.js-3-4FC08D?style=for-the-badge&logo=vue.js&logoColor=white" alt="Vue 3" />
  <img src="https://img.shields.io/badge/Tailwind-CSS-4-06B6D4?style=for-the-badge&logo=tailwindcss&logoColor=white" alt="Tailwind CSS 4" />
  <img src="https://img.shields.io/badge/Inertia.js-3-9553E9?style=for-the-badge&logo=inertia&logoColor=white" alt="Inertia.js" />
  <img src="https://img.shields.io/badge/PHPStan-level%20max-blue?style=for-the-badge" alt="PHPStan level max" />
</p>

<h1 align="center">💪 GymTracker</h1>

<p align="center">
  <strong>Une application de suivi de musculation moderne, élégante et performante.</strong>
  <br />
  <em>Suis tes entraînements, mesure tes progrès, atteins tes objectifs.</em>
</p>

<p align="center">
  <a href="#-fonctionnalités">Fonctionnalités</a> •
  <a href="#-screenshots">Screenshots</a> •
  <a href="#-installation">Installation</a> •
  <a href="#-qualité--performance">Qualité</a> •
  <a href="#-développement">Développement</a> •
  <a href="#-contribution">Contribution</a>
</p>

---

## ✨ Fonctionnalités

### 🏋️ Suivi d'Entraînement
- **Séances & Modèles** — Démarre rapidement avec tes routines favorites ou crée des séances libres.
- **Records personnels (PR)** — Détection automatique de tes nouveaux records (Poids, 1RM, Volume).
- **Streak counter** — Maintiens ta motivation avec le suivi des jours consécutifs.
- **Liquid Glass UI** — Une interface mobile-first pensée pour l'entraînement.

### 📊 Statistiques & Santé
- **Graphiques de progression** — Visualisation interactive de ton volume et de tes max.
- **Habits Tracking** — Suivi de tes routines (Créatine, Méditation, Sommeil...).
- **Vitals & Composition** — Enregistre ta tension, fréquence cardiaque et % de masse grasse (US Navy).
- **Mesures corporelles** — Suivi complet de ton évolution physique.

### 🔐 Sécurité & Outils
- **OAuth Social** — Connexion via Google, GitHub, Apple.
- **Calculateurs** — Plaques de fonte et estimation 1RM.
- **Sécurité renforcée** — Throttling API, CSP strict et Nonce-based protection.

---

## 🏆 Qualité

Chaque seuil ci-dessous est **appliqué par la CI**, pas déclaratif. Ils sont posés au niveau mesuré et montent par cliquets — un seuil qu'on n'atteint pas finit désactivé.

| Contrôle | Seuil | Où |
| --- | --- | --- |
| **PHPStan** | `level: max` + strict-rules, deprecation-rules, détecteur de code mort | bloquant par PR |
| **Tests backend** | 1 748 tests, couverture ≥ **94 %** | bloquant par PR |
| **Tests frontend** | 1 997 tests, ≥ **95 %** statements / 92 branches / 92 functions / 95 lines | bloquant par PR |
| **Tests navigateur** | 116 parcours Dusk sous Chrome headless | bloquant par PR |
| **PHP Insights** | ≥ 90 en qualité, complexité, architecture et style | bloquant par PR |
| **Rector / Pint** | aucun changement en attente | bloquant par PR |
| **Mutation testing** | ≥ 80 % `App\Services`, 95 % `App\Actions`, 99 % `App\Policies` | nocturne, **bloque la release** |

S'y ajoutent une vingtaine de **gardes de convention** — des tests qui protègent une règle plutôt qu'un comportement : sous-ensemble de police d'icônes, frontières de propriété des policies, absence d'oracle de divulgation sur l'API, zoom des champs sur iOS, identifiants provisoires qui ne doivent jamais atteindre le serveur.

Voir aussi les [décisions d'architecture](docs/adr/) et la [charte graphique](docs/charte.html). La feuille de route vit dans les issues GitHub et le journal des modifications, pas dans un document qui vieillit.

---

## 📦 Mise en production

La production suit l'image `ghcr.io/kuasar-mknd/gym-tracker:v1`, publiée quand un tag `v*` est poussé.

**Ce tag ne publie rien tant que tout n'est pas vert.** La publication exige, sur le commit exact du tag :

1. une **CI verte** — les 16 contrôles ;
2. une **passe nocturne verte** — chacune de ses parts, seuils de mutation compris.

La nuit tourne sur la pointe de `main` à 03h17 UTC : un tag posé après elle n'est pas encore couvert. Pour le débloquer :

```bash
gh workflow run mutation.yml --ref v1.2.3
```

Un échec sur `main` — CI ou passe nocturne — **ouvre automatiquement une issue**, dédupliquée par workflow.

`docker-compose.prod.yml` déclare cinq services : `app`, `db`, `redis`, `worker` (Horizon) et **`scheduler`** — ce dernier exécute les tâches planifiées. Sans lui, elles ne tournent pas, et rien ne le signale : une tâche qui ne s'exécute pas ne lève aucune erreur.

Le service `db` tourne avec `--innodb-flush-log-at-trx-commit=2` et `--skip-log-bin` : sur le disque dur du NAS, chaque écriture coûtait 250 à 500 ms de synchronisation ; le journal est désormais synchronisé une fois par seconde, et une coupure brutale (pas un redémarrage propre) peut perdre jusqu'à une seconde d'écritures validées.

L'application s'ouvre **par le proxy inverse HTTPS du DSM**, jamais directement sur le port 8888 publié par `app` : en production, le cookie de session est réservé à HTTPS, et une visite en http ne garde aucune session, et la connexion échoue. Le proxy doit transmettre `X-Forwarded-Proto` ; Laravel fait confiance aux adresses privées (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`).

---

## 🔧 Variables d'environnement

Une variable se déclare dans `.env.example` pour le développement, et se documente ici. `LeReadmeDocumenteLesVariablesTest` refuse une variable de `docker-compose.prod.yml`, de `.env.example` ou un secret de CI que cette section ne nomme pas, et une variable du tableau « Production » que la composition ne transmet pas.

### Production : à poser dans la pile

Elles se posent dans l'environnement de la pile — les variables de Portainer, ou un fichier `.env` à côté de `docker-compose.prod.yml`. **Seules celles que `docker-compose.prod.yml` transmet atteignent les conteneurs** : une variable lue par `config/` mais absente de ce fichier garde sa valeur par défaut, quoi qu'on pose dans la pile.

Une variable oubliée arrive **vide**, pas absente, sauf quand le tableau donne un défaut : Laravel retient alors la chaîne vide, pas le défaut de sa configuration. Seules `BACKUP_ARCHIVE_PASSWORD` et `BACKUP_HOST_PATH` empêchent la pile de démarrer quand elles manquent ; les autres obligatoires laissent la pile démarrer et l'application en panne.

| Variable | Obligatoire | Défaut | Rôle |
| --- | --- | --- | --- |
| `APP_KEY` | oui | — | Clé de chiffrement des sessions, des cookies et des données chiffrées, au format `base64:…` : `echo "base64:$(openssl rand -base64 32)"` en produit une. En changer déconnecte tout le monde et rend illisible ce qui a été chiffré avec l'ancienne. |
| `APP_URL` | oui | — | Adresse publique, en `https://` : celle du proxy du DSM. Elle sert aux liens des courriels, aux URL des actifs (`ASSET_URL` en est la copie) et d'identité Web Push quand `VAPID_SUBJECT` est vide. |
| `APP_DEBUG` | non | `false` | Pages d'erreur détaillées. Jamais en production : elles affichent la configuration, mots de passe compris. |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | oui | — | Base MySQL de l'application. Au premier démarrage d'un volume vide, le service `db` crée la base et l'utilisateur avec ces valeurs ; `DB_USERNAME` ne peut donc pas valoir `root`, que l'image mysql refuse. Les changer ensuite ne modifie pas l'utilisateur déjà créé. |
| `DB_ROOT_PASSWORD` | au premier démarrage | — | Mot de passe root de MySQL, lu seulement à l'initialisation d'un volume vide. |
| `REDIS_PASSWORD` | oui | — | Mot de passe de Redis, qui porte les sessions, le cache et les files ; le service `redis` démarre avec. |
| `MAIL_HOST`, `MAIL_PORT` | oui | — | Serveur SMTP de tous les courriels : vérification d'adresse, mot de passe oublié, alertes de santé. |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | si le relais l'exige | — | Authentification SMTP. |
| `MAIL_SCHEME` | non | déduit du port | `smtps` (TLS implicite) ou `smtp` (STARTTLS quand le serveur le propose). Vide : `smtps` sur le port 465, `smtp` ailleurs. Remplace `MAIL_ENCRYPTION`, que Laravel ne lit plus. |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | oui | — | Expéditeur des courriels. Oubliées, elles arrivent vides. |
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` | pour les notifications | — | Web Push. Sans elles, la page Profil affiche « Le service de notifications n'est pas encore configuré sur le serveur. » et rien ne part. Générées une fois par `npx web-push generate-vapid-keys` ; en changer invalide les abonnements existants. |
| `VAPID_SUBJECT` | non | `APP_URL` | Identité de l'expéditeur auprès du service push : une adresse `mailto:` ou une URL. |
| `BACKUP_ARCHIVE_PASSWORD` | oui, la pile refuse de démarrer | — | Mot de passe AES-256 des archives de sauvegarde, transmis à `app`, `worker` et `scheduler`. Sans lui, aucune archive n'est écrite, que la sauvegarde vienne du planificateur, de Filament ou de `backup:run` : une archive en clair sur une autre machine serait une fuite. |
| `BACKUP_HOST_PATH` | oui, la pile refuse de démarrer | — | Dossier de l'hôte monté dans `app`, `worker` et `scheduler` pour les archives : un partage d'une autre machine monté par le DSM, jamais un volume Docker. |
| `ADMIN_ALLOWED_IPS` | pour ouvrir le panneau | vide = fermé | Adresses IP autorisées sur le panneau `/backoffice`, séparées par des virgules : adresses exactes ou plages CIDR, IPv4 et IPv6 (`192.168.1.0/24,100.76.239.32`). |
| `HORIZON_ALLOWED_EMAILS` | pour ouvrir Horizon | vide = fermé | Adresses des comptes autorisés sur `/horizon`, séparées par des virgules. |
| `ADMIN_INITIAL_PASSWORD` | pour créer le premier administrateur | — | Mot de passe du compte `admin@gymtracker.app`, créé par `php artisan db:seed --class=AdminSeeder --force` lancé dans le conteneur `app` (console de Portainer ou `docker exec`). Le seeder échoue sans lui et ne réécrit jamais un mot de passe existant : à retirer de la pile une fois le compte créé. |
| `HEALTH_TO_ADDRESS` | non | vide = aucun courriel | Adresse qui reçoit un courriel quand un contrôle de santé passe au rouge (base, Redis, file, planificateur, tâche échouée, sauvegarde manquante, disque plein), une fois par heure au plus. Vide, la page « Santé » du panneau suffit. |

### Fixées par la composition

Écrites en dur dans `docker-compose.prod.yml` : les poser dans la pile ne change rien.

| Variable | Valeur | Pourquoi |
| --- | --- | --- |
| `APP_ENV` | `production` | Active ce qui ne vaut qu'en production : cookie de session réservé à HTTPS, panneau fermé sans `ADMIN_ALLOWED_IPS`, Horizon et Telescope derrière leur porte, mots de passe à casse mixte et absents des fuites connues. |
| `ASSET_URL` | la valeur d'`APP_URL` | Les actifs se servent depuis l'adresse publique. |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT` | `mysql`, `db`, `3306` | Le service `db` de la pile. |
| `REDIS_HOST`, `REDIS_PORT` | `redis`, `6379` | Le service `redis` de la pile. |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | `redis` | Sessions, cache et files partagés par les trois conteneurs de l'application. |
| `MAIL_MAILER` | `smtp` | Les courriels partent par le serveur de `MAIL_HOST`. |
| `GOMAXPROCS` | `2` | Plafonne les threads Go de FrankenPHP dans `app` ; `worker` et `scheduler`, en PHP CLI, l'ignorent. |
| `OCTANE_SERVER` | `frankenphp`, dans `app` seulement | Le serveur d'Octane. |
| `LOG_CHANNEL` | `stderr`, dans `app` seulement | Les journaux de `app` vont dans `docker logs`. Ceux de `worker` et `scheduler` vont dans storage/logs/laravel.log de leur propre conteneur, qui n'est ni monté ni affiché (#1907). |

### Lues par l'application, non transmises en production

`config/` les lit, mais la composition ne les transmet pas : la production applique leur défaut, quoi qu'on pose dans la pile.

| Variable | Défaut appliqué | Effet |
| --- | --- | --- |
| `SESSION_SECURE_COOKIE` | `true` en production | Le cookie de session n'est envoyé qu'en HTTPS : d'où le passage obligé par le proxy du DSM. |
| `SESSION_LIFETIME` | `120` | Minutes d'inactivité avant que la session expire ; « Se souvenir de moi » reconnecte ensuite sans mot de passe. |
| `APP_TIMEZONE` | `Europe/Paris` | Fuseau de l'application et des heures du planificateur (sauvegarde à 02 h 30). |
| `LOG_LEVEL` | `debug` | Tout est journalisé (#1907). |
| `GOOGLE_CLIENT_ID` et les autres variables de connexion sociale | vides | Les boutons Google, GitHub et Apple restent masqués : la connexion sociale ne s'active pas en production (#1908). |

### Développement local

Sous Sail, `cp .env.example .env` suffit : le gabarit vise les services de `compose.yaml` (`mysql`, `redis`, `mailpit`, `selenium`).

| Variable | Valeur du gabarit | Rôle |
| --- | --- | --- |
| `APP_NAME` | `GymTracker` | Nom de l'application. `VITE_APP_NAME` le recopie pour les titres de page ; Vite fige cette valeur dans les fichiers au build, et l'image de production, construite sans `.env`, retombe sur GymTracker. |
| `APP_ENV`, `APP_DEBUG` | `local`, `true` | Environnement local : pages d'erreur détaillées, panneau, Horizon et Telescope ouverts, connexion de développement. |
| `APP_KEY`, `APP_URL` | vide, `http://localhost` | `sail artisan key:generate` remplit la clé. |
| `APP_LOCALE`, `APP_FALLBACK_LOCALE`, `APP_FAKER_LOCALE` | `fr`, `en`, `fr_FR` | Langue de l'interface, langue de repli, langue des données factices. |
| `APP_MAINTENANCE_DRIVER`, `APP_MAINTENANCE_STORE` | `file`, commentée | Où se mémorise le mode maintenance. |
| `PHP_CLI_SERVER_WORKERS` | commentée | Processus de `artisan serve`. |
| `BCRYPT_ROUNDS` | `12` | Coût du hachage des mots de passe ; phpunit.xml le baisse à 4. |
| `LOG_CHANNEL`, `LOG_STACK`, `LOG_LEVEL`, `LOG_DEPRECATIONS_CHANNEL` | `stack`, `single`, `debug`, `null` | Journaux dans storage/logs/laravel.log, dépréciations ignorées. |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | `mysql`, `mysql`, `3306`, `gym_tracker`, `sail`, `password` | Le MySQL de Sail. Les tests gardent l'hôte et visent la base `gym_tracker_testing`. |
| `SESSION_DRIVER`, `SESSION_LIFETIME`, `SESSION_ENCRYPT`, `SESSION_PATH`, `SESSION_DOMAIN` | `database`, `120`, `false`, `/`, `null` | Sessions en base. |
| `CACHE_STORE`, `CACHE_PREFIX`, `QUEUE_CONNECTION`, `FILESYSTEM_DISK` | `database`, commentée, `database`, `local` | Cache et files en base, fichiers sur le disque local. |
| `REDIS_CLIENT`, `REDIS_HOST`, `REDIS_PASSWORD`, `REDIS_PORT` | `phpredis`, `redis`, `null`, `6379` | Le Redis de Sail, dont Horizon a besoin. |
| `MAIL_MAILER`, `MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Mailpit | Les courriels arrivent dans Mailpit, sur le port 8025. |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`, `GITHUB_CLIENT_ID`, `GITHUB_CLIENT_SECRET`, `GITHUB_REDIRECT_URI`, `APPLE_CLIENT_ID`, `APPLE_CLIENT_SECRET`, `APPLE_REDIRECT_URI` | vides | Connexion sociale : un bouton n'apparaît qu'avec l'identifiant et le secret de son fournisseur. L'URL de rappel à déclarer chez lui est `APP_URL` suivie de `/auth/{fournisseur}/callback`. |
| `OCTANE_SERVER` | `frankenphp` | `sail artisan octane:start` sert l'application comme en production. |
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` | vides | Comme en production : sans clés, pas de notifications. |
| `APP_PORT` | `80` | Port de l'hôte publié par Sail. |
| `DUSK_DRIVER_URL` | `http://selenium:4444/wd/hub` | Le Selenium de Sail, pour `sail artisan dusk`. |
| `ADMIN_ALLOWED_IPS`, `HORIZON_ALLOWED_EMAILS`, `ADMIN_INITIAL_PASSWORD`, `HEALTH_TO_ADDRESS`, `BACKUP_ARCHIVE_PASSWORD`, `BACKUP_HOST_PATH` | vides | Celles de la production, pour l'essayer en local. |

Le front ne lit qu'une variable, `VITE_APP_NAME`. Une variable `VITE_*` est **publique** : Vite la recopie dans les fichiers servis au navigateur, elle ne porte donc jamais de secret.

`compose.yaml` lit aussi les réglages propres à Sail, à ajouter au `.env` au besoin : `FORWARD_DB_PORT`, `FORWARD_REDIS_PORT`, `FORWARD_MAILPIT_PORT`, `FORWARD_MAILPIT_DASHBOARD_PORT`, `VITE_PORT`, `SAIL_XDEBUG_MODE` et `SAIL_XDEBUG_CONFIG`.

### Secrets de CI

| Secret | Obligatoire | Rôle |
| --- | --- | --- |
| `GITHUB_TOKEN` | fourni par GitHub | Publie l'image sur ghcr.io, crée les releases, ouvre les issues d'échec de `main`, ferme les issues inactives. |
| `AUTO_MERGE_TOKEN` | non | Jeton personnel à granularité fine du job `dependabot` de `.github/workflows/auto-merge.yml`. Une fusion faite avec lui relance la CI sur `main`, ce que `GITHUB_TOKEN` ne fait pas (#1672) ; absent, le workflow retombe sur `GITHUB_TOKEN`. |
| `AVIS_HORS_LIGNE_TOKEN` | non | Jeton personnel qui laisse `.github/workflows/avis-hors-ligne.yml` ouvrir lui-même la PR de rafraîchissement de `roave/security-advisories`, avec une CI qui tourne. Absent, le workflow se contente d'avertir, puis échoue passé quatorze jours. |

---

## 🛠️ Stack Technique

| Catégorie | Technologies |
| --- | --- |
| **Backend** | Laravel 13, PHP 8.5 (Strict Types), MySQL |
| **Frontend** | Vue 3, Inertia.js 3, Tailwind CSS 4 |
| **Testing** | Pest 5, PHPUnit 13, Laravel Dusk 8 |
| **DevOps** | Laravel Sail (Docker), GitHub Actions |
| **Monitoring** | Le panneau (santé, exceptions, tâches planifiées, journaux, erreurs navigateur), Laravel Pulse, Telescope |

---

## 🎨 Charte graphique

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

---

## 🚀 Installation (via Laravel Sail)

### Prérequis
- Docker Desktop
- PHP & Composer (uniquement pour l'installation initiale de Sail si besoin)

### Installation Rapide
```bash
# Clone le repo
git clone https://github.com/kuasar-mknd/gym-tracker.git
cd gym-tracker

# Installation des dépendances via Docker
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs

# Configuration
cp .env.example .env
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail npm install
./vendor/bin/sail npm run build
```

---

## 💻 Développement

### Commandes courantes
| Commande | Description |
| --- | --- |
| `./vendor/bin/sail up -d` | Lance les conteneurs (App, MySQL, Redis, Mailpit, Selenium) |
| `./vendor/bin/sail npm run dev` | Lance Vite avec Hot Reload |
| `./vendor/bin/sail artisan test -p` | Suite backend en parallèle (~25 s) |
| `./vendor/bin/sail npx vitest run` | Suite frontend |
| `./vendor/bin/sail artisan dusk` | Parcours navigateur |
| `./vendor/bin/sail bin pint` | Formate le code |
| `./vendor/bin/sail php vendor/bin/phpstan analyse` | Analyse statique, `level: max` |
| `./vendor/bin/sail php vendor/bin/rector process --dry-run` | Modernisation en attente |

### Mutation testing

Le seuil n'est appliqué que par `vendor/bin/pest` : `artisan test --mutate --min` accepte l'option et l'ignore — c'est le `--min` de la **couverture**, silencieusement absorbé.

```bash
./vendor/bin/sail php vendor/bin/pest --mutate --parallel --covered-only \
  --class='App\Policies' --min=92
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

Le nocturne n'est pas concerné : un runner GitHub à quatre cœurs ne lance que deux processus, et sa passe de référence est deux fois plus lente, ce qui élargit d'autant le délai. Mesure sur trois nuits consécutives — 1 timeout sur les 841 mutations de `App\Services`, 4 sur les 887 de `App\Actions`, 0 sur `App\Policies`.

---

## 🤝 Contribution

Les contributions sont les bienvenues !
1. Assure-toi que les tests passent : `./vendor/bin/sail artisan test`
2. Vérifie la qualité : `./vendor/bin/sail artisan insights`
3. Formate ton code : `./vendor/bin/sail bin pint`
4. Voir le [Guide de Contribution](CONTRIBUTING.md) pour plus de détails.
