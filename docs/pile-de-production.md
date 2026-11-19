# La pile de production : comment elle est construite, et pourquoi

Cette page explique comment `docker-compose.prod.yml` est construit et pourquoi chaque réglage existe. Elle ne contient pas de procédure : pour déployer, voir [Déploiement](deploiement.md) ; pour ce qu'une version change au déploiement, voir [Mise à niveau](mise-a-niveau.md).

## Les services

| Service | Image | Rôle | Réseaux | Mémoire max |
| --- | --- | --- | --- | --- |
| `app` | `ghcr.io/kuasar-mknd/gym-tracker:v1` | Le serveur web (Octane sur FrankenPHP). Publie `127.0.0.1:8888:80`. | `frontend`, `backend` | 1 Go |
| `worker` | la même | Horizon (`php artisan horizon`) : les files. | `backend`, `sortie` | 512 Mo |
| `scheduler` | la même | Les tâches planifiées (`php artisan schedule:work`). | `backend`, `sortie` | 256 Mo |
| `db` | `mysql:8.4` | MySQL, volume `db_data`. | `backend` | 1 Go |
| `redis` | `redis:8.10.3-alpine` | L'instance des données : file, Horizon, sessions, verrous, limiteurs, battements de santé. Volume `redis_data`. | `backend` | 256 Mo |
| `redis-cache` | `redis:8.10.3-alpine` | L'instance du cache. Sans volume. | `backend` | 192 Mo |

Le volume `journaux` porte les fichiers de journal des trois conteneurs de l'application (voir [Exploitation](exploitation.md#journaux)).

### Pourquoi `scheduler` existe

Sans `scheduler`, les tâches planifiées ne tournent pas : ni les sauvegardes, ni le rappel d'entraînement, ni le contrôle de santé qui enverrait l'alerte. La page « Santé » garde alors des résultats qui vieillissent, et seul son bouton de rafraîchissement fait passer le planificateur au rouge.

- Il porte `init: true` : ses tâches d'arrière-plan (les sauvegardes) sont orphelines dès leur lancement, et `schedule:work`, qui ne récolte pas les enfants qu'il n'a pas lancés, les laisserait en processus zombies jusqu'à la recréation du conteneur.
- Il lance `horizon:snapshot` toutes les cinq minutes : la page « Metrics » de Horizon montre le débit et la durée des travaux des deux dernières heures.

### Pourquoi `worker` réagit tout de suite

`worker` prend un travail neuf dès sa mise en file : la lecture d'une file vide attend sur Redis deux secondes au plus (`block_for`), et aucun processus de Horizon ne dort entre deux lectures (`sleep` à 0).

### Les réseaux

| Réseau | Rôle |
| --- | --- |
| `frontend` | Le réseau de `app`, par lequel passe ce qui arrive sur son port publié. |
| `backend` | Interne : sans aucune route vers l'extérieur. `db` et `redis` ne joignent rien hors de la pile. Faute de port publié, seuls les conteneurs de `backend` les joignent, et l'hôte aussi, par leur adresse sur ce réseau. |
| `sortie` | La sortie de `worker` et de `scheduler`, vers le relais SMTP et les services push. |

`worker` envoie les notifications en file (push, courriels, avis des sauvegardes lancées du panneau, dont le job tourne dans Horizon). `scheduler` envoie lui-même, sans file, les alertes de santé. Ils ont un réseau à eux plutôt que `frontend` : ils n'ont rien à recevoir, et ce qui se brancherait sur `frontend` pour joindre `app` les joindrait aussi. `db` et `redis` n'y sont pas.

## Deux instances de Redis

Le cache ne partage plus l'éviction de la file, des sessions et des verrous.

| Instance | Contenu | Politique |
| --- | --- | --- |
| `redis` | file, Horizon, sessions, verrous, limiteurs, battements de santé | `noeviction` : pleine, elle refuse une écriture plutôt que de perdre une session ou un travail. Persistée (AOF, volume `redis_data`). |
| `redis-cache` | ce qui se recalcule | `allkeys-lru`, sans persistance ni volume : elle repart vide. |

