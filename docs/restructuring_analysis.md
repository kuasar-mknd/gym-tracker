# 🏗️ Analyse & Plan de Restructuration — Gym Tracker

> **Objectif :** Amener la codebase aux standards professionnels 2026 — propre, maintenable, performante et scalable.

---

## 1. Vue d'ensemble du projet

| Dimension | État actuel |
|---|---|
| **Stack** | Laravel 12 · PHP 8.5 · Inertia v2 · Vue 3 · Tailwind v4 · Filament v5 |
| **Base de données** | MySQL · 73 migrations |
| **Models** | 28 Eloquent models |
| **Controllers** | 27 Web + 30 API = 57 controllers |
| **Form Requests** | 46 (+ 2 sous-dossiers Auth/Api) |
| **API Resources** | 28 |
| **Policies** | 29 |
| **Services** | 6 |
| **Actions** | 14+ dossiers (pattern Action bien adopté) |
| **Middleware** | 6 custom |
| **Tests** | 50+ Feature tests, 3+ Unit test dirs |
| **Frontend Pages** | 14 modules + Dashboard |
| **Composables** | 3 (`useHaptics`, `usePullToRefresh`, `useTheme`) |
| **UI Components** | 8 Glass + 14 shared components |

---

## 2. Points forts ✅

- **`declare(strict_types=1)`** partout
- **Pattern Action** systématique (14+ dossiers sous `app/Actions/`)
- **Form Request validation** — 46 classes, pas de validation inline
- **Policies complètes** — 29 policies pour tous les models
- **API Resources** — 28 resources + API versionnée `v1` + Sanctum
- **PHPDoc typé** — `@return`, `@param`, array shapes
- **Model `User` `final`** + casts modernes (`casts()` method)
- **Inertia v2 `Deferred` props** sur le Dashboard
- **Design System** — composants `Glass*` réutilisables
- **CI Pipeline** — PHPStan, Pint, PHP Insights, Pest, Dusk E2E
- **CSP & Security Headers** — middleware dédié

---

## 3. Problèmes identifiés & Recommandations

### 🔴 Priorité Critique

---

#### 3.1. Fichiers `ci_failure*.log` à la racine

