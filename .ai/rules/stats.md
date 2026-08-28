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
