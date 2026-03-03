# Bolt's Performance Journal

## 2025-05-15 - Surgical Cache Invalidation and Column Selection
**Learning:** In a dashboard-heavy application, 'summary' stats are frequently accessed. Using `select()` to limit Eloquent hydration and caching the result significantly reduces database pressure. Furthermore, cache invalidation should be surgical: workout metadata changes (like notes) shouldn't invalidate volume-based trends, and workout updates shouldn't invalidate unrelated body measurement caches.
**Action:** Always use explicit column selection for summary metrics and implement tiered cache invalidation (Metadata vs. Data) to maximize cache hit rates.