6 fichiers de logs CI (jusqu'à 824 KB chacun) polluent la racine du projet.

```diff
# Supprimer
- ci_failure.log → ci_failure_7.log

# Ajouter au .gitignore
+ ci_failure*.log
```

---

#### 3.2. `StatsService` monolithique (758 lignes)

`app/Services/StatsService.php` — le plus gros fichier du backend, gère volume trends, body metrics, duration distribution, 1RM progress + toute la logique de cache.

**Recommandation — splitter en services spécialisés :**

| Nouveau Service | Méthodes extraites |
|---|---|
| `VolumeStatsService` | `getVolumeTrend`, `getDailyVolumeTrend`, `getWeeklyVolumeTrend`, `getVolumeHistory`, `getMonthlyVolumeHistory`, volume comparisons |
| `BodyStatsService` | `getLatestBodyMetrics`, `getWeightHistory`, `getBodyFatHistory` |
| `WorkoutStatsService` | `getDurationHistory`, `getDurationDistribution`, `getTimeOfDayDistribution` |
| `ExerciseStatsService` | `getExercise1RMProgress`, `getMuscleDistribution` |
| `StatsCacheManager` | Toute la logique de cache clearing |

---

#### 3.3. `Dashboard.vue` monolithique (478 lignes)

`resources/js/Pages/Dashboard.vue` — tout inline (header, charts, activity list, goals, PRs).

**Recommandation — extraire en composants :**
```
Pages/Dashboard.vue (≈80 lignes, composition pure)
├── Components/Dashboard/DashboardHeader.vue
├── Components/Dashboard/QuickActions.vue
├── Components/Dashboard/WeeklyVolumeSection.vue
├── Components/Dashboard/DurationSection.vue
├── Components/Dashboard/TimeOfDaySection.vue
├── Components/Dashboard/RecentActivity.vue
├── Components/Dashboard/GoalsSummary.vue
└── Components/Dashboard/RecentPRs.vue
```

---

#### 3.4. `WorkoutLine` model trop chargé (323 lignes)

`app/Models/WorkoutLine.php` contient de la logique métier lourde (recommended values, batch loading, cache).

**Recommandation :** Extraire vers `App\Services\RecommendedValuesService`.

---

### 🟡 Priorité Haute

---

#### 3.5. 73 migrations non squashées

12+ migrations juste pour les index (`add_performance_indexes`, `add_missing_indexes_perf_audit`, `add_remaining_missing_indexes`, `optimize_database_indexes`, `add_final_missing_indexes`…).

**Recommandation :** Squash via `php artisan schema:dump`. Futures migrations d'index inline dans la création de table.

---

#### 3.6. Inconsistance de nommage des controllers

| Pluriel ❌ | Singulier ✅ |
|---|---|
| `WorkoutsController` | `SupplementController` |
| `SetsController` | `HabitController` |
| `WorkoutLinesController` | `GoalController` |
| `WorkoutTemplatesController` | `ExerciseController` |

**Recommandation :** Renommer en **singulier** (convention Laravel).

---

#### 3.7. Duplication `AuthorizesRequests`

Déjà dans la classe parente `Controller` (ligne 9), mais re-déclaré dans certains enfants (ex: `WorkoutsController` ligne 27).

**Recommandation :** Retirer le `use AuthorizesRequests` des controllers enfants.

---

#### 3.8. Strings hardcodées en français dans le backend

```php
// StatsService.php
$labels = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
$buckets = ['Matin (06h-12h)' => 0, 'Après-midi (12h-17h)' => 0, ...];
```

**Recommandation :** Utiliser `__()` / `trans()` + `lang/fr/*.php` ou `translatedFormat()`.

---

#### 3.9. Utilisation de `DB::` au lieu d'Eloquent

15+ usages de `DB::table(...)` avec joins manuels dans `StatsService`.

**Recommandation :** Créer des query objects dédiés (`App\Queries\VolumeByPeriodQuery`, etc.).

---

### 🟢 Priorité Moyenne

---

#### 3.10. Manque de DTOs pour les retours de stats

Les array shapes PHPDoc ne protègent pas à l'exécution.

**Recommandation :** Créer des DTOs readonly :
```php
final readonly class VolumeTrendPoint {
    public function __construct(
        public string $date,
        public string $fullDate,
        public string $name,
        public float $volume,
    ) {}
}
```

---

#### 3.11. Organisation des composants frontend

Composants orphelins à la racine de `Components/` : `Checkbox.vue`, `DangerButton.vue`, `PrimaryButton.vue`, `SecondaryButton.vue`, `TextInput.vue`, `Modal.vue`, `NavLink.vue`, `Stats.vue`.

**Recommandation :**
- Déplacer `Modal.vue` → `UI/`
- Primitives de formulaire → `Form/`
- Consolider `PrimaryButton`/`SecondaryButton`/`DangerButton` → `GlassButton` avec prop `variant`
- Consolider `TextInput` → `GlassInput`

---

#### 3.12. Pas d'Enums pour les constantes métier

Types d'exercices, types de PR, types de goals = strings magiques.

**Recommandation :** Créer `App\Enums\ExerciseCategory`, `PersonalRecordType`, `GoalType`, etc.

---

#### 3.13. Créer les dossiers `DTOs`, `Enums`, `ValueObjects`, `Queries`

Structure manquante pour une architecture 2026 clean.

---

#### 3.14. Tests — améliorer l'organisation

45+ fichiers flat dans `tests/Feature/`. Déplacer dans des sous-dossiers thématiques : `Controllers/`, `Models/`, `Workouts/`.

---

#### 3.15. SQLite de test/dusk traqués dans le repo

`database/database.sqlite`, `database/dusk.sqlite`, `database/testing.sqlite` (634 KB chacun).

```diff
# .gitignore
+ database/*.sqlite
```

---

## 4. Audit de la documentation 📚

### 🔴 Problèmes critiques

---

#### 4.1. Emails placeholder non remplacés

| Fichier | Contenu problématique |
|---|---|
| `SECURITY.md` L18 | `**[INSERT SECURITY EMAIL]**` |
| `CODE_OF_CONDUCT.md` L49 | `**[INSERT CONTACT EMAIL]**` |

**Impact :** Extrêmement non professionnel. Remplacer par une vraie adresse ou un lien vers les Issues GitHub.

---

#### 4.2. Version PHP incorrecte dans README

`README.md` ligne 68 dit `PHP 8.4` — le projet tourne sur **PHP 8.5**.

---

### 🟡 Problèmes hauts

---

#### 4.3. CONTRIBUTING.md — commandes sans Sail

Toutes les commandes n'utilisent **pas** Sail alors que c'est l'environnement standard du projet. Aussi : `composer setup` et `composer dev` ne sont pas définis dans `composer.json`.

---

#### 4.4. Incohérence de langue FR/EN dans la documentation

| Fichier | Langue | Problème |
|---|---|---|
| **README.md** | 🇫🇷 FR | Sous-titre anglais, section « Contributing » en anglais |
| **CHANGELOG.md** | 🇬🇧 EN | 100% anglais alors que le reste est en FR |
| **SECURITY.md** | 🇬🇧 EN | 100% anglais |
| **CODE_OF_CONDUCT.md** | 🇬🇧 EN | 100% anglais |

**Recommandation :** Unifier en **FR** (cohérent avec l'interface 100% française).

---

#### 4.5. CHANGELOG — liens manquants + formatage incohérent

Liens de comparaison manquants pour v1.4.9 → v1.4.13. Versions récentes utilisent Keep a Changelog, versions anciennes des emojis custom.

---

### 🟢 Problèmes moyens

---

#### 4.6. `docs/` — fichiers obsolètes ou redondants

| Fichier | Problème |
|---|---|
| `docs/general/release_notes.md` | 11 lignes, doublon avec CHANGELOG.md |
| `docs/performance/attack_plan.md` | Déjà implémenté, pas marqué « Complété » |
| `docs/ui/design_roast.md` | Références de lignes périmées |
| `docs/security/plan.md` | Tout `[x]`, mais section priorités encore présentée comme à faire |

---

#### 4.7. Roadmap — sections déjà complétées

v1.3 « En Cours » et v1.4 « Planifié » sont 100% complétés. Export PDF listé en v1.5 ET v2.2 (doublon).

---

## 5. Matrice de priorité — Plan d'exécution

| # | Chantier | Impact | Effort | Risque |
|---|---|---|---|---|
| 1 | Supprimer `ci_failure*.log` + gitignore + sqlite | 🟢 Faible | 🟢 5 min | ⚪ Aucun |
| 2 | Fixer emails placeholder (SECURITY, COC) | 🔴 Fort | 🟢 5 min | ⚪ Aucun |
| 3 | Fixer version PHP dans README | 🟡 Moyen | 🟢 2 min | ⚪ Aucun |
| 4 | Mettre à jour CONTRIBUTING avec commandes Sail | 🟡 Moyen | 🟢 15 min | ⚪ Aucun |
| 5 | Unifier la langue de la doc (FR) | 🟡 Moyen | 🟡 1h | ⚪ Aucun |
| 6 | Corriger roadmap + marquer attack_plan complété | 🟡 Moyen | 🟢 15 min | ⚪ Aucun |
| 7 | Ajouter liens CHANGELOG manquants + unifier format | 🟢 Faible | 🟢 15 min | ⚪ Aucun |
| 8 | Squash 73 migrations | 🟡 Moyen | 🟡 1h | 🟡 Moyen |
| 9 | Renommer controllers (singulier) | 🟡 Moyen | 🟡 30 min | 🟡 Moyen |
| 10 | Retirer `AuthorizesRequests` dupliqué | 🟢 Faible | 🟢 5 min | ⚪ Aucun |
| 11 | Décomposer `StatsService` | 🔴 Fort | 🔴 3-4h | 🟡 Moyen |
| 12 | Décomposer `Dashboard.vue` | 🟡 Moyen | 🟡 2h | 🟢 Faible |
| 13 | Extraire logique `WorkoutLine` → service | 🟡 Moyen | 🟡 1-2h | 🟡 Moyen |
| 14 | Créer Enums backend | 🟡 Moyen | 🟡 1-2h | 🟡 Moyen |
| 15 | Créer DTOs pour stats | 🟡 Moyen | 🟡 2h | 🟢 Faible |
| 16 | Réorganiser composants frontend | 🟡 Moyen | 🟡 1h | 🟢 Faible |
| 17 | Strings FR → fichiers i18n | 🟢 Faible | 🟡 1h | ⚪ Aucun |
| 18 | Réorganiser tests Feature | 🟢 Faible | 🟡 30 min | 🟢 Faible |
| 19 | Convertir `DB::` → Eloquent Query Objects | 🟡 Moyen | 🔴 3-4h | 🟡 Moyen |
| 20 | Supprimer/archiver docs obsolètes | 🟢 Faible | 🟢 10 min | ⚪ Aucun |

---

## 6. Structure cible finale

```
app/
├── Actions/             ← ✅ déjà bien structuré
├── Console/Commands/    ← ✅ ok
├── DTOs/                ← 🆕 Data Transfer Objects
├── Enums/               ← 🆕 ExerciseCategory, PRType, GoalType…
├── Exceptions/          ← ✅ ok
├── Filament/            ← ✅ ok
├── Http/
│   ├── Controllers/     ← 🔧 renommer en singulier
│   ├── Middleware/       ← ✅ ok
│   ├── Requests/        ← ✅ ok
│   └── Resources/       ← ✅ ok
├── Jobs/                ← ✅ ok
├── Models/              ← 🔧 alléger WorkoutLine
├── Notifications/       ← ✅ ok
├── Policies/            ← ✅ ok
├── Providers/           ← ✅ ok
├── Queries/             ← 🆕 Query Objects pour stats complexes
├── Services/            ← 🔧 split StatsService en 4-5 services
├── Support/             ← ✅ ok
├── Traits/              ← ✅ ok
└── ValueObjects/        ← 🆕 RecommendedValues, etc.

resources/js/
├── Components/
│   ├── UI/              ← 🔧 consolider (supprimer doublons)
│   ├── Form/            ← 🆕 primitives de formulaire
│   ├── Dashboard/       ← 🔧 enrichir (extraire de Dashboard.vue)
│   ├── Navigation/      ← ✅ ok
│   ├── Stats/           ← ✅ ok
│   ├── Workout/         ← ✅ ok
│   ├── Achievements/    ← ✅ ok
│   └── Goals/           ← ✅ ok
├── Layouts/             ← ✅ ok
├── Pages/               ← ✅ ok (Dashboard.vue → slim composition)
├── composables/         ← ✅ ok
├── directives/          ← ✅ ok
└── Utils/               ← ✅ ok
```

---

## 7. Métriques de qualité visées

| Métrique | Actuel | Cible |
|---|---|---|
| Plus gros fichier backend | `StatsService.php` (758 lignes) | ≤ 200 lignes/service |
| Plus gros composant Vue | `Dashboard.vue` (478 lignes) | ≤ 100 lignes/page |
| Migrations | 73 fichiers | 1 dump + migrations futures |
| Fichiers racine parasites | `ci_failure*.log` (6) | 0 |
| Enums | 0 | 5+ |
| DTOs | 0 | 10+ |
| Nommage controllers | Incohérent | 100% singulier |
| SQLite traqué | 3 fichiers | 0 |
| Docs avec placeholder | 2 (`[INSERT EMAIL]`) | 0 |
| Docs obsolètes | 3+ fichiers dans `docs/` | 0 |
| Cohérence langue docs | Mixed FR/EN | 100% FR |
| PHP Insights Score | 100/100 | Maintenir 100/100 |

---

> **Note :** Chaque chantier peut être exécuté de façon indépendante via des PRs ciblées. L'ordre recommandé suit la section 5 (de haut en bas).
