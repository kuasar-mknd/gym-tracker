## 2025-05-22 - [Fix N+1 query in workout lines]
**Learning:** Globally appended model attributes (using `$appends`) that perform database queries can cause catastrophic N+1 query explosions when serializing collections. A small collection of 5 items with 3 lines each caused 33 queries.
**Action:** Use conditional attributes in API resources (`$this->whenAppended()`) and remove expensive attributes from the model's global `$appends` array. Explicitly call `->append()` only in specific controllers where the data is actually required.

## 2026-01-20 - [Surgical Cache Invalidation]
**Learning:** Monolithic cache invalidation ("Nuke It All") causes unnecessary database load for statistics that are unaffected by specific user actions. Logging a set only affects volume, not workout duration distribution.
**Action:** Implement granular invalidation methods (e.g., `clearVolumeStats()`, `clearDurationStats()`) and call them selectively in controllers and actions based on the actual data change.

## 2025-05-24 - [Dashboard Cache Bypass]
**Learning:** Fetching raw models with `first()` on the dashboard (e.g., `$user->bodyMeasurements()->latest()->first()`) directly bypasses caching layers specifically built for those metrics in `StatsService` (e.g., `getLatestBodyMetrics()`). This creates a redundant database query on every dashboard load.
**Action:** When gathering metrics for the dashboard, always verify if a cached aggregate or getter method already exists in `StatsService` before querying Eloquent relations directly.

## 2026-03-10 - [Asynchronous Goal/Achievement Sync]
**Learning:** Performing heavy goal and achievement synchronization in synchronous model observers blocks the user's request (e.g. saving a set). For a typical workout with 20-30 sets, this adds significant overhead.
**Action:** Offload synchronization logic to unique background jobs (`SyncUserAchievements`, `SyncUserGoals`) to keep the main request thread fast.

## 2026-03-10 - [SQL-based Aggregate Calculation]
**Learning:** Loading all of a user's workout data into PHP memory to find a maximum value (e.g. max volume goal) is inefficient and memory-intensive as data grows.
**Action:** Always perform aggregate calculations (MAX, SUM, AVG) directly in SQL using database grouping and sorting for maximum performance and minimal memory footprint.
