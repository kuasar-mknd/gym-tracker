# Déployer et mettre à jour la pile de production

Cette page dit comment mettre l'application en production, la mettre à jour et revenir en arrière. Le [README](../README.md) en donne la version courte ; ici, le détail et les vérifications.

| Je veux… | Section |
| --- | --- |
| Installer la production pour la première fois | [Premier déploiement](#premier-déploiement) |
| Passer à la release suivante | [Mettre à jour la pile](#mettre-à-jour-la-pile) |
| Défaire une mise à jour | [Revenir en arrière](#revenir-en-arrière) |
| Brancher ou vérifier le proxy inverse | [Proxy inverse et port de l'application](#proxy-inverse-et-port-de-lapplication) |
| Comprendre pourquoi la pile est faite ainsi | [La pile de production](pile-de-production.md) |
| Savoir ce que la prochaine version change | [Mise à niveau](mise-a-niveau.md) |

## Avant de commencer

Il faut :

- un hôte qui fait tourner Docker Compose ;
- un proxy inverse HTTPS sur ce même hôte ;
- un dossier de l'hôte pour les sauvegardes, inscriptible par l'utilisateur de l'uid 33 (`www-data`), qui sera `BACKUP_HOST_PATH` ;
- un relais SMTP ;
- des clés VAPID, si vous voulez les notifications (`npx web-push generate-vapid-keys`).

La production suit l'image `ghcr.io/kuasar-mknd/gym-tracker:v1`, publiée quand un tag `v*` est poussé. `v1` suit chaque v1.x.y.

## Premier déploiement

1. **Poser les variables** dans la pile, ou dans un fichier `.env` à côté de `docker-compose.prod.yml`.
   - Poser au moins celles que la colonne « Obligatoire en production » de [la référence](variables-d-environnement.md#production--à-poser-dans-la-pile) marque `oui`.
   - Pour `APP_KEY` : `echo "base64:$(openssl rand -base64 32)"`.
   - Pour `ADMIN_INITIAL_PASSWORD`, voir l'étape 4.
   - Pas d'apostrophe ni d'antislash dans les mots de passe de MySQL.
   - Sans `BACKUP_ARCHIVE_PASSWORD`, `BACKUP_HOST_PATH` ou `REDIS_PASSWORD`, la pile ne démarre pas.
2. **Lancer la pile** :

   ```bash
   docker compose -f docker-compose.prod.yml up -d
   ```

   Au démarrage, `app` joue les migrations. Une migration qui échoue l'arrête.
3. **Placer le proxy inverse HTTPS** (voir [Proxy inverse](#proxy-inverse-et-port-de-lapplication)).
4. **Créer le premier administrateur** (voir [Créer le premier administrateur](#créer-le-premier-administrateur)).
5. **Vérifier** :
   - `docker compose -f docker-compose.prod.yml ps` : tous les services `healthy` ;
   - le site, par son adresse HTTPS ;
   - la page « Santé » du panneau.

### Créer le premier administrateur

1. Vérifier que `ADMIN_INITIAL_PASSWORD` est posée dans la pile. Si on l'ajoute après coup, recréer `app` d'abord : la configuration est figée au démarrage.
2. Lancer le seeder :

   ```bash
   docker compose -f docker-compose.prod.yml exec app php artisan db:seed --class=AdminSeeder --force
   ```

3. Retirer `ADMIN_INITIAL_PASSWORD` de la pile, puis recréer `app`.

Le compte est `admin@gymtracker.app`. Le panneau est à `/backoffice`, ouvert aux adresses de `ADMIN_ALLOWED_IPS`. Il exige une double authentification, enrôlée à la première connexion (voir [Sécurité](securite.md#double-authentification-du-panneau)).

## Mettre à jour la pile

À chaque release :

1. Recevoir dans la pile la composition du tag de la release (`docker-compose.prod.yml`).
2. Lire [la mise à niveau](mise-a-niveau.md), qui dit ce que la version fait au déploiement, et suivre ses manœuvres.
3. À une heure creuse, mettre la pile à jour :

   ```bash
   docker compose -f docker-compose.prod.yml up -d
   ```

   Vous pouvez aussi utiliser la mise à jour de la pile de votre outil. L'image est retéléchargée, et les conteneurs dont l'image a changé sont recréés.
4. Vérifier :
   - `docker compose -f docker-compose.prod.yml ps` : `db`, `redis`, `redis-cache` et `redis-sessions` en `healthy` ;
   - la page « Santé » reste verte. « Versions des conteneurs » et « Versions des services » disent si les conteneurs et les services servent la version attendue.

Ce qu'il faut savoir :

- **La base et Redis ne suivent pas `v1`.** `db` et `redis` lancent l'étiquette de la composition (`mysql:8.4`, `redis:8.10.3-alpine`). C'est pourquoi la pile doit recevoir la composition de la release à chaque fois.
- **Une release qui monte `redis`** (`redis-cache` et `redis-sessions` portent la même étiquette et suivent la même marche, `redis-sessions` avec son volume `redis_sessions_data`) se déploie par la marche « Redis » de `.ai/rules/dependencies.md`, à une heure creuse :
  1. arrêter l'application, puis Redis ;
  2. copier le volume de données de `redis` ;
  3. mettre à jour, puis vérifier ;
  4. au besoin, revenir par la copie.

  La version d'avant ne relit plus le volume une fois l'AOF réécrit par la nouvelle.
- **Une bascule de MySQL** a sa propre marche, dans le même fichier.

## Revenir en arrière

1. Remettre la composition de la release d'avant.
2. Épingler l'image de `app`, `worker` et `scheduler` sur la version exacte d'avant : `ghcr.io/kuasar-mknd/gym-tracker:X.Y.Z` à la place de `:v1`, qui suit la dernière version.
3. Relancer la pile :

   ```bash
   docker compose -f docker-compose.prod.yml up -d --remove-orphans
   ```

   Sans `--remove-orphans`, `redis-cache` et `redis-sessions` restent lancés.

Ce que le retour ne défait pas :

- **Une release qui monte `redis` ou MySQL** ne se défait pas ainsi : le volume n'est plus relu par la version d'avant. Le retour passe par la copie du volume prise avant la mise à jour.
- **Les migrations déjà jouées** restent en place. L'ancienne image relance `migrate --force`, qui ne fait rien, et tourne sur le schéma plus récent. Une migration destructrice rend le retour impossible sans restaurer la sauvegarde.
- **Certains effets d'une mise à niveau** n'ont pas de retour : sessions fermées, double authentification, clé de l'application remplacée. [La mise à niveau](mise-a-niveau.md#retour-arrière) dit lesquels.

## Proxy inverse et port de l'application

L'application s'ouvre par le proxy inverse HTTPS, jamais directement sur le port 8888. Les raisons sont dans [La pile de production](pile-de-production.md#le-proxy-inverse-et-le-port-de-lapplication).

### Ce que le proxy doit faire

| Exigence | Détail |
| --- | --- |
| Tourner sur le même hôte | `app` n'écoute que sur la boucle locale (`127.0.0.1:8888:80`). |
| Viser `localhost:8888` | |
| Servir en HTTPS | Le cookie de session est réservé à HTTPS : une visite en http ne garde aucune session, la connexion échoue. |
| Transmettre `X-Forwarded-Proto` | Sans l'en-tête, l'historique du navigateur reste en clair. |

### Si le proxy est sur un autre hôte

1. Remettre `8888:80` à la place de `127.0.0.1:8888:80` dans la composition.
2. Régler `TRUSTED_PROXIES` sur l'adresse du proxy.
3. Si `TRUSTED_PROXIES` est resserré, y mettre l'adresse de la passerelle du réseau de conteneurs, pas seulement l'adresse du réseau local : le conteneur voit la passerelle du pont, ni `127.0.0.1` ni l'adresse de l'hôte.

### Vérifier le port local

À faire une fois, quand le port passe sur la boucle locale (voir [la mise à niveau](mise-a-niveau.md#port-de-lapplication-sur-la-boucle-locale)), avec un proxy inverse sur le même hôte. Dans l'ordre :

1. Ouvrir le site par son adresse HTTPS habituelle, donc par le proxy inverse. Résultat attendu : la page de connexion répond.
2. Depuis un autre appareil du réseau local, ouvrir `http://IP-DE-L-HÔTE:8888`. Résultat attendu : la connexion est refusée ou expire, sans réponse de l'application.

Si l'étape 1 échoue : le proxy inverse ne joint pas le port. Remettre `8888:80` dans la composition et redéployer ouvre le port au réseau local, donc seulement si le proxy est sur un autre hôte (voir ci-dessus) ; sinon, corriger le proxy pour qu'il vise `localhost:8888`.
