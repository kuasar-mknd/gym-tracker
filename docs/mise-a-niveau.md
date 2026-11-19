# Mise à niveau : ce que la prochaine version change au déploiement

Cette page dit ce que la version qui suit v1.7.0 fait une seule fois, au déploiement, et ce qu'il faut faire.

À lire avant `docker compose -f docker-compose.prod.yml up -d`, et à déployer à une heure creuse. La composition de la release est à poser avant la mise à jour de la pile (voir [Déploiement](deploiement.md#mettre-à-jour-la-pile)).

## En bref

| Changement | Ce que les gens voient | Ce que vous faites | Retour possible |
| --- | --- | --- | --- |
| [Cookie de session `__Host-`](#sessions-et-cookies--tout-le-monde-se-reconnecte-une-fois) | Tout le monde se reconnecte une fois ; les copies hors ligne s'effacent | rien | non |
| [Sessions chiffrées](#sessions-chiffrées-et-mots-de-passe-hors-des-lignes-de-commande) | Les sessions en clair se lisent comme vides : une reconnexion | une manœuvre en trois commandes | oui |
| [Double authentification du panneau](#double-authentification-obligatoire-au-panneau) | Les administrateurs sont déconnectés une fois et enrôlent leur application | rien | non |
| [Résolution DNS bornée](#résolution-dns-bornée--app-worker-et-scheduler-sont-recréés) | rien | rien : `app`, `worker` et `scheduler` sont recréés | oui |
| [Port sur la boucle locale](#port-de-lapplication-sur-la-boucle-locale) | rien, si le proxy inverse est sur le même hôte | vérifier le proxy | oui |
| [Clé `APP_KEY` publiée](#remplacer-une-clé-app_key-publiée) | rien, tant qu'on ne la remplace pas | remplacer la clé si le contrôle est rouge | non |
| [Deux instances de Redis](#depuis-une-version-davant-les-deux-instances-de-redis) | rien | une bascule en trois commandes | oui |

## Sessions et cookies : tout le monde se reconnecte une fois

Le cookie de session prend le préfixe `__Host-` (`SESSION_SECURE_COOKIE` vrai en production). Au déploiement :

- **Toutes les sessions se ferment une fois.**
- Les cookies `appareil_connu` ne valent plus rien : le plafond d'échecs de connexion par adresse s'applique de nouveau jusqu'à la prochaine connexion de chaque appareil.
- La clé des copies hors ligne vit dans la session (`CleDesCopiesEnSession`) : **les copies hors ligne de chaque appareil s'effacent une fois**, à la première vérification de la page « hors ligne ». Les modifications qui attendaient leur envoi restent pour le retour du compte.
- Les appareils qui se reconnectent ensemble depuis une même adresse comptent dans le plafond de sessions neuves par minute et par adresse.

Rien à faire. Pas de retour possible.

## Sessions chiffrées et mots de passe hors des lignes de commande

`SESSION_ENCRYPT` vaut `true` par défaut. La composition change aussi la sonde de `db` et la commande de `redis` et `redis-cache` : `up -d` les recrée.

### Ce que voient les utilisateurs

- Une session écrite en clair se lit comme vide : son titulaire devient un invité **une seule fois**. Une page du compte le renvoie vers la connexion, sa session s'écrit chiffrée sous le même identifiant, sans erreur.
- L'appareil qui se souvient du compte (cookie de rappel) se reconnecte seul.
- La clé des copies hors ligne de la session est neuve : les copies d'avant s'effacent à la première vérification de la page « hors ligne ». Les modifications qui attendaient leur envoi restent pour le retour du compte (voir [Hors ligne](hors-ligne.md#modifications-en-attente)).
- Les sessions en clair restent dans Redis (et dans son AOF, jusqu'à sa prochaine réécriture) jusqu'à `SESSION_LIFETIME` minutes sans visite, ou jusqu'à ce que leur titulaire revienne.
- Une rotation d'`APP_KEY` sans `APP_PREVIOUS_KEYS` ferme de même toutes les sessions.

### La manœuvre

Une seule fois, à une heure creuse, avec la composition de la release :

1. Arrêter `worker` et `scheduler`, et mettre l'application en maintenance :

   ```bash
   docker compose -f docker-compose.prod.yml stop worker scheduler
   docker compose -f docker-compose.prod.yml exec app php artisan down
   ```

2. Mettre la pile à jour, en laissant à MySQL le temps de s'arrêter proprement :

   ```bash
   docker compose -f docker-compose.prod.yml up -d -t 300
   ```

   `up -d` recrée :
   - `db` : sa sonde a changé. MySQL s'arrête proprement (`stop_grace_period` de cinq minutes) et repart sur le même volume.
   - `redis` et `redis-cache` : leur commande a changé. `redis` écrit son AOF en s'arrêtant et la relit (les sessions et la file y sont). `redis-cache` repart vide, comme toujours.
   - `app`, `worker` et `scheduler`.

   Les variables de la pile, `REDIS_PASSWORD` comprise, ne changent pas.
3. Si `up -d` n'a pas recréé `app`, le rouvrir :

   ```bash
   docker compose -f docker-compose.prod.yml exec app php artisan up
   ```

### Vérifier

- `docker compose -f docker-compose.prod.yml ps` montre `db`, `redis` et `redis-cache` en `healthy`.
- `docker inspect <projet>-redis-1 --format '{{json .Config.Cmd}} {{json .Config.Healthcheck}}'` (et `<projet>-db-1`) ne montre aucun mot de passe.
- `docker compose -f docker-compose.prod.yml exec redis redis-cli ping` répond `PONG`.
- « Réglages de Redis » et « Versions des services », dans « Santé », restent verts.
- La connexion marche. Seules les personnes sans « Se souvenir de moi » se reconnectent une fois.

Si `redis` ne démarre pas, `docker logs <projet>-redis-1` le dit. Le plus probable : un retour à la ligne dans `REDIS_PASSWORD`.

### Revenir en arrière

| Ce qu'on défait | Comment |
| --- | --- |
| Les sessions seules | `SESSION_ENCRYPT=false` dans la pile, puis `up -d`. Les sessions chiffrées se lisent alors comme vides : chacun se reconnecte une fois de plus. |
| Tout | La composition d'avant et `up -d`, même suite de commandes. |

## Double authentification obligatoire au panneau

**Tous les administrateurs sont déconnectés une fois.** La session porte la preuve du second facteur, et « Se souvenir de moi » ne rouvre plus le panneau sans le code.

- Chacun enrôle son application de codes à sa prochaine connexion : QR code, puis codes de récupération à noter, affichés une seule fois.
- Un administrateur qui a tout perdu : `php artisan admin:reinitialiser-mfa adresse@example.org`, dans le conteneur `app`.
- Le détail est dans [Sécurité](securite.md#double-authentification-du-panneau).

Rien à faire. Pas de retour possible : on ne redevient pas sans second facteur d'un geste, et `admin:reinitialiser-mfa` efface un secret.

## Résolution DNS bornée : `app`, `worker` et `scheduler` sont recréés

Ils portent `dns_opt: [timeout:2, attempts:2]`. `docker compose -f docker-compose.prod.yml up -d` les recrée.

Rien d'autre à faire, et aucune variable n'est ajoutée. Retour : la composition d'avant. La raison est dans [La pile de production](pile-de-production.md#la-résolution-dns-bornée).

## Port de l'application sur la boucle locale

`app` publie `127.0.0.1:8888:80` au lieu de `8888:80`.

1. Le proxy inverse doit tourner sur le même hôte et viser `localhost:8888`.
2. Après `up -d`, faire la vérification de [Déploiement](deploiement.md#vérifier-le-port-local).

Si le proxy est sur un autre hôte : remettre `8888:80` et régler `TRUSTED_PROXIES` sur son adresse (voir [Déploiement](deploiement.md#si-le-proxy-est-sur-un-autre-hôte)). Le proxy transmet `X-Forwarded-Proto`.

Retour : remettre `8888:80` dans la composition.

## Remplacer une clé `APP_KEY` publiée

Le contrôle « Clé de l'application » de la page « Santé » passe au rouge en production quand `APP_KEY` est l'une des clés qui ont été lisibles dans l'historique du dépôt.

1. Générer une clé neuve :

   ```bash
   echo "base64:$(openssl rand -base64 32)"
   ```

2. La poser dans `APP_KEY`. Ne pas mettre l'ancienne clé dans `APP_PREVIOUS_KEYS` : une clé publiée n'y a pas sa place.
3. Recréer les conteneurs.

Effets : toutes les sessions se ferment, et les liens déjà envoyés par courriel cessent de valoir (voir [`APP_KEY` : générer, changer](variables-d-environnement.md#app_key--générer-changer)). Pas de retour possible : les liens et sessions d'avant ne reviennent pas.

## Depuis une version d'avant les deux instances de Redis

Le cache a sa propre instance (`redis-cache`). Les raisons sont dans [La pile de production](pile-de-production.md#deux-instances-de-redis).

**Avant de commencer** : l'image de la release (celle qui connaît `REDIS_CACHE_HOST` et `CACHE_DURABLE_STORE`) doit être publiée, et son `APP_VERSION` vérifié (« Versions des conteneurs »), avant de poser la nouvelle composition.

La bascule se fait en trois commandes, dans cet ordre :

```bash
docker compose -f docker-compose.prod.yml exec app php artisan cache:clear
docker compose -f docker-compose.prod.yml up -d -t 60
docker compose -f docker-compose.prod.yml exec redis redis-cli -n 1 FLUSHDB ASYNC
```

| Commande | Ce qu'elle fait |
| --- | --- |
| 1. `cache:clear` | Vide l'ancien cache avant que `redis` ne passe en `noeviction`. Sans elle, `redis` recharge jusqu'à 128 Mo d'ancien cache depuis son AOF et refuse sessions et mises en file. |
| 2. `up -d -t 60` | Met la pile à jour. Dès lors, `app` écrit son cache sur `redis-cache`. |
| 3. `FLUSHDB ASYNC` | Vide la base 1 de `redis`, où les anciens conteneurs ont pu écrire pendant la bascule. **Jamais `FLUSHALL`, jamais sans `-n 1`.** Elle seule la vide : un `cache:clear` lancé après la mise à jour ne vide que `redis-cache`. |

Effets :

- les compteurs des limiteurs repartent de zéro ;
- « Planificateur » comme « File d'attente » peuvent passer au rouge une fois, le temps du battement suivant.

**Retour** : remettre la composition de la release d'avant (jamais l'image seule), puis :

```bash
docker compose -f docker-compose.prod.yml up -d --remove-orphans
```

Sans `--remove-orphans`, `redis-cache` reste lancé. Les sessions et la file restent dans `redis_data` ; le cache repart vide. Revenir ensuite à la nouvelle composition, c'est refaire les trois commandes : le cache a rempli la base 1 entre-temps.

## Dans tous les cas

- À chaque release, la pile reçoit la composition de son tag. « Versions des services », dans la page « Santé », dit ensuite si `db` et `redis` servent la version attendue.
- Une release qui monte `redis` ou MySQL se déploie par la marche de `.ai/rules/dependencies.md`, à une heure creuse.
- Après la mise à jour : `docker compose -f docker-compose.prod.yml ps` montre `db`, `redis` et `redis-cache` en `healthy`, et la page « Santé » reste verte.

## Retour arrière

Pour revenir à la release d'avant : remettre la composition d'avant **et** épingler l'image sur la version exacte d'avant (voir [Déploiement](deploiement.md#revenir-en-arrière)). Les migrations déjà jouées restent, et une migration destructrice demande la restauration de la sauvegarde.

| Avec retour | Sans retour |
| --- | --- |
| le port sur la boucle locale (remettre `8888:80`) | les sessions fermées par le cookie `__Host-` et les copies hors ligne effacées (rien ne les rend) |
| les sessions chiffrées (`SESSION_ENCRYPT=false`, chacun se reconnecte une fois de plus) | les administrateurs déconnectés et la double authentification enrôlée (on ne redevient pas sans second facteur d'un geste ; `admin:reinitialiser-mfa` efface un secret) |
| les deux instances de Redis (composition d'avant, `--remove-orphans`) | la clé `APP_KEY` remplacée (les liens et sessions d'avant ne reviennent pas) |
| `dns_opt` (composition d'avant) | |
