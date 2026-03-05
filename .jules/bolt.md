# Bolt's Performance Journal

## 2025-05-15 - Surgical Cache Invalidation and Column Selection
**Learning:** In a dashboard-heavy application, 'summary' stats are frequently accessed. Using `select()` to limit Eloquent hydration and caching the result significantly reduces database pressure. Furthermore, cache invalidation should be surgical: workout metadata changes (like notes) shouldn't invalidate volume-based trends, and workout updates shouldn't invalidate unrelated body measurement caches.
**Action:** Always use explicit column selection for summary metrics and implement tiered cache invalidation (Metadata vs. Data) to maximize cache hit rates.

## 2025-03-05 - Centralize Chart.js Registration
**Learning:** Repetitive imports and global configuration calls across multiple async-loaded Vue components (like Chart.js component registrations) can cause unnecessary script execution overhead and bloated individual chunk sizes. In complex dashboards, each chart component importing and registering `ChartJS` components separately creates redundant work.
**Action:** Centralize global configurations (like `ChartJS.register(...)`) in a single initialization file (e.g. `resources/js/chartSetup.js`) and import it once in the main application entry point. This ensures configurations run only once and are shared across all dynamic imports, improving startup performance and reducing bundle redundancy.
