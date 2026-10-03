<p align="center">
  <img src="https://img.shields.io/badge/Laravel-13-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel 13" />
  <img src="https://img.shields.io/badge/Vue.js-3-4FC08D?style=for-the-badge&logo=vue.js&logoColor=white" alt="Vue 3" />
  <img src="https://img.shields.io/badge/Tailwind_CSS-4-06B6D4?style=for-the-badge&logo=tailwindcss&logoColor=white" alt="Tailwind CSS 4" />
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
  <a href="#-qualité">Qualité</a> •
  <a href="#-mise-en-production">Mise en production</a> •
  <a href="#-variables-denvironnement">Variables d'environnement</a> •
  <a href="#-installation-via-laravel-sail">Installation</a> •
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
- **Composition corporelle** — Saisis ton % de masse grasse avec tes mesures et suis-le en graphique.
- **Mesures corporelles** — Suivi complet de ton évolution physique.

### 🔐 Sécurité & Outils
- **OAuth Social** — Connexion via Google et GitHub, dès que l'identifiant et le secret du fournisseur sont posés ; Apple attend encore son câblage (#1911).
- **Outils** — Calculateurs de plaques, de 1RM, de Wilks et de macros, échauffement, hydratation, minuteur d'intervalles et jeûne.
- **Sécurité renforcée** — Throttling API, CSP strict et Nonce-based protection (un nonce neuf à chaque requête, Octane compris).

---

## 🏆 Qualité

Chaque seuil ci-dessous est **appliqué par la CI**, pas déclaratif. Ils sont posés au niveau mesuré et montent par cliquets — un seuil qu'on n'atteint pas finit désactivé.

| Contrôle | Seuil | Où |
| --- | --- | --- |
| **PHPStan** | `level: max` + strict-rules, deprecation-rules, détecteur de code mort | bloquant par PR |
| **Tests backend** | 1 999 tests, couverture ≥ **94 %** | bloquant par PR |
| **Tests frontend** | 2 087 tests, ≥ **95 %** statements / 92 branches / 92 functions / 95 lines | bloquant par PR |
| **Tests navigateur** | 117 parcours Dusk sous Chrome headless | bloquant par PR |
| **PHP Insights** | ≥ 90 en qualité, complexité, architecture et style | bloquant par PR |
| **Rector / Pint** | aucun changement en attente | bloquant par PR |
| **Mutation testing** | ≥ 80 % `App\Services`, 95 % `App\Actions`, 99 % `App\Policies` | nocturne, **bloque la release** |

S'y ajoutent plus de quarante **gardes de convention** — des tests qui protègent une règle plutôt qu'un comportement : sous-ensemble de police d'icônes, frontières de propriété des policies, absence d'oracle de divulgation sur l'API, zoom des champs sur iOS, identifiants provisoires qui ne doivent jamais atteindre le serveur, variables d'environnement documentées.

