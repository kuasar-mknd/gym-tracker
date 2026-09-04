---
paths:
  - 'app/Services/Stats/**'
---

# Stats

## Ne pas joindre exercises dans une agrégation de séries
Joindre `exercises` à `workout_lines`/`sets` en groupant ou ordonnant sur `exercises.*` fait choisir à MySQL `exercises_user_id_category_name_index` comme TÊTE de plan : il tire alors les lignes de chaque exercice, tous utilisateurs confondus, et n'applique le filtre qu'à la fin. Une borne de 30 jours devient décorative.

Agréger par `workout_lines.exercise_id`, puis relire les libellés à part par clef primaire. Deux requêtes, chacune servie exactement.

Filtrer sur `workout_lines.user_id` / `workout_lines.workout_started_at` (dénormalisés depuis #1601) plutôt que de joindre `workouts` — index `workout_lines_user_date_index`.

Vu deux fois : `FetchCalendarEventsAction` (#1611) et `ExerciseStatsService` (#1612).

## Une clef de statistique passe par ClesDeStats, jamais par un littéral
Chaque clef porte une version par utilisateur (`ClesDeStats::seances()` ou `::mesures()`) ; invalider, c'est `invaliderSeances()` / `invaliderMesures()`, jamais `Cache::forget()` d'une clef énumérée à la main (une liste avait déjà oublié une entrée, #1502). Une nouvelle statistique choisit sa famille et ne touche pas au gestionnaire.
La garde `LeCacheOublieCeQuIlEcritTest` vérifie qu'après l'invalidation chaque lecteur écrit une clef neuve : un littéral `"stats.…"` sans version la fait tomber. Dans un test, relire la clef par `ClesDeStats` au moment de l'assertion, pas depuis une variable remplie avant l'invalidation. Depuis #1716 (2026-09-04).

