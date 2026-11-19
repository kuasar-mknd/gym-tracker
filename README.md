<p align="center">
  <img src="public/icone.svg" alt="" width="120" height="120" />
</p>

<h1 align="center">GymTracker</h1>

<p align="center">
  Une application de suivi de musculation : séances, records, statistiques, mesures, habitudes.
  <br />
  Laravel 13, Vue 3, Inertia 3, Tailwind CSS 4, Filament 5 (panneau d'administration).
</p>

## Fonctionnalités

- **Séances et modèles** : démarre depuis un modèle ou une séance libre ; records personnels (poids, 1RM, volume) détectés seuls ; série de jours consécutifs.
- **Statistiques et santé** : graphiques de progression, habitudes, mesures et composition corporelle.
- **Outils** : calculateurs de plaques, de 1RM, de Wilks et de macros, échauffement, hydratation, minuteur d'intervalles, jeûne.
- **Hors ligne** : l'application installée (PWA) s'ouvre et enregistre séries et séances sans réseau.
- **Connexion** par courriel, ou par Google, GitHub et Apple ; notifications Web Push.

## Installation

Il faut Docker Desktop : PHP et Composer tournent dans des conteneurs (Laravel Sail).

```bash
git clone https://github.com/kuasar-mknd/gym-tracker.git
cd gym-tracker

# Il n'existe pas d'image laravelsail/…-composer à la version de PHP du projet :
# celle-ci ne sert qu'à amorcer Sail, sans exécuter les scripts de Composer.
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs --no-scripts

cp .env.example .env
./vendor/bin/sail up -d
./vendor/bin/sail composer install   # rejoue les scripts, à la bonne version de PHP
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail npm install
./vendor/bin/sail npm run build
```

`migrate --seed` crée un compte de démonstration, `test@example.com` / `password` ; en local, `/__dev-login` s'y connecte d'un clic. Les courriels arrivent dans Mailpit, sur le port 8025.

## Commandes courantes

- `./vendor/bin/sail up -d` lance les conteneurs (application, MySQL, Redis, Mailpit, Selenium).
- `./vendor/bin/sail composer dev` lance Vite avec rechargement à chaud, la file (`queue:listen`) et les journaux (`pail`).
- `./vendor/bin/sail artisan test -p` : suite backend, en parallèle.
- `./vendor/bin/sail npx vitest run` : suite frontend.
- `./vendor/bin/sail artisan dusk` : parcours navigateur, après avoir dérivé `.env.dusk.local` (recette : [Parcours navigateur](docs/developpement.md#parcours-navigateur)).
- `./vendor/bin/sail bin pint` formate le code.
- `./vendor/bin/sail php vendor/bin/phpstan analyse --memory-limit=2G` : analyse statique, `level: max`.
- `./vendor/bin/sail php vendor/bin/rector process --dry-run` : modernisation en attente.
- `./vendor/bin/sail php vendor/bin/phpinsights analyse --no-interaction --min-quality=90 --min-complexity=90 --min-architecture=90 --min-style=90` : qualité aux seuils de la CI.

## Qualité

La CI bloque une PR sur PHPStan (`level: max`), les tests backend (MySQL 8.4 et 9.7.4), frontend et navigateur (Dusk), PHP Insights, Rector et Pint, chacun à un seuil qui ne fait que monter. La mutation, nocturne, bloque la release.

Les seuils, les mesures et les gardes de convention sont dans [docs/developpement.md](docs/developpement.md). Le guide du contributeur est [CONTRIBUTING.md](CONTRIBUTING.md).

## Mise en production

La production suit l'image `ghcr.io/kuasar-mknd/gym-tracker:v1`, publiée à chaque tag `v*`, par `docker-compose.prod.yml`. Il lance six services :

- `app` : le serveur web ;
- `worker` : Horizon, les files ;
- `scheduler` : les tâches planifiées (sans lui, ni sauvegarde ni alerte de santé) ;
- `db` : MySQL 8.4 ;
- `redis` : la file, les sessions et les verrous ;
- `redis-cache` : le cache.

Le détail de chaque étape est dans [docs/deploiement.md](docs/deploiement.md).

### Premier déploiement

1. **Poser les variables** dans la pile, ou dans un `.env` à côté de la composition.
   - Indispensables : `APP_KEY` (`echo "base64:$(openssl rand -base64 32)"`), `APP_URL` (l'adresse publique en `https://`), `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_ROOT_PASSWORD`, `REDIS_PASSWORD`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_FROM_ADDRESS`, `BACKUP_ARCHIVE_PASSWORD`, `BACKUP_HOST_PATH`.
   - `MAIL_USERNAME` et `MAIL_PASSWORD` si le relais l'exige.
   - Pas de `'` ni de `\` dans les mots de passe de MySQL.
   - `BACKUP_HOST_PATH` est un dossier de l'hôte, inscriptible par l'uid 33, monté pour les sauvegardes. Sans lui, sans `BACKUP_ARCHIVE_PASSWORD` ni `REDIS_PASSWORD`, la pile ne démarre pas.
   - Pour le panneau : `ADMIN_ALLOWED_IPS` et `ADMIN_INITIAL_PASSWORD` (étape 4).
   - Pour les notifications : `VAPID_PUBLIC_KEY` et `VAPID_PRIVATE_KEY` (`npx web-push generate-vapid-keys`).
   - Pour les alertes de santé : `HEALTH_TO_ADDRESS`.
   - Une variable sans valeur arrive vide. La configuration est figée au démarrage : une variable changée n'agit qu'une fois les conteneurs recréés.
   - Référence complète : [variables d'environnement](docs/variables-d-environnement.md).
2. **Lancer la pile** : `docker compose -f docker-compose.prod.yml up -d`. Au démarrage, `app` joue les migrations ; une migration qui échoue l'arrête.
3. **Placer le proxy inverse HTTPS** sur le même hôte, vers `localhost:8888` (le port n'écoute que sur la boucle locale), avec `X-Forwarded-Proto`.
   - L'application ne marche pas en http : le cookie de session est réservé à HTTPS.
   - Si le proxy est ailleurs : remettre `8888:80` et régler `TRUSTED_PROXIES` sur son adresse.
4. **Créer le premier administrateur** : `docker compose -f docker-compose.prod.yml exec app php artisan db:seed --class=AdminSeeder --force`, avec `ADMIN_INITIAL_PASSWORD` posé à l'étape 1. Puis retirer la variable et recréer `app`.
   - Le compte est `admin@gymtracker.app`.
   - Le panneau est à `/backoffice`, ouvert aux adresses de `ADMIN_ALLOWED_IPS`. Il exige une double authentification, enrôlée à la première connexion.
5. **Vérifier** : `docker compose -f docker-compose.prod.yml ps` (tout `healthy`), le site par son adresse HTTPS, la page « Santé » du panneau.

### Mettre à jour

1. À chaque release, recevoir la composition de son tag.
2. Lire [la mise à niveau](docs/mise-a-niveau.md), qui dit les effets du déploiement.
3. Lancer `docker compose -f docker-compose.prod.yml up -d` : l'image est retéléchargée et les conteneurs recréés.

`db` et `redis` ne suivent pas `v1` : leur version est celle de l'étiquette `image:` de la composition.

### Revenir en arrière

1. Remettre la composition de la release d'avant.
2. Épingler l'image de `app`, `worker` et `scheduler` sur la version exacte d'avant (`ghcr.io/kuasar-mknd/gym-tracker:X.Y.Z` à la place de `:v1`, qui suit la dernière version).
3. Lancer `docker compose -f docker-compose.prod.yml up -d --remove-orphans`.

Limites du retour : [docs/deploiement.md](docs/deploiement.md#revenir-en-arrière) (volumes de `redis` ou de MySQL, migrations déjà jouées) et [la mise à niveau](docs/mise-a-niveau.md#retour-arrière) (effets sans retour).

## Documentation

L'index est [docs/README.md](docs/README.md).

- **Déployer et exploiter** : [variables d'environnement](docs/variables-d-environnement.md), [déploiement](docs/deploiement.md), [mise à niveau](docs/mise-a-niveau.md), [exploitation](docs/exploitation.md), [diagnostic](docs/diagnostic.md), [relevé des temps](docs/releve-des-temps.md), [publication des versions](docs/publication-des-versions.md).
- **Comprendre** : [la pile de production](docs/pile-de-production.md), [sécurité](docs/securite.md), [hors ligne](docs/hors-ligne.md), [décisions d'architecture](docs/adr/), [charte graphique](docs/charte.html).
- **Développer** : [développement](docs/developpement.md) (seuils de qualité, parcours navigateur), [tests de mutation](docs/tests-de-mutation.md), [guide du contributeur](CONTRIBUTING.md), [politique de sécurité](SECURITY.md).
