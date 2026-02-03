## 2026-01-13 - Dashboard Performance Bottleneck
**Learning:** The dashboard route was loading the entire `workouts` collection into memory (including all relations) to calculate simple stats like count and "this week" count. This scales O(N) with the number of workouts and causes significant memory usage.
**Action:** Use database aggregates (`count()`, `where()->count()`) and `limit()` for lists instead of filtering in-memory collections.

## 2026-01-20 - Chart.js Bundle Optimization
**Learning:** `chart.js` is a heavy library (~180KB). Static imports in components like `Stats/Index.vue` cause it to be included in the main page bundle or shared vendor chunk that blocks rendering if not carefully managed.
**Action:** Use `defineAsyncComponent` for all chart components. This splits them into separate chunks that are loaded on demand, reducing the initial JavaScript payload for pages that use them. Verified with `vite build`.

## 2026-01-27 - BodyPartMeasurement Index Optimization
**Learning:** Laravel's `groupBy` on a collection preserves the original keys, which can cause unexpected behavior if you assume keys are re-indexed (0, 1, ...). However, `skip(1)->first()` is robust against this. Also, `json_decode` in tests converts `5.0` to `5`, causing strict `toBe(5.0)` assertions to fail.
**Action:** Use `values()` after `groupBy` if you need re-indexed keys, or use methods like `skip()` that don't rely on keys. Use `toEqual()` for numeric assertions in JSON responses.

## 2026-02-03 - Dashboard Eager Loading & Limits Optimization
**Learning:** The dashboard was fetching 5 workouts, 5 PRs, and 3 goals while the UI only displayed 3, 2, and 2 respectively. Also, `with('workoutLines')` was used just to get the count in the UI, causing unnecessary database overhead and model hydration.
**Action:** Use `withCount('workoutLines')` and align `limit()`/`take()` with UI requirements. Updated frontend to use `workout_lines_count`.
