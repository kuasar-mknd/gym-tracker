# Notes de mise à niveau

Ce que la prochaine version (après v1.7.0) fait une fois, au déploiement. À lire avant `docker compose -f docker-compose.prod.yml up -d`, à une heure creuse. La composition de la release est à poser avant la mise à jour de la pile.

## Sessions et cookies : tout le monde se reconnecte une fois

- Le cookie de session prend le préfixe `__Host-` (`SESSION_SECURE_COOKIE` vrai en production) : **toutes les sessions se ferment une fois**.
- Les cookies `appareil_connu` ne valent plus rien : le plafond d'échecs de connexion par adresse s'applique de nouveau jusqu'à la prochaine connexion de chaque appareil.
- La clé des copies hors ligne vit dans la session : **les copies hors ligne de chaque appareil s'effacent une fois**, à la première vérification de la page « hors ligne ». Les modifications qui attendaient leur envoi restent pour le retour du compte.
- Les appareils qui se reconnectent ensemble depuis une même adresse comptent dans le plafond de sessions neuves par minute et par adresse.

## Sessions chiffrées et mots de passe hors des lignes de commande

`SESSION_ENCRYPT` vaut `true` par défaut. Une session écrite en clair se lit comme vide : son titulaire devient un invité **une fois**, sauf l'appareil qui se souvient du compte (cookie de rappel), qui se reconnecte seul. Les sessions en clair restent dans Redis (et dans son AOF, jusqu'à sa prochaine réécriture) jusqu'à `SESSION_LIFETIME` minutes sans visite.

La composition change la sonde de `db` et la commande de `redis` et `redis-cache` : `up -d` les recrée. La manœuvre, une seule fois, avec la composition de la release :

```sh
docker compose -f docker-compose.prod.yml stop worker scheduler
docker compose -f docker-compose.prod.yml exec app php artisan down
docker compose -f docker-compose.prod.yml up -d -t 300
```

Si `up -d` n'a pas recréé `app`, `docker compose -f docker-compose.prod.yml exec app php artisan up` le rouvre. Les vérifications et le retour arrière sont dans `docs/deploiement.md`, « Secrets hors des lignes de commande et sessions chiffrées ».

## Double authentification obligatoire au panneau

**Tous les administrateurs sont déconnectés une fois** : la session porte désormais la preuve du second facteur, et « Se souvenir de moi » ne rouvre plus le panneau sans le code. Chacun enrôle son application de codes à sa prochaine connexion (QR code, puis codes de récupération à noter, affichés une seule fois). Un administrateur qui a tout perdu : `php artisan admin:reinitialiser-mfa adresse@example.org`, dans le conteneur `app`. Détail : `docs/securite.md`.

## Résolution DNS bornée : `app`, `worker` et `scheduler` sont recréés

Ils portent `dns_opt: [timeout:2, attempts:2]`. `docker compose -f docker-compose.prod.yml up -d` les recrée ; rien d'autre à faire, aucune variable n'est ajoutée.

## Port de l'application sur la boucle locale

`app` publie `127.0.0.1:8888:80` au lieu de `8888:80`. Après `up -d`, dans l'ordre : (1) ouvrir le site par son adresse HTTPS habituelle, donc par le proxy inverse : la page de connexion répond ; (2) depuis un autre appareil du réseau local, ouvrir `http://IP-DE-L-HÔTE:8888` : la connexion doit être refusée ou expirer. Si (1) échoue : remettre `8888:80` dans la composition et redéployer.

Le proxy inverse doit tourner sur le même hôte et viser `localhost:8888`. Sur un autre hôte : remettre `8888:80` et régler `TRUSTED_PROXIES` sur son adresse. Si `TRUSTED_PROXIES` est resserré, il doit contenir l'adresse de la passerelle du réseau de conteneurs, pas seulement celle du réseau local. Le proxy transmet `X-Forwarded-Proto`.

## Clé `APP_KEY` publiée à remplacer

Le contrôle « Clé de l'application » de la page « Santé » passe au rouge en production quand `APP_KEY` est l'une des clés qui ont été lisibles dans l'historique du dépôt. La remplacer : `echo "base64:$(openssl rand -base64 32)"`, puis recréer les conteneurs. Effets : **toutes les sessions se ferment**, avec ou sans `APP_PREVIOUS_KEYS` (l'empreinte du mot de passe en session et le cookie « se souvenir de moi » ne sont signés que par `APP_KEY`), et les liens déjà envoyés par courriel (ouverture d'un compte, vérification ou confirmation d'adresse, réinitialisation du mot de passe) cessent de valoir. `APP_PREVIOUS_KEYS` garde lisibles les cookies chiffrés et valides les liens signés d'une ancienne clé : une clé publiée n'y a pas sa place.

## Depuis une version d'avant les deux instances de Redis

Le cache a sa propre instance (`redis-cache`). Avant de s'en servir, l'image de la release (celle qui connaît `REDIS_CACHE_HOST` et `CACHE_DURABLE_STORE`) est publiée et son `APP_VERSION` vérifié (« Versions des conteneurs »). La bascule se fait en trois commandes, dans cet ordre (détail et retour arrière : `docs/deploiement.md`, « Deux instances de Redis ») :

```sh
docker compose -f docker-compose.prod.yml exec app php artisan cache:clear
docker compose -f docker-compose.prod.yml up -d -t 60
docker compose -f docker-compose.prod.yml exec redis redis-cli -n 1 FLUSHDB ASYNC
```

## Dans tous les cas

- À chaque release, la pile reçoit la composition de son tag ; « Versions des services », dans la page « Santé », dit ensuite si `db` et `redis` servent la version attendue.
- Une release qui monte `redis` ou MySQL se déploie par la marche de `.ai/rules/dependencies.md`, à une heure creuse.
- Après la mise à jour : `docker compose -f docker-compose.prod.yml ps` montre `db`, `redis` et `redis-cache` en `healthy`, et la page « Santé » reste verte.
