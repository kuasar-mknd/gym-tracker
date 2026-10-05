# Contributing to GymTracker

Merci de contribuer à GymTracker ! 🎉 Voici comment participer.

## 🚀 Démarrer

L'installation est décrite une seule fois, dans la section [Installation du README](README.md#-installation-via-laravel-sail). Elle passe par un `docker run` avant tout appel à Sail : `vendor/` n'est pas versionné, donc `./vendor/bin/sail` n'existe pas sur un clone neuf, et Sail ne transmet une commande qu'à des conteneurs déjà démarrés. Elle crée aussi le compte de démonstration (`migrate --seed`).

Les commandes de ce guide supposent cette installation faite et les conteneurs lancés (`./vendor/bin/sail up -d`). Pour développer, Vite avec rechargement à chaud :

```bash
./vendor/bin/sail npm run dev
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
    vendor/bin/sail artisan test
    ```
6. **Formate** :
    ```bash
    vendor/bin/sail npm run format
    ```
7. **Commit** en français, au format du dépôt (voir plus bas) :
    ```bash
    git commit -m "fix(séances): le propriétaire d'une séance ne change plus après sa création (#1943)"
    ```
8. **Push** ta branche :
    ```bash
    git push origin feature/ma-super-feature
    ```
9. **Ouvre une Pull Request** sur GitHub

## 📝 Conventions de Commit

Un message de commit s'écrit en français, au format `type(portée): constat`, inspiré de [Conventional Commits](https://www.conventionalcommits.org/) : le type dit la nature du changement, la portée le domaine touché, et le constat ce qui est vrai après le commit, plutôt que le geste fait. Le numéro de l'issue suit entre parenthèses. Le titre d'une issue suit le même format.

| Type        | Description                                       |
| ----------- | ------------------------------------------------- |
| `feat`      | Nouvelle fonctionnalité                           |
| `fix`       | Correction de bogue                               |
| `sécurité`  | Correction d'une faille, durcissement             |
| `perf`      | Performance                                       |
| `ux`        | Parcours ou affichage, sans nouvelle fonction     |
| `docs`      | Documentation uniquement                          |
| `refactor`  | Restructuration sans changement de comportement   |
| `test`      | Ajout ou modification de tests                    |
| `ci`        | Intégration continue, workflows                   |
| `chore`     | Maintenance, dépendances, outillage               |

**Exemples :**

```
fix(séances): le propriétaire d'une séance ne change plus après sa création (#1943)
sécurité(push): se déconnecter détache l'appareil des notifications du compte (#1934)
perf(mesure): un en-tête Server-Timing pour les pages connectées, coupé par défaut (#1931)
ux(notifications): au retour d'une séance, l'accueil propose les notifications de records (#1936)
docs(readme): documenter toutes les variables d'environnement et tenir le README à jour (#1906)
```

## 🧪 Tests

**Avant de soumettre une PR, assure-toi que les tests passent :**

```bash
# Tous les tests
vendor/bin/sail artisan test

# Tests spécifiques
vendor/bin/sail artisan test --filter=WorkoutsControllerTest

# Avec couverture
vendor/bin/sail artisan test --coverage
```

**Écrire des tests :**

- Chaque nouvelle feature doit avoir des tests
- Les bug fixes doivent inclure un test qui reproduit le bug
- Utilise les factories pour les données de test

## 🎨 Code Style

### PHP (Laravel)

- **Pint** pour le formatage : `vendor/bin/sail bin pint`
- **Rector** pour la modernisation : `vendor/bin/sail bin rector process`
- **PHPStan** (Larastan) niveau Max : `vendor/bin/sail bin phpstan analyse --memory-limit=2G`
- **PHP Insights** aux quatre seuils de 90 : `vendor/bin/sail bin phpinsights analyse --no-interaction --min-quality=90 --min-complexity=90 --min-architecture=90 --min-style=90` (`phpinsights.php` ne déclare aucun seuil : sans ces options, rien n'est imposé)
- **Doctor** et **Checkpoint** pour l'environnement et la sécurité : `vendor/bin/sail artisan doctor`, `vendor/bin/sail artisan checkpoint:scan`
- Suit les conventions Laravel
- Utilise les type hints PHP 8.5 stricts (`declare(strict_types=1);`)
- Crée des Form Requests pour la validation

### JavaScript/Vue

- **Prettier** pour le formatage : `vendor/bin/sail npm run format`
- **ESLint** : `vendor/bin/sail npm run lint:js`
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

La CI rejoue exactement ces portes (`.github/workflows/ci.yml`) ; les passer en local évite un aller-retour.

- [ ] Tests passent, avec la couverture minimale de la CI (`vendor/bin/sail artisan test -p --coverage --min=94`)
- [ ] Tests JavaScript et couverture (`vendor/bin/sail npm run test:coverage`)
- [ ] Lint JavaScript (`vendor/bin/sail npm run lint:js`)
- [ ] Code formaté (`vendor/bin/sail npm run format` & `vendor/bin/sail bin pint`)
- [ ] Rector appliqué (`vendor/bin/sail bin rector process`)
- [ ] PHPStan propre (`vendor/bin/sail bin phpstan analyse --memory-limit=2G`)
- [ ] Insights aux seuils de la CI (`vendor/bin/sail bin phpinsights analyse --no-interaction --min-quality=90 --min-complexity=90 --min-architecture=90 --min-style=90`)
- [ ] Dépendances Composer toutes utilisées et sans avis (`vendor/bin/sail bin composer-unused`, `vendor/bin/sail composer audit`)
- [ ] Diagnostics Doctor et Checkpoint (`vendor/bin/sail artisan doctor`, `vendor/bin/sail artisan checkpoint:scan`)
- [ ] Tests navigateur si l'interface change (`vendor/bin/sail artisan dusk`)
- [ ] `actionlint` si un workflow change (`docker run --rm -v "$PWD":/repo:ro -w /repo rhysd/actionlint:1.7.12`)
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

Utilise le formulaire « Bug Report » des issues GitHub, avec :

- Description du bug, comportement attendu vs actuel
- Étapes pour reproduire
- Version (tag ou commit) si tu la connais, et navigateur
- Journaux ou captures si applicable

Une faille de sécurité ne s'ouvre jamais en issue publique : voir [SECURITY.md](SECURITY.md).

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
