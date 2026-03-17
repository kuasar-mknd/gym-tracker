# 🏗️ Analyse & Plan de Restructuration Phase 2 — Gym Tracker

> **Objectif :** Finaliser l'architecture 2026 en traitant les derniers restes techniques et en décomposant les plus gros composants restants.

---

## 1. Vue d'ensemble du projet (Après Phase 1)

| Dimension | État actuel |
|---|---|
| **Stack** | Laravel 12 · PHP 8.5 · Inertia v2 · Vue 3 · Tailwind v4 · Filament v5 |
| **Base de données** | MySQL · 1 schéma (migrations squashées) |
| **Models** | 28 Eloquent models |
| **Controllers** | Nommage 100% singulier respecté |
| **Services** | Découpage effectif (`StatsService` éclaté, `RecommendedValuesService` extrait) |
| **Enums** | `ExerciseCategory`, `GoalType`, `PersonalRecordType` implémentés |
| **Frontend** | `Dashboard.vue` décomposé, composants réorganisés (`UI/`, `Form/`, `Navigation/`) |
| **Tests** | Réorganisés par domaines (`Controllers/`, `Models/`, `Services/`) |

---

## 2. Ce qui a été accompli ✅ (Phase 1)

- **Nettoyage racine** : `ci_failure*.log` et SQLite retirés.
- **Documentation** : Mises à jour PHP 8.5, retrait des placeholders, unification FR.
- **Squash des Migrations** : 73 fichiers réduits à un seul dump.
- **Refactorisation Controllers** : Nommage singulier, retrait des `AuthorizesRequests` dupliqués.
- **Décomposition `StatsService`** : Éclaté en 5 services spécialisés.
- **Décomposition `Dashboard.vue`** : Éclaté en 8 sous-composants.
- **Extraction `WorkoutLine`** : Logique de recommandation extraite.
- **Enums Métier** : Remplacement des strings magiques par des Enums PHP 8.1+.
- **Composants Frontend** : Restructuration propre de `resources/js/Components/`.
- **Organisation des Tests** : Rangement par domaine d'application.

---

## 3. Nouveaux Problèmes identifiés & Recommandations (Phase 2)

### 🔴 Priorité Haute

---

#### 3.1. Composants Vue massifs restants

Les deux vues principales du projet sont encore monolithiques :
- `resources/js/Pages/Workouts/Show.vue` (738 lignes)
- `resources/js/Pages/Exercises/Index.vue` (615 lignes)

**Recommandation :**
- Pour `Workouts/Show.vue` : Extraire `WorkoutHeader`, `WorkoutTimer`, `ExerciseList`, `WorkoutControls`.
- Pour `Exercises/Index.vue` : Extraire `ExerciseSearch`, `ExerciseFilters`, `ExerciseCard`.

---

#### 3.2. Utilisation persistante de `DB::table()`

Bien que `StatsService` ait été découpé, ses sous-services (`VolumeStatsService`, `ExerciseStatsService`) ainsi que `GoalService` et `FetchHabitsIndexAction` utilisent encore `DB::table()` au lieu d'Eloquent, ce qui viole les règles architecturales définies.

**Recommandation :**
Convertir les appels `DB::table()` en requêtes Eloquent (`Workout::query()`, `Set::query()`) ou créer des **Query Objects** (ex: `GetMonthlyVolumeQuery`).

---

### 🟡 Priorité Moyenne

---

#### 3.3. Modèle `User` volumineux (286 lignes)

Le fichier `app/Models/User.php` devient encombré par ses nombreuses relations (workouts, goals, habits, measurements, etc.) et ses méthodes helpers.

**Recommandation :**
Créer des traits pour regrouper les relations (ex: `HasWorkouts`, `HasGoals`, `HasHabits`) afin d'alléger le modèle principal.

---

#### 3.4. Manque de DTOs pour les retours de statistiques

Les services de statistiques retournent encore des tableaux typés en PHPDoc (ex: `array<int, array{...}>`). Bien que strict_types soit actif, cela n'offre pas de garantie à l'exécution.

**Recommandation :**
Créer des classes DTO `readonly` (ex: `VolumeTrendPoint`, `MuscleDistributionStat`) pour typer fortement les retours des services de statistiques.

---

### 🟢 Priorité Basse

---

#### 3.5. Nettoyage de la documentation interne

Des fichiers de planification comme `docs/security/plan.md` ou `docs/performance/attack_plan.md` contiennent des tâches déjà terminées.

**Recommandation :**
Archiver ou supprimer les documents de travail obsolètes pour ne garder que la documentation "vivante".

---

## 4. Plan d'exécution Phase 2

| # | Chantier | Impact | Effort |
|---|---|---|---|
| 1 | Convertir `DB::table()` en requêtes Eloquent | 🔴 Fort | 🟡 1h |
| 2 | Décomposer `Workouts/Show.vue` | 🔴 Fort | 🔴 2h |
| 3 | Décomposer `Exercises/Index.vue` | 🟡 Moyen | 🟡 1h |
| 4 | Créer des DTOs pour les statistiques | 🟡 Moyen | 🟡 1.5h |
| 5 | Alléger le modèle `User` (Création de Traits) | 🟢 Faible | 🟢 30m |
| 6 | Archiver la documentation obsolète | 🟢 Faible | 🟢 5m |

> **Stratégie :** Traiter en priorité les problèmes de requêtes DB et la décomposition de la vue d'entraînement (`Workouts/Show.vue`).
