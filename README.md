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
| **Tests backend** | 1 898 tests, couverture ≥ **94 %** | bloquant par PR |
| **Tests frontend** | 1 733 tests, ≥ **95 %** statements / 91 branches / 92 functions | bloquant par PR |
| **Tests navigateur** | 83 parcours Dusk sous Chrome headless | bloquant par PR |
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

### Variables d'environnement propres au déploiement

| Variable | Rôle |
| --- | --- |
| `SENTRY_DSN` | Erreurs côté serveur. |
| `SENTRY_DSN_PUBLIC` | Erreurs côté navigateur. Lue **à l'exécution**, jamais au build : une variable `VITE_` serait cuite dans l'image publique, et chaque installation tierce enverrait ses erreurs au même projet. Vide = pas de Sentry navigateur. |
| `HORIZON_ALLOWED_EMAILS` | Adresses autorisées à consulter Horizon, séparées par des virgules. Vide = fermé à tout le monde. |
| `ADMIN_INITIAL_PASSWORD` | Mot de passe du premier administrateur, exigé par `php artisan db:seed --class=AdminSeeder` ; le seeder échoue sans lui et ne réécrit jamais un mot de passe existant. |
| `ADMIN_ALLOWED_IPS` | Adresses IP autorisées sur le panneau `/backoffice`, séparées par des virgules : adresses exactes ou plages CIDR, IPv4 et IPv6 (`192.168.1.0/24,100.76.239.32`). **Vide = fermé** en production. |
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` | Web Push. Sans elles, la page Profil affiche « service de notifications non configuré » et rien ne part. Générées une fois par `npx web-push generate-vapid-keys` ; en changer invalide les abonnements existants. |
| `BACKUP_ARCHIVE_PASSWORD` | Mot de passe AES-256 des archives de sauvegarde, exigé au démarrage de la pile et transmis à `app`, `worker` et `scheduler`. Sans lui, aucune archive n'est écrite, que la sauvegarde vienne du planificateur, de Filament ou de `backup:run` : une archive en clair sur une autre machine serait une fuite. |
| `BACKUP_HOST_PATH` | Dossier de l'hôte monté dans `app` et `scheduler` pour les archives : un partage d'une autre machine monté par le DSM, jamais un volume Docker. Obligatoire, la pile refuse de démarrer sans. |

`docker-compose.prod.yml` déclare cinq services : `app`, `db`, `redis`, `worker` (Horizon) et **`scheduler`** — ce dernier exécute les tâches planifiées. Sans lui, elles ne tournent pas, et rien ne le signale : une tâche qui ne s'exécute pas ne lève aucune erreur.

---

## 🛠️ Stack Technique

| Catégorie | Technologies |
| --- | --- |
| **Backend** | Laravel 13, PHP 8.5 (Strict Types), MySQL |
| **Frontend** | Vue 3, Inertia.js 3, Tailwind CSS 4 |
| **Testing** | Pest 4, PHPUnit 12, Laravel Dusk 8 |
| **DevOps** | Laravel Sail (Docker), GitHub Actions |
| **Monitoring** | Laravel Pulse, Sentry, Telescope |

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
