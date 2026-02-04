## 2026-01-13 - Dashboard Performance Bottleneck

**Learning:** The dashboard route was loading the entire `workouts` collection into memory (including all relations) to calculate simple stats like count and "this week" count. This scales O(N) with the number of workouts and causes significant memory usage.
**Action:** Use database aggregates (`count()`, `where()->count()`) and `limit()` for lists instead of filtering in-memory collections.

## 2026-01-20 - Chart.js Bundle Optimization

**Learning:** `chart.js` is a heavy library (~180KB). Static imports in components like `Stats/Index.vue` cause it to be included in the main page bundle or shared vendor chunk that blocks rendering if not carefully managed.
**Action:** Use `defineAsyncComponent` for all chart components. This splits them into separate chunks that are loaded on demand, reducing the initial JavaScript payload for pages that use them. Verified with `vite build`.

## 2026-01-27 - BodyPartMeasurement Index Optimization

**Learning:** Laravel's `groupBy` on a collection preserves the original keys, which can cause unexpected behavior if you assume keys are re-indexed (0, 1, ...). However, `skip(1)->first()` is robust against this. Also, `json_decode` in tests converts `5.0` to `5`, causing strict `toBe(5.0)` assertions to fail.
**Action:** Use `values()` after `groupBy` if you need re-indexed keys, or use methods like `skip()` that don't rely on keys. Use `toEqual()` for numeric assertions in JSON responses.

## 2026-02-05 - Dashboard Payload and Query Optimization

**Learning:** The dashboard was loading full collections of `workoutLines` just to show a count-based icon. This causes O(N) memory and hydration overhead. Also, fetching more records than visible (e.g. 5 vs 2) bloats the Inertia payload.
**Action:** Use Eloquent `withCount()` to only fetch the integer count. Align database `limit()` with UI `.slice()` and then remove the redundant frontend slicing to minimize JSON payload and hydration time.

## 2026-02-04 - Surgical Cache Invalidation

**Learning:** Nuke-it-all cache strategies (clearing everything on any change) cause unnecessary database load (cache thrashing). Using Eloquent's `wasChanged()` in Actions allows for precise invalidation of only the affected cache keys (e.g., changing 'notes' shouldn't clear 'volume trends').
**Action:** Implement granular cache clearing methods in Services and call them conditionally in Actions based on `$model->wasChanged(['attributes'])`.

## 2025-05-15 - [Inertia Hydration Bottleneck & CI Stability]

**Learning:** Eager loading full collections (e.g., `with('workoutLines')`) just to check counts or presence in Inertia.js components is a massive hydration bottleneck. It inflates the JSON payload and server-side memory usage. Additionally, class-based Dusk tests in this repo do NOT inherit global Pest traits and must explicitly include `DatabaseMigrations`.
**Action:** Use `withCount()` for summary views and always verify that class-based tests have necessary traits for CI database initialization.
