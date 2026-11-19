# Variables d'environnement

Cette page est la référence de toutes les variables : celles qu'on pose dans la pile de production, celles que la composition ou l'image fixent, celles du développement local et les secrets de CI.

Une variable se déclare dans `.env.example` pour le développement et se documente ici, dans la PR qui l'ajoute, la renomme, la retire ou en change le rôle. `LesVariablesSontDocumenteesTest` refuse une variable de `docker-compose.prod.yml`, de `.env.example` ou un secret de CI que cette page ne nomme pas, et une variable du tableau « Production » que la composition ne transmet pas.

| Je cherche… | Section |
| --- | --- |
| Ce que je dois poser pour déployer | [Production](#production--à-poser-dans-la-pile) |
| Ce que la composition fixe et que je ne peux pas changer | [Fixées par la composition](#fixées-par-la-composition), [Fixées par l'image](#fixées-par-limage) |
| Une variable que `config/` lit mais que la production n'applique pas | [Lues par l'application, non transmises en production](#lues-par-lapplication-non-transmises-en-production) |
| Le développement sous Sail | [Développement local](#développement-local) |
| Les secrets de la CI | [Secrets de CI](#secrets-de-ci) |

## Production : à poser dans la pile

Elles se posent dans l'environnement de la pile : les variables de la pile, ou un fichier `.env` à côté de `docker-compose.prod.yml`.

Trois règles valent pour toutes :

1. **Seules celles que `docker-compose.prod.yml` transmet atteignent les conteneurs.** Une variable lue par `config/` mais absente de ce fichier garde son défaut, quoi qu'on pose dans la pile (voir « Lues par l'application, non transmises »).
2. **Une variable oubliée arrive vide, pas absente.**
   - Compose avertit et la remplace par une chaîne vide. Laravel retient cette chaîne vide, pas le défaut de sa configuration.
   - La colonne « Défaut » dit ce que reçoit le conteneur quand la variable manque.
   - Seules `BACKUP_ARCHIVE_PASSWORD`, `BACKUP_HOST_PATH` et `REDIS_PASSWORD` empêchent la pile de démarrer. Les autres obligatoires la laissent démarrer, puis un service tombe.
3. **La configuration est figée au démarrage du conteneur** : `entrypoint.sh` lance `php artisan config:cache`.
   - Une variable changée n'agit qu'une fois les conteneurs recréés (`docker compose -f docker-compose.prod.yml up -d`, ou la mise à jour de la pile).
   - Un redémarrage garde l'ancien environnement, et `docker exec -e` ne change pas la configuration.

### Application

| Variable | Obligatoire en production | Défaut | Rôle |
| --- | --- | --- | --- |
| `APP_KEY` | oui | vide : chaque page échoue (`MissingAppKeyException`) | Clé de chiffrement des cookies, des URL signées et de l'adresse que portent les liens des courriels, au format `base64:…`. |
| `APP_PREVIOUS_KEYS` | non | vide | Anciennes clés acceptées pendant une rotation d'`APP_KEY`. Transmise à `app`, `worker` et `scheduler`. |
| `APP_URL` | oui | vide | Adresse publique en `https://`, celle du proxy inverse. |
| `APP_DEBUG` | non | `false` | Pages d'erreur détaillées. **Jamais en production.** |
| `TRUSTED_PROXIES` | conseillé | `127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16` | Pairs dont l'application croit les en-têtes `X-Forwarded-*`. Transmise à `app` seul. |

#### `APP_KEY` : générer, changer

Générer une clé :

```bash
echo "base64:$(openssl rand -base64 32)"
```

**Changer `APP_KEY` a deux effets**, quelle que soit la raison (rotation, remplacement d'une clé publiée) :

- les liens déjà envoyés par courriel cessent de valoir : ouverture d'un compte, vérification ou confirmation d'adresse, réinitialisation du mot de passe ;
- **toutes les sessions se ferment**, avec ou sans `APP_PREVIOUS_KEYS` : l'empreinte du mot de passe que porte chaque session, comme le cookie « se souvenir de moi », est signée par `APP_KEY` seule ;

`APP_PREVIOUS_KEYS` garde lisibles les cookies chiffrés, et valides l'adresse des liens de courriel et les liens signés d'une ancienne clé. Une clé publiée n'y a pas sa place.

Le contrôle « Clé de l'application » de la page « Santé » passe au rouge en production quand la clé est l'une de celles dont `config/app.php` (`cles_publiees`) garde l'empreinte SHA-256 parce qu'elles ont été lisibles dans l'historique du dépôt. Pour la remplacer : [la mise à niveau](mise-a-niveau.md#remplacer-une-clé-app_key-publiée).

#### `APP_URL` : où elle sert

- La composition la recopie dans `ASSET_URL`. Une adresse fausse fait charger CSS et JavaScript depuis une mauvaise origine : la page s'affiche sans style ni script.
- Les liens des courriels de réinitialisation du mot de passe et de vérification d'adresse se bâtissent toujours sur elle, quel que soit l'hôte de la requête qui les envoie. Le second est signé sur l'URL complète : changer `APP_URL` invalide ceux déjà envoyés.
- Elle sert aux liens produits hors d'une requête (worker, planificateur).
- Elle donne l'origine CORS de l'API.
- Elle donne l'identité Web Push quand `VAPID_SUBJECT` est vide.

#### `APP_DEBUG` : pourquoi jamais en production

Elle affiche le code source, les requêtes SQL avec leurs valeurs, les en-têtes (cookies compris) et les champs envoyés, mots de passe saisis compris. En production, le contrôle « Mode debug » de la page « Santé » passe alors au rouge. Le `Dockerfile` pose aussi `false`.

#### `TRUSTED_PROXIES` : à régler

Une liste séparée par des virgules : adresses exactes ou sous-réseaux CIDR, IPv4 et IPv6.

- Le défaut accepte la boucle locale et les trois plages privées.
- À régler sur l'adresse précise, ou le plus petit sous-réseau, sous laquelle le proxy inverse joint `app` : l'adresse que l'application voit comme pair, pas l'adresse publique. Resserré, il doit contenir l'adresse de la passerelle du réseau de conteneurs (voir [Déploiement](deploiement.md#proxy-inverse-et-port-de-lapplication)).
- Une valeur qui ne couvre pas le proxy fait lire l'adresse du proxy pour tous les clients (une seule limite de débit pour tous, `ADMIN_ALLOWED_IPS` sans effet) et perdre HTTPS (liens et redirections en `http://`).
- Vide ou absente : le défaut. Le contrôle « Proxys de confiance » de la page « Santé » le signale en orange en production (plage trop large, entrée invalide ou `*`).

### Base de données

Le service `db` reçoit `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` et `DB_ROOT_PASSWORD` sous les noms `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD` et `MYSQL_ROOT_PASSWORD`. Il ne les lit **qu'à l'initialisation d'un volume vide** : les changer ensuite ne modifie ni la base ni ses comptes. Un `DB_PASSWORD` changé dans la pile sans `ALTER USER` dans MySQL coupe l'application.

| Variable | Obligatoire en production | Défaut | Rôle |
| --- | --- | --- | --- |
| `DB_DATABASE` | oui | vide : aucune base n'est créée, la migration échoue et `app` s'arrête | Nom de la base de l'application. |
| `DB_USERNAME` | oui | vide : aucun utilisateur n'est créé | Utilisateur MySQL de l'application, créé au premier démarrage. Pas `root` : l'image mysql refuse `MYSQL_USER=root` et le service `db` s'arrête. |
| `DB_PASSWORD` | oui | vide : l'utilisateur n'est pas créé | Mot de passe de cet utilisateur. Faux ou vide, `app`, `worker` et `scheduler` attendent la base vingt minutes (« Waiting for DB ») puis s'arrêtent. |
| `DB_ROOT_PASSWORD` | au premier démarrage | vide : `db` refuse d'initialiser un volume neuf | Mot de passe root de MySQL. L'application ne s'en sert jamais ; la sonde de santé de `db` le lit, par `MYSQL_PWD` et non par un argument de `mysqladmin`. |

#### Mots de passe de MySQL : caractères à éviter

**Évitez l'apostrophe (`'`) et l'antislash (`\`)** dans `DB_PASSWORD` et `DB_ROOT_PASSWORD`. L'image `mysql:8.4` recopie ces valeurs telles quelles dans ses instructions d'initialisation (constaté sur des conteneurs jetables). Aucun test ne le vérifie.

| Caractère | Effet |
| --- | --- |
| apostrophe | L'initialisation échoue (`ERROR 1064`) et laisse des comptes faux, sans que la sonde de santé de `db` le dise : `mysqladmin ping` rend 0 tant que `mysqld` répond, accès refusé compris. |
| antislash final | L'initialisation casse (root et utilisateur). |
| antislash au milieu de `DB_ROOT_PASSWORD` | « Access denied ». |
| antislash au milieu de `DB_PASSWORD` | L'initialisation enregistre **sans erreur un autre mot de passe** (`Ab\c1` devient `Abc1`), et l'application ne se connecte plus. |

Les guillemets doubles seuls n'ont pas cassé l'initialisation (non reproduit). Prenez des lettres, des chiffres et des signes simples.

### Redis

| Variable | Obligatoire en production | Défaut | Rôle |
| --- | --- | --- | --- |
| `REDIS_PASSWORD` | oui | vide : la mise à jour de la pile est refusée | Mot de passe imposé aux services `redis` et `redis-cache`, et utilisé par `app`, `worker` et `scheduler` pour les sessions, le cache, les files et Horizon. |

Absent ou vide, Compose refuse la mise à jour (`${REDIS_PASSWORD:?…}`) plutôt que de lancer un Redis sans authentification (`requirepass` vide) : c'est le seul garde-fou.

Chaque Redis lit le mot de passe dans son environnement et le reçoit par une configuration sur l'entrée standard (`requirepass`), jamais par un argument (voir [La pile de production](pile-de-production.md#secrets-hors-des-lignes-de-commande)).

### Courriel

| Variable | Obligatoire en production | Défaut | Rôle |
| --- | --- | --- | --- |
| `MAIL_HOST`, `MAIL_PORT` | oui | vides : aucun courriel ne part | Serveur SMTP de tous les courriels. `MAIL_PORT` décide aussi du chiffrement quand `MAIL_SCHEME` est vide. |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | si le relais l'exige | vides : pas d'authentification | Authentification SMTP. |
| `MAIL_SCHEME` | non | vide : déduit du port | `smtps` (TLS implicite) ou `smtp` (STARTTLS quand le serveur le propose). Vide : `smtps` sur le port 465, `smtp` ailleurs. |
| `MAIL_FROM_ADDRESS` | oui | vide : l'envoi échoue | Expéditeur de tous les courriels, et destinataire des notifications de sauvegarde faute de `BACKUP_NOTIFICATION_EMAIL`. |
| `MAIL_FROM_NAME` | conseillé | vide : courriels sans nom d'expéditeur | Nom de l'expéditeur. Le défaut de la configuration ne s'applique pas : la composition transmet une chaîne vide. |

Détails :

- **Courriels envoyés** : vérification d'adresse ; lien qui ouvre un compte après l'inscription ; lien qui confirme une nouvelle adresse ; avis au titulaire d'une adresse qu'une inscription ou un autre compte a voulu prendre ; mot de passe oublié ; avis de changement d'adresse, envoyé à la dernière adresse vérifiée du compte ; alertes de santé ; notifications des sauvegardes manuelles.
- **Par `worker`** : l'inscription, le changement d'adresse et le lien de mot de passe oublié sont traités par `worker` (Horizon), d'où partent leurs courriels. Horizon arrêté, aucun compte ne se crée et aucun lien ne part, alors que la page a répondu.
- **`MAIL_SCHEME`** remplace `MAIL_ENCRYPTION`, que Laravel ne lit plus : une pile qui la pose encore n'en tire rien.
- **`MAIL_FROM_ADDRESS`** reçoit aussi les réponses : l'avis de changement d'adresse invite à y répondre qui n'est pas à l'origine du changement. Elle doit donc être lue.

### Notifications Web Push

| Variable | Obligatoire en production | Défaut | Rôle |
| --- | --- | --- | --- |
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` | pour les notifications | vides : aucune notification | Clés VAPID, générées une fois par `npx web-push generate-vapid-keys`. En changer invalide tous les abonnements existants. |
| `VAPID_SUBJECT` | non | `APP_URL` | Identité de l'expéditeur auprès du service push : une adresse `mailto:` ou une URL. |

- Sans la clé publique, la page Profil affiche « Le service de notifications n'est pas encore configuré sur le serveur. »
- La clé privée signe chaque envoi du worker.
- L'adresse d'un abonnement doit désigner `fcm.googleapis.com`, `updates.push.services.mozilla.com`, `push.apple.com` ou `notify.windows.com` (ou un de leurs sous-domaines), à l'enregistrement comme à l'envoi. Un abonnement stocké chez un autre hôte est ignoré.
- Le client de l'envoi attend 10 s au plus, 5 s pour se connecter, et ne suit aucune redirection.
- Un nouveau service push se déclare dans `PublicPushEndpoint::HOTES_ADMIS`.

### Sauvegardes

| Variable | Obligatoire en production | Défaut | Rôle |
| --- | --- | --- | --- |
| `BACKUP_ARCHIVE_PASSWORD` | oui, la pile refuse de démarrer | aucun | Mot de passe AES-256 des archives, transmis à `app`, `worker` et `scheduler`. |
| `BACKUP_HOST_PATH` | oui, la pile refuse de démarrer | aucun | Dossier de l'hôte monté sur `/app/storage/app/sauvegardes` dans `app`, `worker` et `scheduler`. |

- **`BACKUP_ARCHIVE_PASSWORD`** : sans lui, aucune archive n'est écrite, que la sauvegarde vienne du planificateur, du panneau ou de `backup:run` : une archive en clair sur une autre machine serait une fuite. Le perdre rend les archives illisibles.
- **`BACKUP_HOST_PATH`** : un dossier partagé d'une autre machine, monté sur l'hôte, jamais un volume Docker. Il doit être inscriptible par l'utilisateur du conteneur, `www-data` (uid 33), sans quoi aucune archive ne s'écrit.

### Connexion sociale

Un bouton n'apparaît qu'avec l'identifiant **et** le secret de son fournisseur ; vides, rien ne change. Apple accepte, à la place du secret, le trio qui le signe.

L'URL de rappel à déclarer chez le fournisseur est `APP_URL` suivie de `/auth/google/callback`, `/auth/github/callback` ou `/auth/apple/callback`. Un échange refusé par le fournisseur (`redirect_uri_mismatch`, `invalid_client`…) laisse une ligne « Connexion sociale refusée par le fournisseur » dans les journaux.

| Variable | Obligatoire en production | Défaut | Rôle |
| --- | --- | --- | --- |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | pour proposer Google | vides : bouton masqué | Client OAuth « Application Web » de la console Google Cloud. Transmises à `app` seul. |
| `GITHUB_CLIENT_ID`, `GITHUB_CLIENT_SECRET` | pour proposer GitHub | vides : bouton masqué | OAuth App de GitHub (Settings › Developer settings). Transmises à `app` seul. |
| `APPLE_CLIENT_ID` | pour proposer Apple | vide : bouton masqué | Le Services ID (`org.example.gym.web`), pas l'identifiant de l'app. Transmise à `app` seul. |
| `APPLE_TEAM_ID`, `APPLE_KEY_ID`, `APPLE_PRIVATE_KEY` | pour proposer Apple, à défaut d'`APPLE_CLIENT_SECRET` | vides : bouton masqué | L'identifiant d'équipe, celui de la clé, et le contenu du `.p8` en clair. Transmises à `app` seul. |
| `APPLE_CLIENT_SECRET` | non | vide | Un secret déjà signé (jeton ES256). Transmise à `app` seul. |

#### Apple

- **Le trio** (`APPLE_TEAM_ID`, `APPLE_KEY_ID`, `APPLE_PRIVATE_KEY`) :
  - `APPLE_PRIVATE_KEY` s'écrit en-têtes `BEGIN`/`END PRIVATE KEY` compris, sur une ligne, `\n` à la place des retours ;
  - l'application en signe un secret neuf, valable une heure, à chaque échange ;
  - les trois sont nécessaires : tant qu'il en manque une, la clé est ignorée et seul `APPLE_CLIENT_SECRET` peut servir.
- **`APPLE_CLIENT_SECRET`** n'est lu que quand le trio est incomplet : complet, il signe à sa place. Il expire au plus tard six mois après sa signature, et le bouton reste affiché après : chaque connexion échoue alors en `invalid_client`.
- **Chez Apple** (compte Apple Developer) : un Services ID avec « Sign in with Apple », le domaine d'`APP_URL` et l'URL de rappel déclarés, et une clé « Sign in with Apple » dont on télécharge le fichier `.p8`.
- **Le rappel** : Apple renvoie l'utilisateur par un POST depuis son site, sans cookie de session. Son rappel est la seule route exclue de la vérification CSRF. Un nonce, posé au départ dans un cookie chiffré `SameSite=None` et signé par Apple dans le jeton d'identité, le lie au navigateur parti. Ce cookie ne voyage qu'en HTTPS.

### Administration et supervision

| Variable | Obligatoire en production | Défaut | Rôle |
| --- | --- | --- | --- |
| `ADMIN_ALLOWED_IPS` | pour ouvrir le panneau | vide : le panneau, Pulse et le lecteur de journaux répondent 404, et Horizon reste fermé à l'administrateur du panneau (403) | Adresses autorisées sur le panneau et ses outils. Transmise à `app` seul. |
| `HORIZON_ALLOWED_EMAILS` | pour ouvrir Horizon à un compte de l'application | vide : fermé aux comptes de l'application | Adresses des comptes **utilisateurs** admis sur `/horizon`. Transmise à `app` seul. |
| `ADMIN_INITIAL_PASSWORD` | pour créer le premier administrateur | vide : le seeder échoue | Mot de passe du compte `admin@gymtracker.app`. Transmise à `app` seul. |
| `HEALTH_TO_ADDRESS` | non | vide : aucun courriel | Adresse qui reçoit un courriel quand un contrôle de santé passe au rouge. |
| `DIAGNOSTIC_ENABLED` | non | `false` | Ouvre l'accès de diagnostic en lecture seule. |
| `LOG_LEVEL` | non | `info` | Niveau minimal des journaux : `debug` pour un dépannage, `warning` pour n'écrire que les incidents. |
| `PULSE_ENABLED` | non | `false` | Laravel Pulse. |
| `SERVER_TIMING_ENABLED` | non | `false` | En-tête `Server-Timing` sur les réponses d'un utilisateur connecté ou d'un administrateur du panneau. |
| `SESSION_ENCRYPT` | non | `true` | Les sessions s'écrivent chiffrées dans Redis. |

#### `ADMIN_ALLOWED_IPS`

Adresses séparées par des virgules : adresses exactes ou plages CIDR, IPv4 et IPv6 (`198.51.100.0/24,203.0.113.32`). Elles sont autorisées sur :

- le panneau `/backoffice` ;
- Pulse (`/backoffice/pulse`) et le lecteur de journaux (`/backoffice/journaux`, API comprise), qui vivent sous lui ;
- l'ouverture d'Horizon (`/horizon`) par l'administrateur du panneau.

#### `HORIZON_ALLOWED_EMAILS`

Adresses séparées par des virgules.

- Ces comptes n'entrent sur `/horizon` qu'une fois leur adresse confirmée par le lien de vérification. Ils ne passent pas par `ADMIN_ALLOWED_IPS`.
- Une session que le changement de leur mot de passe a fermée n'y entre plus.
- L'administrateur du panneau qui voit le lien « Horizon » de son menu (capacité `view-outils`, celle du super administrateur) n'a pas à y figurer : il y entre avec sa session du panneau, tant qu'un changement de son mot de passe ne l'a pas invalidée, depuis une adresse de `ADMIN_ALLOWED_IPS`.

#### `ADMIN_INITIAL_PASSWORD`

- Le compte est créé par `php artisan db:seed --class=AdminSeeder --force`, dans le conteneur `app` (`docker exec`). Voir [Déploiement](deploiement.md#créer-le-premier-administrateur).
- Le seeder ne réécrit jamais un mot de passe existant : retirer la variable de la pile une fois le compte créé.
- Le compte enrôle sa double authentification à sa première connexion (voir [Sécurité](securite.md#double-authentification-du-panneau)).

#### `HEALTH_TO_ADDRESS`

- Un courriel part une fois par heure au plus.
- Les contrôles et leurs libellés sont décrits dans [Exploitation](exploitation.md#contrôles-de-santé).
- Les contrôles tournent dans le planificateur toutes les cinq minutes : `scheduler` arrêté, aucun courriel ne part.
- Vide, la page « Santé » du panneau reste seule.

#### `DIAGNOSTIC_ENABLED`

Tant qu'il est faux, aucun jeton ne se crée, et chaque démarrage de `app` révoque ceux qui restent. Les conditions d'ouverture, les alertes et le comportement des routes sont dans [Diagnostic](diagnostic.md#ouvrir-laccès).

#### `PULSE_ENABLED`

- Pulse écrit ses agrégats en base à chaque requête et chaque job. En production, cela provoquait un convoi de verrous (voir [Mesurer la base](exploitation.md#mesurer-la-base)).
- `/backoffice/pulse` reste consultable par le super administrateur, depuis une adresse de `ADMIN_ALLOWED_IPS`, sans nouvelles données tant qu'il est coupé : ses cartes disent « No results ».
- Les sauvegardes gardent ses tables, pas leurs lignes.

#### `SERVER_TIMING_ENABLED`

- L'en-tête porte les durées de l'application et de ses étapes, des requêtes SQL et des lectures du cache (voir [Relever ce que coûte chaque écran](releve-des-temps.md#ce-que-porte-len-tête)).
- Il est lisible dans l'onglet Réseau du navigateur et par le relevé des temps de la page, qui ne s'arme que si l'en-tête arrive.
- Jamais pour un invité, ni sur une réponse d'authentification ou un 404 : une durée ou un nombre dirait si un compte ou une ressource existe.
- Seule une valeur vraie (`true`, `1`) l'allume ; vide ou absente le coupe.
- À allumer le temps d'un relevé, puis à couper.

#### `SESSION_ENCRYPT`

- Les sessions s'écrivent chiffrées dans Redis (clé `APP_KEY`, comme les cookies). Elles portent la clé des copies hors ligne, qu'un accès en lecture à Redis ne doit pas livrer.
- `false` ne sert qu'à revenir en arrière. Seuls `false`, `(false)`, `0`, `off` et `no` éteignent le chiffrement (casse et espaces ignorés) ; une valeur vide ou mal écrite le laisse.
- Dans les deux sens, une session écrite dans l'autre format se lit comme vide : son titulaire en ouvre une neuve, une fois (voir [La mise à niveau](mise-a-niveau.md#sessions-chiffrées-et-mots-de-passe-hors-des-lignes-de-commande)).

## Fixées par la composition

Écrites en dur dans `docker-compose.prod.yml` : les poser dans la pile ne change rien. L'image pose de son côté `APP_ENV=production`, `APP_DEBUG=false` et `SERVER_NAME=:80`. Cette dernière est sans effet, le Caddyfile de l'application lisant l'adresse qu'Octane lui passe.

| Variable | Valeur | Rôle |
| --- | --- | --- |
| `APP_ENV` | `production`, aussi posée par le `Dockerfile` | Cookie de session réservé à HTTPS, panneau fermé sans `ADMIN_ALLOWED_IPS`, Horizon fermé hors liste et hors administrateur du panneau, mots de passe à casse mixte et absents des fuites connues. Telescope, dépendance de développement, n'est pas dans l'image. |
| `ASSET_URL` | la valeur d'`APP_URL` | Les actifs se servent depuis l'adresse publique. |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT` | `mysql`, `db`, `3306` | Le service `db` ; `entrypoint.sh` l'attend jusqu'à vingt minutes avant d'abandonner. |
| `REDIS_HOST`, `REDIS_PORT` | `redis`, `6379` | Le service `redis` : l'instance des données (file, Horizon, sessions, verrous, limiteurs, battements de santé), en `noeviction`, persistée dans `redis_data`. |
| `REDIS_CACHE_HOST`, `REDIS_CACHE_PORT` | `redis-cache`, `6379` | Le service `redis-cache` : l'instance du cache, en `allkeys-lru`, sans persistance ni volume (elle repart vide). Le mot de passe est celui de `REDIS_PASSWORD`. Absentes, la connexion `cache` retombe sur `REDIS_HOST` et `REDIS_PORT` : Sail et la CI gardent une seule instance. |
| `CACHE_DURABLE_STORE` | `redis-durable` | Le magasin des limiteurs de tentatives et des battements de « File d'attente » et « Planificateur » : la connexion des données, que le cache à part ne vide pas. Vide, il suit le magasin par défaut. |
| `VERSION_ATTENDUE_DE_MYSQL`, `VERSION_ATTENDUE_DE_REDIS` | l'étiquette de `image:` de `db` et de `redis`, sans sa variante : `8.4` et `8.10.3` | Les versions que « Versions des services » attend des deux serveurs. Elles montent avec l'étiquette, dans la même PR (`LesVersionsAttenduesSuiventLesImagesTest`). Une pile qui ne les a pas n'a pas reçu la composition de la release. |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | `redis` | Sessions, cache, résultats des contrôles de santé, versions annoncées par les conteneurs et file d'Horizon, partagés par les trois conteneurs de l'application. |
| `MAIL_MAILER` | `smtp` | Les courriels partent par le serveur de `MAIL_HOST`. |
| `GOMAXPROCS` | `2` | Plafonne les threads Go de FrankenPHP dans `app`. `worker` et `scheduler`, en PHP CLI, l'ignorent. |
| `OCTANE_SERVER` | `frankenphp`, dans `app` seulement | Serveur que visent `octane:status` et `octane:reload`. L'image lance `octane:frankenphp` directement. |
| `LOG_CHANNEL`, `LOG_STACK` | `stack`, `stderr,daily` | Chaque ligne va dans `docker logs` et dans le fichier du jour du conteneur, dans le volume `journaux` (voir [Journaux](exploitation.md#journaux)). |
| `LOG_DAILY_NAME` | `app`, `worker` ou `scheduler`, selon le service | Nom du fichier de journal du conteneur : la page « Journaux » du panneau dit ainsi qui a écrit quoi. |
| `PHP_INI_SCAN_DIR` | `:/app/docker/php-cli`, dans `worker` et `scheduler` seulement | Charge `docker/php-cli/zz-cli.ini` après les ini de l'image : OPcache actif en ligne de commande, sans JIT, avec un cache fichier dans /tmp/opcache que l'image crée. |
| `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD` | `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_ROOT_PASSWORD` | Variables de l'image mysql du service `db`, lues à l'initialisation d'un volume vide. |

À propos de `PHP_INI_SCAN_DIR` :

- Chaque commande artisan, tâche planifiée et sonde de santé reprend ainsi le code déjà compilé au lieu de tout recompiler.
- Le `:` de tête garde le dossier par défaut de l'image (extensions, `docker/php-prod.ini`).
- Posée dans `app`, elle couperait le JIT du serveur web.
- Le fichier répète aussi `zend.exception_ignore_args`, que pose `docker/php-prod.ini` : aucune pile d'exception ne garde les arguments de ses appels.

## Fixées par l'image

Posées par le `Dockerfile` à partir des arguments que la CI passe au build : les poser dans la pile ne change rien. Un conteneur dit ainsi l'image qu'il exécute, pas celle qu'on croit avoir déployée.

| Variable | Valeur | Rôle |
| --- | --- | --- |
| `APP_VERSION` | le tag construit (`v1.5.20`), `main` sur `main` ; `dev` hors CI | Version que chaque conteneur annonce à son démarrage, la même que l'étiquette `org.opencontainers.image.version` de l'image. Le contrôle « Versions des conteneurs » compare celles d'`app`, `worker` et `scheduler`. |
| `APP_REVISION` | le commit construit ; `inconnue` hors CI | Révision annoncée avec la version : deux images de `main` portent la même version et ne diffèrent que par elle. |

## Lues par l'application, non transmises en production

`config/` les lit, mais la composition ne les transmet pas : la production applique leur défaut, quoi qu'on pose dans la pile. Pour en régler une, l'ajouter à `docker-compose.prod.yml` avec un défaut (`${NOM:-valeur}`), puis la déplacer dans le tableau « Production ».

| Variable | Défaut appliqué | Effet |
| --- | --- | --- |
| `APP_NAME` | `GymTracker` ; `laravel` pour les préfixes | Nom de l'application et du dossier des archives que vérifie la page « Santé ». |
| `APP_TIMEZONE` | `Europe/Paris` | Fuseau de l'application et des heures du planificateur : rappel d'entraînement à 18 h, sauvegarde à 01 h 55. |
| `SESSION_SECURE_COOKIE` | `true` en production | Le cookie de session n'est envoyé qu'en HTTPS : d'où le passage obligé par le proxy inverse. |
| `SESSION_LIFETIME` | `120` | Minutes d'inactivité avant que la session expire. « Se souvenir de moi » reconnecte ensuite sans mot de passe. |
| `BACKUP_PATH` | `/app/storage/app/sauvegardes` | Racine du disque des archives, exactement la cible du montage de `BACKUP_HOST_PATH`. À ne pas transmettre : une autre valeur écrirait les archives dans le conteneur, hors du partage. |
| `BACKUP_NOTIFICATION_EMAIL` | `MAIL_FROM_ADDRESS` | Destinataire des notifications de sauvegarde. Les sauvegardes planifiées les coupent (`--disable-notifications`) : seules celles lancées à la main, du panneau ou par `backup:run`, écrivent. |
| `AUTH_TIMEBOX_DURATION` | `1000000` (une seconde, en microsecondes) | Durée minimale des réponses d'authentification. |
| `HORIZON_HEARTBEAT_URL`, `SCHEDULE_HEARTBEAT_URL` | vides | URL qu'un contrôle réussi d'Horizon ou du planificateur appellerait, pour qu'une surveillance externe s'alarme quand les appels cessent. Cela couvre le planificateur arrêté, que `HEALTH_TO_ADDRESS` ne peut pas signaler. |
| `GOOGLE_REDIRECT_URI`, `GITHUB_REDIRECT_URI`, `APPLE_REDIRECT_URI` | `APP_URL` suivie de `/auth/google/callback`, `/auth/github/callback` ou `/auth/apple/callback` | L'URL de rappel à déclarer chez le fournisseur. |

#### `APP_NAME`

Les préfixes des clés Redis, du cache et d'Horizon et le nom du cookie de session se calculent sur `laravel`, pas sur `APP_NAME`. Transmettre `APP_NAME` les changerait tous : déconnexion générale, cache et métriques d'Horizon repartis de zéro.

#### `SESSION_SECURE_COOKIE`

Elle décide de trois choses :

| Valeur | Cookie de session | Témoin des copies hors ligne |
| --- | --- | --- |
| vraie | `__Host-<app>-session`, sans `SESSION_DOMAIN` et avec `SESSION_PATH=/` (le navigateur refuse `__Host-` autrement) | `__Host-gt_copies`, avec l'attribut `Secure` |
| fausse (Dusk, développement) | le nom ordinaire | `gt_copies`, sans `Secure`, même servi en HTTPS |

Le témoin des copies hors ligne suit cette variable, et non le protocole de la requête.

Ses effets au déploiement (sessions fermées, copies effacées) sont dans [la mise à niveau](mise-a-niveau.md#sessions-et-cookies--tout-le-monde-se-reconnecte-une-fois).

#### `SESSION_LIFETIME`

- Elle fixe aussi l'échéance du témoin des copies hors ligne, repoussée à chaque réponse. Avec `SESSION_EXPIRE_ON_CLOSE` vraie (fausse par défaut), il finit, comme le cookie de session, avec le navigateur. Dans les deux cas, douze heures quand l'appareil se souvient du compte.
- Elle borne aussi le temps pendant lequel le serveur se souvient qu'une session a été fermée.

#### `AUTH_TIMEBOX_DURATION`

Elle fait que les réponses d'authentification ne durent pas plus longtemps quand l'adresse a un compte.

- **Ce qui est retenu** : toute inscription ou demande de lien de mot de passe oublié, et tout refus de connexion (site et panneau) ou de réinitialisation, lien de réinitialisation refusé dès l'ouverture de sa page compris.
- **Ce qui répond sans attendre** : une connexion ou une réinitialisation réussie sur le site, l'ouverture d'un lien de réinitialisation qui vaut, le refus du limiteur de la connexion, et la demande de changement d'adresse du profil, qui ne lit pas l'adresse demandée.
- **Au panneau**, chaque étape de la connexion, mot de passe puis code de double authentification, dure le plancher, réussie ou non : Filament ne dit pas ainsi quel compte a activé la double authentification.
- **Elle doit dépasser** un hachage bcrypt au coût de `BCRYPT_ROUNDS` (environ 0,25 s à 12, le double à chaque unité) : relever les deux ensemble au-delà de 13.
- **Coût** : chaque réponse ainsi retenue occupe l'un des quatre processus de `app` (`--workers=4` du Dockerfile : une attente ne consomme pas de processeur, seulement un processus) jusqu'au bout.
- **Limites** : le limiteur des routes d'authentification, commun à toutes, n'en laisse que six par minute à une adresse IP. Celui de l'ouverture du lien de réinitialisation, à part parce qu'un envoi refusé du formulaire rouvre la page, en laisse douze de plus. Le panneau, ouvert aux seules adresses d'`ADMIN_ALLOWED_IPS`, a le sien : cinq essais par minute. Des refus venus de nombreuses adresses IP ralentissent pourtant tout le site.
- Une valeur vide ou non numérique donne le défaut. `phpunit.xml` la met à 0, et les tests de durée reposent la seconde.

## Front : variables de build

Vite recopie les variables `VITE_*` dans les fichiers servis au navigateur : elles sont **publiques** et ne portent jamais de secret. L'image se construit sans `.env` : en production, chacune prend le repli du code.

| Variable | Obligatoire en production | Défaut | Rôle |
| --- | --- | --- | --- |
| `VITE_APP_NAME` | non, et non réglable : figée au build de l'image | `GymTracker` | Suffixe du titre de chaque page. En développement, `.env.example` la recopie d'`APP_NAME`. |

## Développement local

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
| `AUTH_TIMEBOX_DURATION` | commentée | Durée minimale des réponses d'authentification, une seconde par défaut ; phpunit.xml la met à 0. Voir « Lues par l'application, non transmises en production ». |
| `LOG_CHANNEL`, `LOG_STACK`, `LOG_LEVEL`, `LOG_DEPRECATIONS_CHANNEL` | `stack`, `single`, `debug`, `null` | Journaux dans storage/logs/laravel.log, dépréciations ignorées. Avec `LOG_STACK=daily`, `LOG_DAILY_NAME` (défaut `laravel`) nomme le fichier du jour. |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | `mysql`, `mysql`, `3306`, `gym_tracker`, `sail`, `password` | Le MySQL de Sail, qui crée la base et l'utilisateur au premier démarrage du volume. Les tests gardent l'hôte mais visent la base `gym_tracker_testing`, les parcours navigateur la base `gym_tracker_dusk` par `.env.dusk.local` ; le premier démarrage du volume crée les deux. |
| `SESSION_DRIVER`, `SESSION_LIFETIME`, `SESSION_ENCRYPT`, `SESSION_PATH`, `SESSION_DOMAIN` | `database`, `120`, `true`, `/`, `null` | Sessions en base, chiffrées comme en production. `SESSION_PATH` et `SESSION_DOMAIN` décident, avec `SESSION_SECURE_COOKIE`, du préfixe `__Host-` du cookie de session. |
| `CACHE_STORE`, `CACHE_PREFIX`, `QUEUE_CONNECTION`, `FILESYSTEM_DISK` | `database`, commentée, `database`, `local` | Cache et file en base, fichiers sur le disque local. Les tâches en file attendent `queue:listen` (lancé par `sail composer dev`) : Horizon ne sert que la connexion `redis`. |
| `REDIS_CLIENT`, `REDIS_HOST`, `REDIS_PASSWORD`, `REDIS_PORT` | `phpredis`, `redis`, `null`, `6379` | Le Redis de Sail, dont Horizon a besoin. |
| `MAIL_MAILER`, `MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Mailpit | Les courriels arrivent dans Mailpit, sur le port 8025. |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`, `GITHUB_CLIENT_ID`, `GITHUB_CLIENT_SECRET`, `GITHUB_REDIRECT_URI`, `APPLE_CLIENT_ID`, `APPLE_TEAM_ID`, `APPLE_KEY_ID`, `APPLE_PRIVATE_KEY`, `APPLE_CLIENT_SECRET`, `APPLE_REDIRECT_URI` | vides, URL de rappel dérivées d'`APP_URL` | Connexion sociale : un bouton n'apparaît qu'avec l'identifiant et le secret de son fournisseur, ou pour Apple le trio qui signe son secret. L'URL de rappel à déclarer chez lui est `APP_URL` suivie de `/auth/{fournisseur}/callback`. Apple n'accepte qu'une URL de rappel en HTTPS, et son cookie de nonce n'en revient qu'en HTTPS. |
| `OCTANE_SERVER` | `frankenphp` | Serveur que visent les commandes `octane:*` ; Sail, lui, sert l'application par `artisan serve`. |
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` | vides | Comme en production : sans clés, pas de notifications. |
| `SERVER_TIMING_ENABLED` | `false` | `true` ajoute l'en-tête `Server-Timing` aux pages d'un compte connecté, comme en production : le temps serveur se lit dans l'onglet réseau du navigateur, et le relevé des temps de la page s'arme (`releverLesTemps()` dans la console, `#releve-des-temps` à l'écran). |
| `APP_PORT` | `80` | Port de l'hôte publié par Sail. |
| `DUSK_DRIVER_URL` | `http://selenium:4444/wd/hub` | Le Selenium de Sail, pour `sail artisan dusk`. Le navigateur tourne alors dans le conteneur `selenium` : `APP_URL` doit y désigner l'application, ce que ne font ni `localhost` ni `127.0.0.1` (voir « Parcours navigateur » dans [Développement](developpement.md#parcours-navigateur)). |
| `ADMIN_ALLOWED_IPS`, `HORIZON_ALLOWED_EMAILS`, `ADMIN_INITIAL_PASSWORD`, `HEALTH_TO_ADDRESS`, `BACKUP_ARCHIVE_PASSWORD` | vides | Celles de la production. Vides en local : panneau et Horizon ouverts, aucun courriel de santé, et une sauvegarde échoue faute de mot de passe d'archive. |

`compose.yaml` lit aussi des réglages propres à Sail, à ajouter au `.env` au besoin :

- `FORWARD_DB_PORT`, `FORWARD_REDIS_PORT`, `FORWARD_MAILPIT_PORT`, `FORWARD_MAILPIT_DASHBOARD_PORT` ;
- `VITE_PORT` ;
- `SAIL_XDEBUG_MODE`, `SAIL_XDEBUG_CONFIG` ;
- `MYSQL_EXTRA_OPTIONS`.

Le script sail pose lui-même `WWWUSER` et `WWWGROUP`.

## Secrets de CI

| Secret | Obligatoire | Rôle |
| --- | --- | --- |
| `GITHUB_TOKEN` | fourni par GitHub | Publie l'image sur ghcr.io, crée les releases, ouvre les issues d'échec de `main`, ferme les issues inactives, et crée les labels que `.github/dependabot.yml` demande et qui manquent sur le dépôt (`.github/workflows/labels-dependabot.yml`). Une fusion ou une PR faites avec lui ne déclenchent aucun workflow. |
| `AUTO_MERGE_TOKEN` | non | Jeton personnel à granularité fine du job `dependabot` de `.github/workflows/auto-merge.yml`. Une fusion faite avec lui relance la CI sur `main`, ce que `GITHUB_TOKEN` ne fait pas. Absent, le workflow retombe sur `GITHUB_TOKEN`. |
| `AVIS_HORS_LIGNE_TOKEN` | non | Jeton personnel limité à ce dépôt (Contents et Pull requests en lecture et écriture, surtout pas Workflows), qui laisse `.github/workflows/avis-hors-ligne.yml` ouvrir lui-même la PR de rafraîchissement de `roave/security-advisories`, avec une CI qui tourne. Absent, le workflow se contente d'avertir, puis échoue passé quatorze jours. |