Voir aussi les [décisions d'architecture](docs/adr/) et la [charte graphique](docs/charte.html). La feuille de route vit dans les issues GitHub et le journal des modifications, pas dans un document qui vieillit.

---

## 📦 Mise en production

La production suit l'image `ghcr.io/kuasar-mknd/gym-tracker:v1`, publiée quand un tag `v*` est poussé. `v1` suit chaque v1.x.y : une mise à jour, c'est `docker compose -f docker-compose.prod.yml up -d` (ou la mise à jour de la pile) : `pull_policy: always` retélécharge l'image et recrée les conteneurs dont l'image a changé. Un conteneur garde sinon l'image avec laquelle il a été créé, et un simple redémarrage ne télécharge rien (#1813). Au démarrage, `app` joue les migrations, et une migration qui échoue l'arrête plutôt que de servir un code qui ne correspond pas au schéma.

**Ce tag ne publie rien tant que tout n'est pas vert.** Sur le commit exact du tag, l'image n'est poussée que si :

1. les tests backend, frontend et navigateur, le lint, l'audit et la construction multi-architecture sont verts ;
2. l'image démarre sur une base MySQL vide et répond sur `/up` (job `demarrage`) ;
3. la **passe nocturne** est verte, chacune de ses parts, seuils de mutation compris (job `promotion`).

La release GitHub exige en plus la CI entière, `semgrep`, `secrets` et `workflows` compris.

La nuit tourne sur la pointe de `main` à 03h17 UTC : un tag posé après elle n'est pas encore couvert. Pour le débloquer :

```bash
gh workflow run mutation.yml --ref v1.2.3
```

Une fois la nuit verte, rien ne relance la publication tout seul : relancer les jobs échoués du run de CI du tag (`gh run rerun <id> --failed`), puis le run de `release.yml`.

Un échec sur `main` — CI ou passe nocturne — **ouvre automatiquement une issue**, dédupliquée par workflow.

La page « Santé » du panneau dit ce que le dépôt ne peut pas corriger seul sur le serveur de production ; comme les autres contrôles, ces trois-là écrivent à `HEALTH_TO_ADDRESS` quand ils passent au rouge :

- **Dossier des sauvegardes** écrit puis efface une sonde dans le partage monté (#1812). Chaque accès est borné à dix secondes : un partage qui ne répond plus met le contrôle au rouge (« Sans réponse ») et suspend « Backups », dont le parcours des archives n'a pas de délai, au lieu de figer tous les contrôles. Au démarrage, chaque conteneur fait la même vérification, bornée elle aussi, et, en cas d'échec, écrit dans `docker logs` « ATTENTION : le dossier des sauvegardes … n'est pas inscriptible par uid … » sans s'arrêter.
- **Versions des conteneurs** compare l'image qu'exécutent `app`, `worker` et `scheduler`, chacun l'annonçant à son démarrage (#1813). Un conteneur garde l'image avec laquelle il a été créé : au rouge, mettre à jour la pile en retéléchargeant l'image. `docker exec <conteneur> printenv APP_VERSION APP_REVISION` donne la version d'un conteneur ; pour une image plus ancienne, l'étiquette `org.opencontainers.image.revision` de `docker inspect`.
- **Réglages de la base** relit en production `innodb_flush_log_at_trx_commit` et `log_bin` dans MySQL, et l'état de Pulse (#1668) : rouge si une écriture repaie la synchronisation du disque, orange si Pulse enregistre ou si MySQL ne rend pas l'un des deux réglages.

`docker-compose.prod.yml` déclare cinq services : `app`, `db`, `redis`, `worker` (Horizon) et **`scheduler`** — ce dernier exécute les tâches planifiées. Sans lui, les tâches ne tournent pas — ni le contrôle de santé qui enverrait l'alerte : la page « Santé » garde des résultats qui vieillissent, et seul son bouton de rafraîchissement fait passer le planificateur au rouge.

Le service `db` tourne avec `--innodb-flush-log-at-trx-commit=2` et `--skip-log-bin` : sur le disque de production, chaque écriture coûtait 250 à 500 ms de synchronisation ; le journal est désormais synchronisé une fois par seconde, et une coupure brutale (pas un redémarrage propre) peut perdre jusqu'à une seconde d'écritures validées. `--innodb-redo-log-capacity=256M` et `--innodb-io-capacity=200` (`-max=1000`) remplacent les défauts de MySQL : ce sont les réglages appliqués et mesurés sur la pile déployée (#1668).

Les journaux des trois conteneurs de l'application vont dans `docker logs` et, un fichier par conteneur (`app`, `worker`, `scheduler`), dans le volume `journaux` que lit la page « Journaux » du panneau. Le fichier est indispensable au planificateur, qui envoie la sortie de chaque tâche dans /dev/null.

L'application s'ouvre **par le proxy inverse HTTPS**, jamais directement sur le port 8888 publié par `app` : en production, le cookie de session est réservé à HTTPS, et une visite en http ne garde aucune session : la connexion échoue. Le proxy doit transmettre `X-Forwarded-Proto` ; Laravel fait confiance aux adresses privées (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`).

---

## 🔧 Variables d'environnement

Une variable se déclare dans `.env.example` pour le développement et se documente ici, dans la PR qui l'ajoute, la renomme, la retire ou en change le rôle. `LeReadmeDocumenteLesVariablesTest` refuse une variable de `docker-compose.prod.yml`, de `.env.example` ou un secret de CI que cette section ne nomme pas, et une variable du tableau « Production » que la composition ne transmet pas.

### Production : à poser dans la pile

Elles se posent dans l'environnement de la pile : les variables de la pile, ou un fichier `.env` à côté de `docker-compose.prod.yml`. Trois règles valent pour toutes :

- **Seules celles que `docker-compose.prod.yml` transmet atteignent les conteneurs.** Une variable lue par `config/` mais absente de ce fichier garde son défaut, quoi qu'on pose dans la pile (voir « Lues par l'application, non transmises »).
- **Une variable oubliée arrive vide, pas absente.** Compose avertit et la remplace par une chaîne vide ; Laravel retient cette chaîne vide, pas le défaut de sa configuration. La colonne « Défaut » dit ce que reçoit le conteneur quand la variable manque. Seules `BACKUP_ARCHIVE_PASSWORD` et `BACKUP_HOST_PATH` empêchent la pile de démarrer ; les autres obligatoires la laissent démarrer, puis un service tombe.
- **La configuration est figée au démarrage du conteneur** : `entrypoint.sh` lance `php artisan config:cache`. Une variable changée n'agit qu'une fois les conteneurs recréés (`docker compose up -d`, ou la mise à jour de la pile) ; un redémarrage garde l'ancien environnement, et `docker exec -e` ne change pas la configuration.

#### Application

| Variable | Obligatoire en production | Défaut | Rôle |
| --- | --- | --- | --- |
| `APP_KEY` | oui | vide : chaque page échoue (`MissingAppKeyException`) | Clé de chiffrement des cookies et des URL signées, au format `base64:…` : `echo "base64:$(openssl rand -base64 32)"` en produit une. En changer déconnecte tout le monde et invalide les liens de vérification d'adresse déjà envoyés ; `APP_PREVIOUS_KEYS`, qui permettrait une rotation sans casse, n'est pas transmise. |
| `APP_URL` | oui | vide | Adresse publique en `https://`, celle du proxy inverse. La composition la recopie dans `ASSET_URL` : une adresse fausse fait charger CSS et JavaScript depuis une mauvaise origine, et la page s'affiche sans style ni script. Elle sert aussi aux liens produits hors d'une requête (worker, planificateur), à l'origine CORS de l'API et d'identité Web Push quand `VAPID_SUBJECT` est vide. |
| `APP_DEBUG` | non | `false` | Pages d'erreur détaillées : code source, requêtes SQL avec leurs valeurs, en-têtes (cookies compris) et champs envoyés, mots de passe saisis compris. Jamais en production, où le contrôle du mode debug de la page « Santé » passe alors au rouge. Le `Dockerfile` pose aussi `false`. |

#### Base de données

Le service `db` reçoit `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` et `DB_ROOT_PASSWORD` sous les noms `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD` et `MYSQL_ROOT_PASSWORD`, et ne les lit **qu'à l'initialisation d'un volume vide** : les changer ensuite ne modifie ni la base ni ses comptes, et un `DB_PASSWORD` changé dans la pile sans `ALTER USER` dans MySQL coupe l'application.

| Variable | Obligatoire en production | Défaut | Rôle |
| --- | --- | --- | --- |
| `DB_DATABASE` | oui | vide : aucune base n'est créée, la migration échoue et `app` s'arrête | Nom de la base de l'application. |
| `DB_USERNAME` | oui | vide : aucun utilisateur n'est créé | Utilisateur MySQL de l'application, créé au premier démarrage. Pas `root` : l'image mysql refuse `MYSQL_USER=root` et le service `db` s'arrête. |
| `DB_PASSWORD` | oui | vide : l'utilisateur n'est pas créé | Mot de passe de cet utilisateur, aussi celui de la sonde de santé de `db`. Faux ou vide, `app`, `worker` et `scheduler` attendent la base vingt minutes (« Waiting for DB ») puis s'arrêtent. |
| `DB_ROOT_PASSWORD` | au premier démarrage | vide : `db` refuse d'initialiser un volume neuf | Mot de passe root de MySQL. L'application ne s'en sert jamais. |

#### Redis

| Variable | Obligatoire en production | Défaut | Rôle |
| --- | --- | --- | --- |
| `REDIS_PASSWORD` | oui | vide : Redis refuse de démarrer | Mot de passe imposé au service `redis` (`--requirepass`) et utilisé par `app`, `worker` et `scheduler` pour les sessions, le cache, les files et Horizon. Vide, `redis-server` s'arrête sur « wrong number of arguments » et redémarre en boucle : `worker` et `scheduler` ne démarrent pas, et chaque page de `app` échoue. |

#### Courriel

| Variable | Obligatoire en production | Défaut | Rôle |
| --- | --- | --- | --- |
| `MAIL_HOST`, `MAIL_PORT` | oui | vides : aucun courriel ne part | Serveur SMTP de tous les courriels : vérification d'adresse, mot de passe oublié, alertes de santé, notifications des sauvegardes manuelles. `MAIL_PORT` décide aussi du chiffrement quand `MAIL_SCHEME` est vide. |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | si le relais l'exige | vides : pas d'authentification | Authentification SMTP. |
| `MAIL_SCHEME` | non | vide : déduit du port | `smtps` (TLS implicite) ou `smtp` (STARTTLS quand le serveur le propose). Vide : `smtps` sur le port 465, `smtp` ailleurs. Remplace `MAIL_ENCRYPTION`, que Laravel ne lit plus : une pile qui la pose encore n'en tire rien. |
| `MAIL_FROM_ADDRESS` | oui | vide : l'envoi échoue | Expéditeur de tous les courriels, et destinataire des notifications de sauvegarde faute de `BACKUP_NOTIFICATION_EMAIL`. |
| `MAIL_FROM_NAME` | conseillé | vide : courriels sans nom d'expéditeur | Nom de l'expéditeur. Le défaut de la configuration ne s'applique pas : la composition transmet une chaîne vide. |

#### Notifications Web Push

| Variable | Obligatoire en production | Défaut | Rôle |
| --- | --- | --- | --- |
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` | pour les notifications | vides : aucune notification | Clés VAPID, générées une fois par `npx web-push generate-vapid-keys`. Sans la clé publique, la page Profil affiche « Le service de notifications n'est pas encore configuré sur le serveur. » ; la clé privée signe chaque envoi du worker. En changer invalide tous les abonnements existants. |
| `VAPID_SUBJECT` | non | `APP_URL` | Identité de l'expéditeur auprès du service push : une adresse `mailto:` ou une URL. |

#### Sauvegardes

| Variable | Obligatoire en production | Défaut | Rôle |
| --- | --- | --- | --- |
| `BACKUP_ARCHIVE_PASSWORD` | oui, la pile refuse de démarrer | aucun | Mot de passe AES-256 des archives, transmis à `app`, `worker` et `scheduler`. Sans lui, aucune archive n'est écrite, que la sauvegarde vienne du planificateur, du panneau ou de `backup:run` : une archive en clair sur une autre machine serait une fuite. Le perdre rend les archives illisibles. |
| `BACKUP_HOST_PATH` | oui, la pile refuse de démarrer | aucun | Dossier de l'hôte monté sur `/app/storage/app/sauvegardes` dans `app`, `worker` et `scheduler` : un dossier partagé d'une autre machine, monté sur l'hôte, jamais un volume Docker. Il doit être inscriptible par l'utilisateur du conteneur, `www-data` (uid 33), sans quoi aucune archive ne s'écrit (#1812). |

#### Connexion sociale

Un bouton n'apparaît qu'avec l'identifiant **et** le secret de son fournisseur ; vides, rien ne change. L'URL de rappel à déclarer chez le fournisseur est `APP_URL` suivie de `/auth/google/callback` ou `/auth/github/callback`. Un échange refusé par le fournisseur (`redirect_uri_mismatch`, `invalid_client`…) laisse une ligne « Connexion sociale refusée par le fournisseur » dans les journaux.

| Variable | Obligatoire en production | Défaut | Rôle |
| --- | --- | --- | --- |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | pour proposer Google | vides : bouton masqué | Client OAuth « Application Web » de la console Google Cloud. Transmises à `app` seul. |
| `GITHUB_CLIENT_ID`, `GITHUB_CLIENT_SECRET` | pour proposer GitHub | vides : bouton masqué | OAuth App de GitHub (Settings › Developer settings). Transmises à `app` seul. |

#### Administration et supervision

| Variable | Obligatoire en production | Défaut | Rôle |
| --- | --- | --- | --- |
| `ADMIN_ALLOWED_IPS` | pour ouvrir le panneau | vide : tout répond 404 | Adresses autorisées sur `/backoffice`, `/backoffice/pulse` et `/backoffice/journaux`, séparées par des virgules : adresses exactes ou plages CIDR, IPv4 et IPv6 (`192.168.1.0/24,203.0.113.32`). Transmise à `app` seul. |
| `HORIZON_ALLOWED_EMAILS` | pour ouvrir Horizon | vide : fermé à tous | Adresses des comptes **utilisateurs** de l'application, pas des administrateurs du panneau, admis sur `/horizon`, séparées par des virgules. `/horizon` ne passe pas par `ADMIN_ALLOWED_IPS`. Transmise à `app` seul. |
| `ADMIN_INITIAL_PASSWORD` | pour créer le premier administrateur | vide : le seeder échoue | Mot de passe du compte `admin@gymtracker.app`, créé par `php artisan db:seed --class=AdminSeeder --force` dans le conteneur `app` (`docker exec`). Le seeder ne réécrit jamais un mot de passe existant : à retirer de la pile une fois le compte créé. Transmise à `app` seul. |
| `HEALTH_TO_ADDRESS` | non | vide : aucun courriel | Adresse qui reçoit un courriel, une fois par heure au plus, quand un contrôle de santé passe au rouge : base et ses réglages, Redis, cache, file, planificateur, tâches planifiées, Horizon, versions des conteneurs, disque, dossier des sauvegardes, sauvegardes, mode debug, environnement, caches de l'application. Les contrôles tournent dans le planificateur toutes les cinq minutes : `scheduler` arrêté, aucun courriel ne part. Vide, la page « Santé » du panneau reste seule. |
| `LOG_LEVEL` | non | `info` | Niveau minimal des journaux : `debug` pour un dépannage, `warning` pour n'écrire que les incidents. |
| `PULSE_ENABLED` | non | `false` | Laravel Pulse. Il écrit ses agrégats en base à chaque requête et chaque job ; en production, cela provoquait un convoi de verrous (145 attentes en 205 s, aucune une fois coupé, #1668). `/backoffice/pulse` reste consultable, sans nouvelles données tant qu'il est coupé. |
| `SERVER_TIMING_ENABLED` | non | `false` | En-tête `Server-Timing` sur les réponses d'un utilisateur connecté ou d'un administrateur du panneau : durée de l'application, découpée en `routage`, `controleur` et `rendu`, puis nombre et durée cumulée des requêtes SQL (`sql;dur=12.4;desc="7 requetes"`), lisibles dans l'onglet réseau du navigateur. Jamais pour un invité, ni sur une réponse d'authentification ou un 404 : la durée dirait si un compte ou une ressource existe. Seule une valeur vraie (`true`, `1`) l'allume, vide ou absente le coupe. À allumer le temps d'une mesure (#1315), puis à couper. |

### Fixées par la composition

Écrites en dur dans `docker-compose.prod.yml` : les poser dans la pile ne change rien. L'image pose de son côté `APP_ENV=production`, `APP_DEBUG=false` et `SERVER_NAME=:80` ; cette dernière est sans effet, le Caddyfile de l'application lisant l'adresse qu'Octane lui passe.

| Variable | Valeur | Rôle |
| --- | --- | --- |
| `APP_ENV` | `production`, aussi posée par le `Dockerfile` | Cookie de session réservé à HTTPS, panneau fermé sans `ADMIN_ALLOWED_IPS`, Horizon fermé hors liste, mots de passe à casse mixte et absents des fuites connues. Telescope, dépendance de développement, n'est pas dans l'image. |
| `ASSET_URL` | la valeur d'`APP_URL` | Les actifs se servent depuis l'adresse publique. |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT` | `mysql`, `db`, `3306` | Le service `db` ; `entrypoint.sh` l'attend jusqu'à vingt minutes avant d'abandonner. |
| `REDIS_HOST`, `REDIS_PORT` | `redis`, `6379` | Le service `redis`. |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | `redis` | Sessions, cache, résultats des contrôles de santé, versions annoncées par les conteneurs et file d'Horizon, partagés par les trois conteneurs de l'application. |
| `MAIL_MAILER` | `smtp` | Les courriels partent par le serveur de `MAIL_HOST`. |
| `GOMAXPROCS` | `2` | Plafonne les threads Go de FrankenPHP dans `app` ; `worker` et `scheduler`, en PHP CLI, l'ignorent. |
| `OCTANE_SERVER` | `frankenphp`, dans `app` seulement | Serveur que visent `octane:status` et `octane:reload` ; l'image lance `octane:frankenphp` directement. |
| `LOG_CHANNEL`, `LOG_STACK` | `stack`, `stderr,daily` | Chaque ligne va dans `docker logs` et dans le fichier du jour du conteneur, gardé quatorze jours dans le volume `journaux` (#1907). |
| `LOG_DAILY_NAME` | `app`, `worker` ou `scheduler`, selon le service | Nom du fichier de journal du conteneur : la page « Journaux » du panneau dit ainsi qui a écrit quoi. |
| `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD` | `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_ROOT_PASSWORD` | Variables de l'image mysql du service `db`, lues à l'initialisation d'un volume vide. |

### Fixées par l'image

Posées par le `Dockerfile` à partir des arguments que la CI passe au build : les poser dans la pile ne change rien, et un conteneur dit ainsi l'image qu'il exécute, pas celle qu'on croit avoir déployée.

| Variable | Valeur | Rôle |
| --- | --- | --- |
| `APP_VERSION` | le tag construit (`v1.5.20`), `main` sur `main` ; `dev` hors CI | Version que chaque conteneur annonce à son démarrage, la même que l'étiquette `org.opencontainers.image.version` de l'image ; le contrôle « Versions des conteneurs » compare celles d'`app`, `worker` et `scheduler` (#1813). |
| `APP_REVISION` | le commit construit ; `inconnue` hors CI | Révision annoncée avec la version : deux images de `main` portent la même version et ne diffèrent que par elle. |

### Lues par l'application, non transmises en production

`config/` les lit, mais la composition ne les transmet pas : la production applique leur défaut, quoi qu'on pose dans la pile. Pour en régler une, l'ajouter à `docker-compose.prod.yml` avec un défaut (`${NOM:-valeur}`), puis la déplacer dans le tableau « Production ».

| Variable | Défaut appliqué | Effet |
| --- | --- | --- |
| `APP_NAME` | `GymTracker` ; `laravel` pour les préfixes | Nom de l'application et du dossier des archives que vérifie la page « Santé ». Les préfixes des clés Redis, du cache et d'Horizon et le nom du cookie de session se calculent, eux, sur `laravel` : transmettre `APP_NAME` les changerait tous — déconnexion générale, cache et métriques d'Horizon repartis de zéro. |
| `APP_TIMEZONE` | `Europe/Paris` | Fuseau de l'application et des heures du planificateur : rappel d'entraînement à 18 h, sauvegarde à 02 h 30. |
| `APP_PREVIOUS_KEYS` | vide | Anciennes clés acceptées pendant une rotation d'`APP_KEY` ; sans elle, une rotation déconnecte tout le monde. |
| `SESSION_SECURE_COOKIE` | `true` en production | Le cookie de session n'est envoyé qu'en HTTPS : d'où le passage obligé par le proxy inverse. |
| `SESSION_LIFETIME` | `120` | Minutes d'inactivité avant que la session expire ; « Se souvenir de moi » reconnecte ensuite sans mot de passe. |
| `BACKUP_PATH` | `/app/storage/app/sauvegardes` | Racine du disque des archives, exactement la cible du montage de `BACKUP_HOST_PATH`. À ne pas transmettre : une autre valeur écrirait les archives dans le conteneur, hors du partage. |
| `BACKUP_NOTIFICATION_EMAIL` | `MAIL_FROM_ADDRESS` | Destinataire des notifications de sauvegarde. Les sauvegardes planifiées les coupent (`--disable-notifications`) : seules celles lancées à la main, du panneau ou par `backup:run`, écrivent. |
| `HORIZON_HEARTBEAT_URL`, `SCHEDULE_HEARTBEAT_URL` | vides | URL qu'un contrôle réussi d'Horizon ou du planificateur appellerait, pour qu'une surveillance externe s'alarme quand les appels cessent — y compris planificateur arrêté, ce que `HEALTH_TO_ADDRESS` ne peut pas signaler. |
| `APPLE_CLIENT_ID`, `APPLE_CLIENT_SECRET` | vides | Le bouton Apple reste masqué : son rappel arrive en POST et son secret doit être signé, ce que l'application ne sait pas encore faire (#1911). |
| `GOOGLE_REDIRECT_URI`, `GITHUB_REDIRECT_URI` | `APP_URL` suivie de `/auth/google/callback` ou `/auth/github/callback` | L'URL de rappel à déclarer chez le fournisseur. |

### Front : variables de build

Vite recopie les variables `VITE_*` dans les fichiers servis au navigateur : elles sont **publiques** et ne portent jamais de secret. L'image se construit sans `.env` : en production, chacune prend le repli du code.

| Variable | Obligatoire en production | Défaut | Rôle |
| --- | --- | --- | --- |
| `VITE_APP_NAME` | non, et non réglable : figée au build de l'image | `GymTracker` | Suffixe du titre de chaque page. En développement, `.env.example` la recopie d'`APP_NAME`. |

### Développement local

Sous Sail, `cp .env.example .env` suffit : le gabarit vise les services de `compose.yaml` (`mysql`, `redis`, `mailpit`, `selenium`).

| Variable | Valeur du gabarit | Rôle |
| --- | --- | --- |
| `APP_NAME` | `GymTracker` | Nom de l'application ; `VITE_APP_NAME` et `MAIL_FROM_NAME` le recopient. |
| `APP_ENV`, `APP_DEBUG` | `local`, `true` | Pages d'erreur détaillées ; panneau, Horizon et Telescope ouverts ; connexion de développement par `/__dev-login`. |
| `APP_KEY`, `APP_URL` | vide, `http://localhost` | `sail artisan key:generate` remplit la clé. |
| `APP_LOCALE`, `APP_FALLBACK_LOCALE`, `APP_FAKER_LOCALE` | `fr`, `en`, `fr_FR` | Langue de l'interface, langue de repli, langue des données factices. |
| `APP_MAINTENANCE_DRIVER`, `APP_MAINTENANCE_STORE` | `file`, commentée | Où se mémorise le mode maintenance. |
| `PHP_CLI_SERVER_WORKERS` | commentée | Processus de `artisan serve`, que Sail lance pour servir l'application. |
| `BCRYPT_ROUNDS` | `12` | Coût du hachage des mots de passe ; phpunit.xml le baisse à 4. |
| `LOG_CHANNEL`, `LOG_STACK`, `LOG_LEVEL`, `LOG_DEPRECATIONS_CHANNEL` | `stack`, `single`, `debug`, `null` | Journaux dans storage/logs/laravel.log, dépréciations ignorées. Avec `LOG_STACK=daily`, `LOG_DAILY_NAME` (défaut `laravel`) nomme le fichier du jour. |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | `mysql`, `mysql`, `3306`, `gym_tracker`, `sail`, `password` | Le MySQL de Sail, qui crée la base et l'utilisateur au premier démarrage du volume. Les tests gardent l'hôte mais visent la base `gym_tracker_testing`, les parcours navigateur la base `gym_tracker_dusk` par `.env.dusk.local` ; le premier démarrage du volume crée les deux. |
| `SESSION_DRIVER`, `SESSION_LIFETIME`, `SESSION_ENCRYPT`, `SESSION_PATH`, `SESSION_DOMAIN` | `database`, `120`, `false`, `/`, `null` | Sessions en base. |
| `CACHE_STORE`, `CACHE_PREFIX`, `QUEUE_CONNECTION`, `FILESYSTEM_DISK` | `database`, commentée, `database`, `local` | Cache et file en base, fichiers sur le disque local. Les tâches en file attendent `queue:listen` (lancé par `sail composer dev`) : Horizon ne sert que la connexion `redis`. |
| `REDIS_CLIENT`, `REDIS_HOST`, `REDIS_PASSWORD`, `REDIS_PORT` | `phpredis`, `redis`, `null`, `6379` | Le Redis de Sail, dont Horizon a besoin. |
| `MAIL_MAILER`, `MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Mailpit | Les courriels arrivent dans Mailpit, sur le port 8025. |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`, `GITHUB_CLIENT_ID`, `GITHUB_CLIENT_SECRET`, `GITHUB_REDIRECT_URI`, `APPLE_CLIENT_ID`, `APPLE_CLIENT_SECRET`, `APPLE_REDIRECT_URI` | vides, URL de rappel dérivées d'`APP_URL` | Connexion sociale : un bouton n'apparaît qu'avec l'identifiant et le secret de son fournisseur. L'URL de rappel à déclarer chez lui est `APP_URL` suivie de `/auth/{fournisseur}/callback`. |
| `OCTANE_SERVER` | `frankenphp` | Serveur que visent les commandes `octane:*` ; Sail, lui, sert l'application par `artisan serve`. |
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` | vides | Comme en production : sans clés, pas de notifications. |
| `SERVER_TIMING_ENABLED` | `false` | `true` ajoute l'en-tête `Server-Timing` aux pages d'un compte connecté, comme en production : le temps serveur se lit dans l'onglet réseau du navigateur. |
| `APP_PORT` | `80` | Port de l'hôte publié par Sail. |
| `DUSK_DRIVER_URL` | `http://selenium:4444/wd/hub` | Le Selenium de Sail, pour `sail artisan dusk`. Le navigateur tourne alors dans le conteneur `selenium` : `APP_URL` doit y désigner l'application, ce que ne font ni `localhost` ni `127.0.0.1` (voir « Parcours navigateur »). |
| `ADMIN_ALLOWED_IPS`, `HORIZON_ALLOWED_EMAILS`, `ADMIN_INITIAL_PASSWORD`, `HEALTH_TO_ADDRESS`, `BACKUP_ARCHIVE_PASSWORD` | vides | Celles de la production. Vides en local : panneau et Horizon ouverts, aucun courriel de santé, et une sauvegarde échoue faute de mot de passe d'archive. |

`compose.yaml` lit aussi les réglages propres à Sail, à ajouter au `.env` au besoin : `FORWARD_DB_PORT`, `FORWARD_REDIS_PORT`, `FORWARD_MAILPIT_PORT`, `FORWARD_MAILPIT_DASHBOARD_PORT`, `VITE_PORT`, `SAIL_XDEBUG_MODE`, `SAIL_XDEBUG_CONFIG` et `MYSQL_EXTRA_OPTIONS`. Le script sail pose lui-même `WWWUSER` et `WWWGROUP`.

### Secrets de CI

| Secret | Obligatoire | Rôle |
| --- | --- | --- |
| `GITHUB_TOKEN` | fourni par GitHub | Publie l'image sur ghcr.io, crée les releases, ouvre les issues d'échec de `main`, ferme les issues inactives, et crée les labels que `.github/dependabot.yml` demande et qui manquent sur le dépôt (`.github/workflows/labels-dependabot.yml`, #1905). Une fusion ou une PR faites avec lui ne déclenchent aucun workflow. |
| `AUTO_MERGE_TOKEN` | non | Jeton personnel à granularité fine du job `dependabot` de `.github/workflows/auto-merge.yml`. Une fusion faite avec lui relance la CI sur `main`, ce que `GITHUB_TOKEN` ne fait pas (#1672) ; absent, le workflow retombe sur `GITHUB_TOKEN`. |
| `AVIS_HORS_LIGNE_TOKEN` | non | Jeton personnel limité à ce dépôt — Contents et Pull requests en lecture et écriture, surtout pas Workflows — qui laisse `.github/workflows/avis-hors-ligne.yml` ouvrir lui-même la PR de rafraîchissement de `roave/security-advisories`, avec une CI qui tourne. Absent, le workflow se contente d'avertir, puis échoue passé quatorze jours. |

---

## 🛠️ Stack Technique

| Catégorie | Technologies |
| --- | --- |
| **Backend** | Laravel 13, PHP 8.5 (Strict Types), Laravel Octane sur FrankenPHP, MySQL 8.4, Redis et Laravel Horizon |
| **Frontend** | Vue 3, Inertia.js 3, Tailwind CSS 4, Vite 8 |
| **Backoffice** | Filament 5 |
| **Testing** | Pest 5, PHPUnit 13, Laravel Dusk 8 |
| **DevOps** | Docker (image multi-architecture sur ghcr.io), Laravel Sail en développement, GitHub Actions |
| **Monitoring** | Le panneau (santé, exceptions, tâches planifiées, journaux, erreurs navigateur), Horizon, Laravel Pulse (coupé par défaut en production) ; Telescope en développement seulement |

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
- Docker Desktop : PHP et Composer tournent dans des conteneurs.

### Installation Rapide
```bash
# Clone le repo
git clone https://github.com/kuasar-mknd/gym-tracker.git
cd gym-tracker

# Installation des dépendances via Docker. Il n'existe pas d'image
# laravelsail/…-composer à la version de PHP du projet : celle-ci ne sert
# qu'à amorcer Sail, sans exécuter les scripts de Composer.
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs --no-scripts

# Configuration
cp .env.example .env
./vendor/bin/sail up -d
./vendor/bin/sail composer install   # rejoue les scripts, à la bonne version de PHP
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail npm install
./vendor/bin/sail npm run build
```

`migrate --seed` crée un compte de démonstration, `test@example.com` / `password` ; en local, `/__dev-login` s'y connecte d'un clic. Le premier démarrage du MySQL de Sail crée aussi les bases des tests, `gym_tracker_testing` et `gym_tracker_dusk`. Sur un volume plus ancien, le script d'initialisation se rejoue sans toucher aux données de développement :

```bash
./vendor/bin/sail exec mysql bash /docker-entrypoint-initdb.d/20-create-parallel-testing-databases.sh
```

---

## 💻 Développement

### Commandes courantes
| Commande | Description |
| --- | --- |
| `./vendor/bin/sail up -d` | Lance les conteneurs (App, MySQL, Redis, Mailpit, Selenium) |
| `./vendor/bin/sail npm run dev` | Lance Vite avec Hot Reload |
| `./vendor/bin/sail artisan test -p` | Suite backend en parallèle |
| `./vendor/bin/sail npx vitest run` | Suite frontend |
| `./vendor/bin/sail artisan dusk` | Parcours navigateur, sur la base `gym_tracker_dusk` : demande `.env.dusk.local`, voir ci-dessous |
| `./vendor/bin/sail bin pint` | Formate le code |
| `./vendor/bin/sail php vendor/bin/phpstan analyse --memory-limit=2G` | Analyse statique, `level: max` |
| `./vendor/bin/sail php vendor/bin/rector process --dry-run` | Modernisation en attente |

### Parcours navigateur

`artisan dusk` lance les parcours avec le `.env`. Tel quel, sous Sail, ils viseraient la base de développement, et ceux qui vident leur base au premier test la videraient ; le navigateur, qui tourne dans le conteneur `selenium`, chercherait l'application sur `localhost`, c'est-à-dire chez lui. Une garde refuse les deux avant toute écriture (#1909) : la base doit finir par `_dusk`, et `APP_URL` ne peut viser ni `localhost` ni `127.0.0.1` quand le navigateur tourne ailleurs. Une fois, dériver du `.env` la configuration des parcours :

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

`artisan dusk` met `.env.dusk.local` à la place du `.env` le temps de la passe, puis le remet ; il cherche `.env.dusk.` suivi de l'`APP_ENV` du `.env`, `local` sous Sail. Les parcours et le serveur de Sail, qui relit le `.env` à chaque requête, visent ainsi ensemble `gym_tracker_dusk` ; pendant la passe, `http://localhost` sert donc cette base. La CI tourne autrement : serveur et ChromeDriver sur le même exécuteur, `APP_URL=http://127.0.0.1:8000`, dans le `.env` qu'écrit le job `browser-shard`.

### Mutation testing

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

Le nocturne n'est pas concerné : un runner GitHub à quatre cœurs ne lance que deux processus, et sa passe de référence est deux fois plus lente, ce qui élargit d'autant le délai. Mesure sur trois nuits consécutives — 1 timeout sur les 841 mutations de `App\Services`, 4 sur les 887 de `App\Actions`, 0 sur `App\Policies`.

---

## 🤝 Contribution

Les contributions sont les bienvenues !
1. Assure-toi que les tests passent : `./vendor/bin/sail artisan test`
2. Vérifie la qualité aux seuils de la CI : `./vendor/bin/sail php vendor/bin/phpinsights analyse --no-interaction --min-quality=90 --min-complexity=90 --min-architecture=90 --min-style=90`
3. Formate ton code : `./vendor/bin/sail bin pint`
4. Voir le [Guide de Contribution](CONTRIBUTING.md) pour plus de détails.
