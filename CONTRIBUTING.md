# Contributing to GymTracker

Merci de contribuer à GymTracker ! 🎉 Voici comment participer.

## 🚀 Quick Start

```bash
# Fork & clone
git clone https://github.com/YOUR_USERNAME/gym-tracker.git
cd gym-tracker

# Setup
composer setup

# Dev server
composer dev
```

## 📋 Workflow

1. **Fork** le repo sur GitHub
2. **Clone** ton fork localement
3. **Crée une branche** depuis `main` :
    ```bash
    git checkout -b feature/ma-super-feature
    # ou
    git checkout -b fix/bug-description
    ```
4. **Code** ta feature/fix
5. **Teste** :
    ```bash
    php artisan test
    ```
6. **Formate** :
    ```bash
    npm run format
    ```
7. **Commit** avec un message clair :
    ```bash
    git commit -m "feat: add workout templates feature"
    # ou
    git commit -m "fix: resolve N+1 query in dashboard"
    ```
8. **Push** ta branche :
    ```bash
    git push origin feature/ma-super-feature
    ```
9. **Ouvre une Pull Request** sur GitHub

## 📝 Conventions de Commit

Nous utilisons [Conventional Commits](https://www.conventionalcommits.org/) :

| Type        | Description                                 |
| ----------- | ------------------------------------------- |
| `feat:`     | Nouvelle fonctionnalité                     |
| `fix:`      | Correction de bug                           |
| `docs:`     | Documentation uniquement                    |
| `style:`    | Formatage, pas de changement de code        |
| `refactor:` | Refactoring sans changement de comportement |
| `test:`     | Ajout ou modification de tests              |
| `chore:`    | Maintenance, dépendances, CI                |

**Exemples :**

```
feat: add plate calculator tool
fix: resolve login redirect loop
docs: update installation guide
refactor: extract AchievementService from controller
test: add coverage for workout deletion
```

## 🧪 Tests

**Avant de soumettre une PR, assure-toi que les tests passent :**

```bash
# Tous les tests
php artisan test

# Tests spécifiques
php artisan test --filter=WorkoutsTest

# Avec couverture
php artisan test --coverage
```

**Écrire des tests :**

- Chaque nouvelle feature doit avoir des tests
- Les bug fixes doivent inclure un test qui reproduit le bug
- Utilise les factories pour les données de test

## 🎨 Code Style

### PHP (Laravel)

- **Pint** pour le formatage : `vendor/bin/pint`
- **Rector** pour la modernisation : `vendor/bin/rector process`
- **PHPStan** (Larastan) niveau Max : `vendor/bin/phpstan analyze`
- Suit les conventions Laravel
- Utilise les type hints PHP 8.4 stricts (`declare(strict_types=1);`)
- Crée des Form Requests pour la validation

### JavaScript/Vue

- **Prettier** pour le formatage : `npm run format`
- Composants Vue en `<script setup>`
- Utilise les composants du design system (`GlassCard`, `GlassButton`, etc.)

## 🏗️ Architecture

### Backend

- **Controllers** : Thin controllers, logique dans les Services
- **Services** : Logique métier (`AchievementService`, etc.)
- **Models** : Eloquent avec relations typées
- **Form Requests** : Validation séparée

### Frontend

- **Pages** : `resources/js/Pages/` — Pages Inertia
- **Components** : `resources/js/Components/` — Réutilisables
- **Layouts** : `resources/js/Layouts/` — Templates

## 📦 Pull Request Guidelines

### Avant de soumettre

- [ ] Tests passent (`php artisan test`)
- [ ] Code formaté (`npm run format` & `vendor/bin/pint`)
- [ ] Rector appliqué (`vendor/bin/rector process`)
- [ ] PHPStan propre (`vendor/bin/phpstan analyze`)
- [ ] Pas de `console.log` ou `dd()` oubliés
- [ ] Documentation mise à jour si nécessaire

### Template de PR

```markdown
## Description

[Décris ce que fait ta PR]

## Type de changement

- [ ] Bug fix
- [ ] Nouvelle feature
- [ ] Breaking change
- [ ] Documentation

## Comment tester

1. [Étape 1]
2. [Étape 2]

## Captures d'écran (si UI)

[Screenshots]
```

## 🐛 Signaler un Bug

Utilise le template d'issue sur GitHub avec :

- Description du bug
- Étapes pour reproduire
- Comportement attendu vs actuel
- Screenshots si applicable
- Environnement (OS, navigateur, version PHP)

## 💡 Proposer une Feature

1. Vérifie que la feature n'existe pas déjà dans les issues
2. Ouvre une issue avec le template "Feature Request"
3. Attends la validation avant de coder

## 📚 Ressources

- [Documentation Laravel](https://laravel.com/docs)
- [Documentation Inertia.js](https://inertiajs.com/)
- [Documentation Vue 3](https://vuejs.org/)
- [Documentation Tailwind CSS](https://tailwindcss.com/)

---

**Merci de contribuer ! ❤️**
