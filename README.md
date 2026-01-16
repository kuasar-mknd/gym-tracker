<p align="center">
  <img src="https://img.shields.io/badge/Laravel-12-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel 12" />
  <img src="https://img.shields.io/badge/Vue.js-3-4FC08D?style=for-the-badge&logo=vue.js&logoColor=white" alt="Vue 3" />
  <img src="https://img.shields.io/badge/Tailwind-CSS-06B6D4?style=for-the-badge&logo=tailwindcss&logoColor=white" alt="Tailwind CSS" />
  <img src="https://img.shields.io/badge/Inertia.js-2-9553E9?style=for-the-badge&logo=inertia&logoColor=white" alt="Inertia.js" />
  <img src="https://img.shields.io/badge/PWA-Ready-5A0FC8?style=for-the-badge&logo=pwa&logoColor=white" alt="PWA Ready" />
</p>

<h1 align="center">💪 GymTracker</h1>

<p align="center">
  <strong>Une application de suivi de musculation moderne, élégante et performante.</strong>
  <br />
  <em>Track your workouts, measure your progress, achieve your goals.</em>
</p>

<p align="center">
  <a href="#-fonctionnalités">Fonctionnalités</a> •
  <a href="#-screenshots">Screenshots</a> •
  <a href="#-installation">Installation</a> •
  <a href="#-développement">Développement</a> •
  <a href="#-déploiement">Déploiement</a> •
  <a href="#-contributing">Contributing</a>
</p>

---

## ✨ Fonctionnalités

### 🏋️ Suivi d'Entraînement

- **Séances libres ou basées sur modèles** — Démarre rapidement avec tes routines favorites
- **Exercices personnalisables** — Bibliothèque d'exercices avec catégories (poitrine, dos, jambes, etc.)
- **Séries & répétitions** — Enregistre poids, reps, temps de repos
- **Historique complet** — Consulte toutes tes séances passées

### 📊 Statistiques & Progression

- **Graphiques interactifs** — Visualise ta progression avec Chart.js
- **Records personnels (PR)** — Suivi automatique de tes max (1RM, volume, poids)
- **Fréquence d'entraînement** — Statistiques mensuelles et hebdomadaires
- **Streak counter** — Maintiens ta motivation avec les séries consécutives

### 🎯 Objectifs & Récompenses

- **Objectifs personnalisés** — Définis des cibles de poids, volume ou fréquence
- **Système d'achievements** — Déblocage de trophées pour tes accomplissements
- **Notifications push** — Rappels et célébrations via Web Push

### 📏 Suivi Corporel

- **Mesures corporelles** — Poids, tour de taille, bras, cuisses, etc.
- **Journal quotidien** — Notes de bien-être, sommeil, nutrition
- **Évolution visuelle** — Graphiques de progression corporelle

### 🔧 Outils

- **Calculateur de plaques** — Calcule les disques à charger sur ta barre
- **Estimation 1RM** — Calcul de ton max théorique

### 🔐 Authentification

- **Email/Password** — Inscription classique sécurisée
- **OAuth Social** — Connexion via Google, GitHub, Apple
- **Two-Factor Auth** — Sécurité renforcée (optionnel)

---

## 🖼️ Screenshots

> _Screenshots à venir — L'interface utilise un design "Liquid Glass" avec effets de flou, transparence et dégradés modernes._

---

## 🛠️ Stack Technique

| Catégorie    | Technologies                        |
| ------------ | ----------------------------------- |
| **Backend**  | Laravel 12, PHP 8.4, MySQL/SQLite   |
| **Frontend** | Vue 3, Inertia.js 2, Tailwind CSS 3 |
| **Build**    | Vite 7, PWA (vite-plugin-pwa)       |
| **Auth**     | Laravel Breeze, Socialite, Sanctum  |
| **Queue**    | Laravel Horizon, Redis              |
| **Testing**  | PHPUnit 11, Pest 3                  |
| **CI/CD**    | GitHub Actions                      |
| **Deploy**   | Docker, FrankenPHP, Portainer       |

---

## 🚀 Installation

### Prérequis

- PHP 8.2+
- Composer 2.x
- Node.js 20+
- MySQL 8+ ou SQLite

### Installation Rapide

```bash
# Clone le repo
git clone https://github.com/kuasar-mknd/gym-tracker.git
cd gym-tracker

# Installation automatique
composer setup
```

<details>
<summary><strong>Installation Manuelle</strong></summary>