- Les deux ont un plafond de 128 Mo.
- Les limiteurs de tentatives et les battements de « File d'attente » et « Planificateur » restent sur l'instance des données (`CACHE_DURABLE_STORE`) : le cache à part peut repartir vide sans fausse alerte.
- Un retour en arrière remet la composition d'avant, jamais l'image seule : l'ancienne image avec la nouvelle composition range le cache sur la base 1 de `redis`, en `noeviction`, qui refuse alors les écritures dès 128 Mo.
- La procédure de bascule est dans [la mise à niveau](mise-a-niveau.md#depuis-une-version-davant-les-deux-instances-de-redis).

## Secrets hors des lignes de commande

La composition ne passe aucun secret en argument ni dans une sonde.

Avant, la sonde de `db` passait `-p${DB_PASSWORD}` à `mysqladmin`, et `redis` comme `redis-cache` recevaient `--requirepass ${REDIS_PASSWORD}`. La valeur se lisait dans `docker inspect` (la commande et la sonde du conteneur), `docker ps --no-trunc` et `ps` sur l'hôte.

Désormais :

- La sonde de `db` lit `MYSQL_PWD` (`DB_ROOT_PASSWORD`).
- Chaque Redis démarre par `sh -c`, qui lui donne `requirepass` par une configuration sur l'entrée standard, sous les guillemets que Redis demande, puis `exec docker-entrypoint.sh redis-server -`. Redis reste le premier processus, sous l'utilisateur `redis`, et s'arrête proprement.
- `AucunSecretNEstUnArgumentDeLaPileTest` (`tests/Feature/Conventions`) refuse tout secret dans la `command`, l'`entrypoint` ou la sonde d'un service.

**Ce que cela ne cache pas :**

- L'environnement d'un conteneur (`REDIS_PASSWORD`, `DB_PASSWORD`, `APP_KEY`…) reste lisible par `docker inspect` et par `root`. Le fichier de la pile aussi.
- Un mot de passe lu dans un fichier (`*_FILE`, `secrets:`) demanderait que l'entrée et la configuration de l'application le lisent, ce qui n'est pas fait.

Un mot de passe de Redis peut porter espaces, `"`, `\`, `$` et `#` (essayé avec `p@ss w"or\d $x #y`), pas de retour à la ligne.

## Sessions chiffrées

`SESSION_ENCRYPT` vaut `true` par défaut et dans la composition : la session, qui porte la clé AES des copies hors ligne, s'écrit chiffrée (`APP_KEY`) dans Redis.

Coût du chiffrement (AES-256-CBC, PHP 8.5, aarch64) :

| Session | Chiffrer | Déchiffrer | Taille |
| --- | --- | --- | --- |
| 450 octets | 9 µs | 8 µs | 996 octets au lieu de 449 (2,2 fois plus) |
| 4 Ko | 45 µs | 42 µs | |

- Cela fait environ un mégaoctet pour mille sessions, dans les 128 Mo de `redis`.
- Sur le serveur de production, comptez quelques fois plus par requête : c'est une estimation, sans mesure, négligeable devant les 0,35 à 1,7 s que coûte chaque écriture SQL.
- `SessionsChiffreesTest` (`tests/Feature/Security`) joue la bascule dans les deux sens.

Ce qu'une bascule change pour les utilisateurs est dans [la mise à niveau](mise-a-niveau.md#sessions-chiffrées-et-mots-de-passe-hors-des-lignes-de-commande).

## Réglages de MySQL

Le service `db` tourne avec ces options :

| Option | Valeur | Pourquoi |
| --- | --- | --- |
| `--innodb-flush-log-at-trx-commit` | `2` | Sur le disque de production, chaque écriture coûtait 250 à 500 ms de synchronisation (310 ms par écriture synchronisée). Le journal est écrit à chaque validation et synchronisé une fois par seconde : une coupure brutale (pas un redémarrage propre) peut perdre jusqu'à une seconde d'écritures validées. |
| `--skip-log-bin` | | Aucune réplication ne lit le journal binaire. |
| `--innodb-redo-log-capacity` | `256M` | Remplace le défaut de MySQL : réglage appliqué et mesuré sur la pile déployée. |
| `--innodb-io-capacity`, `--innodb-io-capacity-max` | `200`, `1000` | Idem : la capacité d'entrée-sortie est celle du disque de production, pas le défaut de MySQL. |
| `--innodb-buffer-pool-size` | `256M` | |
| `--max-connections` | `50` | |
| `--key-buffer-size`, `--tmp-table-size`, `--max-heap-table-size` | `32M` | |

`stop_grace_period: 5m` laisse au serveur cinq minutes pour s'arrêter proprement, au lieu des dix secondes après lesquelles Docker le coupe net. Il rend la main dès qu'il a fini.

Pour vérifier que ces réglages portent leurs fruits : [Mesurer la base](exploitation.md#mesurer-la-base).

## La sonde de santé de `app`

Le `HEALTHCHECK` de l'image et celui de la composition demandent `/up` avec `curl -f`, toutes les trente secondes.

| Réponse | Quand |
| --- | --- |
| `{"status":"up"}`, 200 | normalement |
| `{"status":"down"}`, 500 | un écouteur de `DiagnosingHealth` lève ; l'exception part dans les journaux |

- La même réponse JSON sert tout client, sans session ni cookie. Ni le navigateur ni le service worker de la PWA ne la gardent (`Cache-Control: no-store`).
- `/up` répond aussi pendant une maintenance.
- Le sondage ne lit pas la base : il réécrit seulement l'annonce de version de `app` dans le cache.
- **Un outil de surveillance** qui cherchait un mot dans l'ancienne page HTML de Laravel doit chercher `"status":"up"`.

## Le proxy inverse et le port de l'application

L'application s'ouvre **par le proxy inverse HTTPS**, jamais directement sur le port 8888 que publie `app`.

- Ce port n'écoute que sur la boucle locale de l'hôte (`127.0.0.1:8888:80`). Aucun autre appareil du réseau ne le joint, donc aucun ne peut forger `X-Forwarded-For` (liste blanche du panneau, limiteurs par adresse).
- Le proxy inverse doit donc tourner sur le même hôte. Les exigences et les vérifications sont dans [Déploiement](deploiement.md#proxy-inverse-et-port-de-lapplication).
- L'ouverture directe ne marche d'ailleurs pas : en production, le cookie de session est réservé à HTTPS, et une visite en http ne garde aucune session, la connexion échoue.
- Le conteneur voit la passerelle du pont, ni `127.0.0.1` ni l'adresse de l'hôte : c'est pourquoi un `TRUSTED_PROXIES` resserré doit contenir cette passerelle.
- Laravel fait confiance par défaut aux adresses privées (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`).

**L'historique du navigateur.** `X-Forwarded-Proto` décide du chiffrement de l'historique : les pages d'un compte sont chiffrées dans l'historique du navigateur, et la déconnexion en jette la clé, pour que le bouton Retour ne réaffiche rien du compte parti.

- Le navigateur ne sait chiffrer qu'en HTTPS ou sur la boucle locale, et l'application ne le lui demande que là.
- Sans l'en-tête, les pages restent servies, mais l'historique est en clair.
- Les pages d'un compte sortent en outre en `Cache-Control: no-store`.

## La résolution DNS bornée

`app`, `worker` et `scheduler` portent `dns_opt: [timeout:2, attempts:2]`.

`dns_get_record()` n'a pas de délai propre : il prenait celui du `resolv.conf` du conteneur. Avec un serveur DNS muet, 10 s mesurées par défaut, 4 s avec ces options.

Aucune variable d'environnement n'est ajoutée : redéployer la pile recrée ces trois conteneurs, et c'est tout.

## Les mises à jour de la pile

`pull_policy: always` fait retélécharger l'image à chaque mise à jour de la pile et recréer les conteneurs dont l'image a changé. Un conteneur garde sinon l'image avec laquelle il a été créé : retélécharger `:v1` ne met à jour aucun conteneur existant, et un simple redémarrage ne télécharge rien.

`db`, `redis` et `redis-cache` ne suivent pas `v1` : ils lancent l'étiquette de la composition (une ligne pour `db`, une version exacte pour `redis` et `redis-cache`), sans `pull_policy`.

Au démarrage, `app` joue les migrations. Une migration qui échoue l'arrête, plutôt que de servir un code qui ne correspond pas au schéma.
