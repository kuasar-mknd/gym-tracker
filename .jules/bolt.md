## 2026-01-13 - Dashboard Performance Bottleneck
**Learning:** The dashboard route was loading the entire `workouts` collection into memory (including all relations) to calculate simple stats like count and "this week" count. This scales O(N) with the number of workouts and causes significant memory usage.
**Action:** Use database aggregates (`count()`, `where()->count()`) and `limit()` for lists instead of filtering in-memory collections.

## 2026-01-20 - Chart.js Bundle Optimization
**Learning:** `chart.js` is a heavy library (~180KB). Static imports in components like `Stats/Index.vue` cause it to be included in the main page bundle or shared vendor chunk that blocks rendering if not carefully managed.
**Action:** Use `defineAsyncComponent` for all chart components. This splits them into separate chunks that are loaded on demand, reducing the initial JavaScript payload for pages that use them. Verified with `vite build`.