```bash
# 1. Dépendances PHP
composer install

# 2. Configuration
cp .env.example .env
php artisan key:generate

# 3. Base de données
php artisan migrate --seed

# 4. Dépendances JS
npm install

# 5. Build assets
npm run build
```

</details>

### Configuration OAuth (optionnel)

Pour activer la connexion sociale, ajoute ces variables dans `.env` :

```env
GOOGLE_CLIENT_ID=your-google-client-id
GOOGLE_CLIENT_SECRET=your-google-secret

GITHUB_CLIENT_ID=your-github-client-id
GITHUB_CLIENT_SECRET=your-github-secret

APPLE_CLIENT_ID=your-apple-client-id
APPLE_CLIENT_SECRET=your-apple-secret
```

---

## 💻 Développement

### Serveur de développement

```bash
# Lance tout en parallèle (serveur, queue, logs, vite)
composer dev
```

Ou manuellement :

```bash
# Terminal 1 - Backend
php artisan serve

# Terminal 2 - Frontend (hot reload)
npm run dev
```

### Commandes utiles

| Commande                     | Description               |
| ---------------------------- | ------------------------- |
| `php artisan test`           | Exécute les tests PHPUnit |
| `npm run build`              | Build production          |
| `npm run format`             | Formate JS/Vue/CSS + PHP  |
| `vendor/bin/pint`            | Formatte le code PHP      |
| `vendor/bin/phpstan analyze` | Analyse statique PHP      |

### Structure du projet

```
gym-tracker/
├── app/
│   ├── Http/Controllers/    # Contrôleurs (Workouts, Stats, etc.)
│   ├── Models/              # Modèles Eloquent
│   └── Services/            # Logique métier (Achievements, etc.)
├── resources/
│   ├── js/
│   │   ├── Components/      # Composants Vue réutilisables
│   │   ├── Layouts/         # Layouts (Authenticated, Guest)
│   │   └── Pages/           # Pages Inertia
│   └── css/                 # Styles Tailwind + Glass Design System
├── database/
│   ├── migrations/          # Schéma de base de données
│   └── seeders/             # Données de test
└── tests/                   # Tests PHPUnit
```

---

## 🐳 Déploiement

### Docker (Production)

```bash
# Build l'image
docker build -t gym-tracker .

# Lance avec docker-compose
docker-compose -f docker-compose.prod.yml up -d
```

### Variables d'environnement production

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

DB_CONNECTION=mysql
DB_HOST=your-db-host
DB_DATABASE=gym_tracker
DB_USERNAME=your-user
DB_PASSWORD=your-password

# Queue (Redis recommandé)
QUEUE_CONNECTION=redis
REDIS_HOST=your-redis-host
```

---

## 🧪 Tests

```bash
# Tous les tests
php artisan test

# Tests avec couverture
php artisan test --coverage

# Un fichier spécifique
php artisan test tests/Feature/WorkoutsTest.php

# Filtrer par nom
php artisan test --filter=testUserCanCreateWorkout
```

---

## 🤝 Contributing

Les contributions sont les bienvenues ! Voir [CONTRIBUTING.md](CONTRIBUTING.md) pour les guidelines.

### Workflow

1. **Fork** le repository
2. **Crée** une branche (`git checkout -b feature/amazing-feature`)
3. **Commit** tes changements (`git commit -m 'Add amazing feature'`)
4. **Push** sur la branche (`git push origin feature/amazing-feature`)
5. **Ouvre** une Pull Request

### Code Quality

- Formate ton code : `npm run format`
- Tests passent : `php artisan test`
- PHPStan propre : `vendor/bin/phpstan analyze`

---

## 📋 Roadmap

Voir [ROADMAP.md](ROADMAP.md) pour les fonctionnalités planifiées.

- [ ] Mode hors-ligne complet (PWA)
- [ ] Synchronisation multi-appareils
- [ ] Export PDF des statistiques
- [ ] Partage social des achievements
- [ ] Intégration wearables (Apple Watch, Garmin)

---

## 🔒 Sécurité

Pour signaler une vulnérabilité, voir [SECURITY.md](SECURITY.md).

---

## 📝 License

Ce projet est sous licence [MIT](LICENSE).

---

<p align="center">
  <strong>Fait avec ❤️ pour les passionnés de fitness</strong>
  <br />
  <a href="https://github.com/kuasar-mknd/gym-tracker">⭐ Star ce repo si tu aimes !</a>
</p>
