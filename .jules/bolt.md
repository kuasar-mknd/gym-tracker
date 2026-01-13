## 2026-01-13 - Dashboard Performance Bottleneck
**Learning:** The dashboard route was loading the entire `workouts` collection into memory (including all relations) to calculate simple stats like count and "this week" count. This scales O(N) with the number of workouts and causes significant memory usage.
**Action:** Use database aggregates (`count()`, `where()->count()`) and `limit()` for lists instead of filtering in-memory collections.
