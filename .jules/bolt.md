## 2025-05-22 - [Fix N+1 query in workout lines]
**Learning:** Globally appended model attributes (using `$appends`) that perform database queries can cause catastrophic N+1 query explosions when serializing collections. A small collection of 5 items with 3 lines each caused 33 queries.
**Action:** Use conditional attributes in API resources (`$this->whenAppended()`) and remove expensive attributes from the model's global `$appends` array. Explicitly call `->append()` only in specific controllers where the data is actually required.

## 2026-01-20 - [Surgical Cache Invalidation]
**Learning:** Monolithic cache invalidation ("Nuke It All") causes unnecessary database load for statistics that are unaffected by specific user actions. Logging a set only affects volume, not workout duration distribution.
**Action:** Implement granular invalidation methods (e.g., `clearVolumeStats()`, `clearDurationStats()`) and call them selectively in controllers and actions based on the actual data change.

## 2025-05-24 - [Dashboard Cache Bypass]
**Learning:** Fetching raw models with `first()` on the dashboard (e.g., `$user->bodyMeasurements()->latest()->first()`) directly bypasses caching layers specifically built for those metrics in `StatsService` (e.g., `getLatestBodyMetrics()`). This creates a redundant database query on every dashboard load.
**Action:** When gathering metrics for the dashboard, always verify if a cached aggregate or getter method already exists in `StatsService` before querying Eloquent relations directly.
