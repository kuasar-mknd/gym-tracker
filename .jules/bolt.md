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

## 2024-05-19 - Dashboard Deferred Loading
**Learning:** When using Inertia 2.0 `<Deferred>` components on the frontend, the backend controller props are also explicitly wrapped in `Inertia::defer(fn () => ...)`. However, some controllers (like `DashboardController`) might already have their backend props correctly wrapped in `Inertia::defer`, while the frontend might be missing the `<Deferred>` components wrapper, causing them to load instantly when they could be delayed.
**Action:** Always verify that both the frontend Vue components are using `<Deferred>` appropriately and the backend controller uses `Inertia::defer` when aiming to defer heavy logic to improve the Time to First Byte (TTFB).

## 2026-03-13 - [Consolidating Deferred Props]
**Learning:** Multiple deferred Inertia props that call the same service method result in redundant backend execution and cache lookups during the async request. Consolidating them into a single object prop ensures the heavy computation runs only once.
**Action:** Always group related deferred data into a single object if they derive from the same source action or service call.

## 2024-03-24 - Eloquent Hydration vs DB Builder for Statistical Aggregation
**Learning:** Hydrating Eloquent models (`$user->workouts()->get()`) and instantiating Carbon objects purely to calculate date/time differences for charts introduces massive performance overhead. In a local benchmark on 1000 records, the Eloquent+Carbon approach took ~4800ms, while using `DB::table()->get()` with native `strtotime()` and `substr()` string parsing took ~110-380ms—a 90%+ reduction in loop execution time.
**Action:** For purely statistical loops where relationships, mutators, and model logic are unnecessary, bypass Eloquent entirely. Use the `DB` facade to fetch `stdClass` objects and rely on native PHP string/datetime functions for aggregation parsing.

## 2026-03-22 - [Removing Unused Legacy Performance Methods]
**Learning:** When refactoring multiple analytical queries into a single consolidated query (like combining `getDurationDistribution` and `getTimeOfDayDistribution` into `getWorkoutDistributions`), it's crucial to remove the old, unused methods to prevent them from being accidentally called later, which would reintroduce the performance bottleneck.
**Action:** Always do a codebase search (`grep`) for the methods being replaced and remove them from services and actions if they have zero active calls after refactoring.
