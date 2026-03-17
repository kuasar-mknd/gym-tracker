<p align="center">
  <img src="https://img.shields.io/badge/Laravel-12-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel 12" />
  <img src="https://img.shields.io/badge/Vue.js-3-4FC08D?style=for-the-badge&logo=vue.js&logoColor=white" alt="Vue 3" />
  <img src="https://img.shields.io/badge/Tailwind-CSS-4-06B6D4?style=for-the-badge&logo=tailwindcss&logoColor=white" alt="Tailwind CSS 4" />
  <img src="https://img.shields.io/badge/Inertia.js-2-9553E9?style=for-the-badge&logo=inertia&logoColor=white" alt="Inertia.js" />
  <img src="https://img.shields.io/badge/PHPStan-Level%209-blue?style=for-the-badge" alt="PHPStan Level 9" />
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

## 🏆 Qualité & Performance

Le projet respecte des standards d'ingénierie très élevés :
- **PHP Insights** : Score de **100%** en Architecture et Complexité.
- **PHPStan** : Analyse statique de **Niveau 9** (Strict Typing complet).
- **Tests** : Suite de +750 tests automatisés (Pest & Dusk).
- **Optimisation** : Surgical Cache Invalidation pour des performances maximales.

Voir la documentation détaillée :
- [🚀 Plan d'Attaque Performance](docs/performance/attack_plan.md)
- [🛡️ Plan de Sécurité](docs/security/plan.md)
- [📅 Roadmap du projet](docs/roadmap.md)

---

## 🛠️ Stack Technique

| Catégorie | Technologies |
| --- | --- |
| **Backend** | Laravel 12, PHP 8.5 (Strict Types), MySQL |
| **Frontend** | Vue 3, Inertia.js 2, Tailwind CSS 4 |
| **Testing** | Pest 4, PHPUnit 12, Laravel Dusk 8 |
| **DevOps** | Laravel Sail (Docker), GitHub Actions |
| **Monitoring** | Laravel Pulse, Sentry, Telescope |

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
| `./vendor/bin/sail up -d` | Lance les conteneurs (App, MySQL, Redis, Mailpit) |
| `./vendor/bin/sail npm run dev` | Lance Vite avec Hot Reload |
| `./vendor/bin/sail artisan test` | Exécute la suite de tests Pest |
| `./vendor/bin/sail bin pint` | Formate le code (PSR-12) |
| `./vendor/bin/sail bin phpstan analyze` | Vérifie le typage strict |
| `./vendor/bin/sail artisan insights` | Analyse la qualité du code |

---

## 🤝 Contribution

Les contributions sont les bienvenues !
1. Assure-toi que les tests passent : `./vendor/bin/sail artisan test`
2. Vérifie la qualité : `./vendor/bin/sail artisan insights`
3. Formate ton code : `./vendor/bin/sail bin pint`
4. Voir le [Guide de Contribution](CONTRIBUTING.md) pour plus de détails.
