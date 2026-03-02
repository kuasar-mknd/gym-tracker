# ⚡ PERFORMANCE ATTACK PLAN

**Target:** `App\Services\StatsService.php` (and its consumers)
**Bottleneck:** **Cache Thrashing (The "Nuke It All" Strategy)**
**Severity:** HIGH (Architectural Inefficiency)

## 💀 THE ENEMY

Currently, the application uses a "Scorched Earth" policy for cache invalidation.

- **Trigger:** Saving a Workout, Updating a Workout, Deleting a Workout, OR Adding a Body Measurement (Weight).
- **Effect:** calls `clearUserStatsCache($user)`, which nukes:
    - User Dashboard Data
    - Volume Trends (30, 90, 365 days)
    - Muscle Distribution
    - 1RM Progress
    - Daily Volume
    - **AND** Weight History
    - **AND** Body Fat History

**The Absurdity:**
If a user updates their body weight in the "Measurements" tab, the application invalidates their **Workout Volume History**.
If a user fixes a typo in a workout note, the application invalidates their **Body Weight History**.

## ⚔️ THE STRATEGY

We will implement **Surgical Cache Invalidation**.

1.  **Split `clearUserStatsCache`** into:
    - `clearWorkoutRelatedStats($user)`: Clears volume, muscle distribution, 1RM, dashboard.
    - `clearBodyMeasurementStats($user)`: Clears weight history, body fat history, dashboard.

2.  **Update Consumers**:
    - `BodyMeasurementController`: Only call `clearBodyMeasurementStats`.
    - `WorkoutsController` / `UpdateWorkoutAction`: Only call `clearWorkoutRelatedStats`.

3.  **Smart Invalidation (Bonus)**:
    - In `UpdateWorkoutAction`: If only `notes` or `name` changed, DO NOT clear `volume` or `muscle` stats. Only clear `duration` (if `is_finished` changed) or `dashboard` (recent activity).

## 📉 EXPECTED ROI

- **Weight History Cache Hit Rate:** 0% -> 100% (when editing workouts).
- **Volume Stats Cache Hit Rate:** 0% -> 100% (when logging weight).
- **Database Load:** Reduced by ~50% on dashboard loads for active users who track both weight and workouts.
