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

## 2026-03-23 - [Consolidating Statistics & Deferred Props]
**Learning:** When a page requires multiple independent statistics (like frequency, volume, and duration trends), fetching them through separate service calls results in multiple database queries and potentially redundant data grouping in PHP. Consolidating these into grouped analytical methods (e.g., `getMonthlyWorkoutStats`) allows for a single database query and a single loop over the results, significantly reducing overhead. Grouping these results into a single deferred Inertia prop further optimizes the frontend by reducing the number of XHR requests and ensuring consistent loading states.
**Action:** Identify pages with multiple deferred props or statistics and consolidate their underlying data-fetching logic into grouped service methods and single deferred props.

## 2026-03-24 - [Consolidating Stats Page Props]
**Learning:** Having multiple independent deferred Inertia props (e.g., 6 separate charts) leads to an equal number of asynchronous XHR requests on page load. This increases HTTP overhead and can lead to a "pop-in" effect where charts load at different times.
**Action:** Consolidate related deferred props into logical groups (e.g., `workoutStats`, `bodyStats`) at the controller level. This reduces the number of requests and ensures related visualizations appear together. Always update the corresponding cache invalidation logic to include these new consolidated keys.

## 2026-03-25 - [Optimizing Consolidated Body Metrics Query]
**Learning:** Consolidation of deferred props at the controller level provides a great opportunity to also consolidate the underlying database queries. Fetching weight and body fat history separately caused two queries to the same `body_measurements` table for the same timeframe.
**Action:** Fetch the shared model collection once in the service and use PHP-side filtering/mapping to populate the DTOs, reducing database load while improving frontend responsiveness.

## 2026-03-26 - [Consolidating Mixed Deferred Props]
**Learning:** Even when deferred data comes from different sources (e.g., cached exercise lists and analytical charts), consolidating them into a single deferred Inertia prop provides a significant performance win. It reduces the number of XHR requests, cutting down on HTTP overhead and TLS handshakes, which is critical for mobile performance. It also ensures the UI transitions from "loading" to "ready" in a single, synchronized step rather than multiple staggered "pops."
**Action:** Always look for opportunities to merge all deferred props on a page into a single `deferredData` object, even if they represent different logical domains.

## 2025-03-30 - Redundant whereHas with join
**Learning:** Found a case where Eloquent `whereHas` was used alongside a direct `join` to the exact same related table in order to sort. This caused a costly redundant `EXISTS()` subquery.
**Action:** When a query already has an `INNER JOIN` to a related table (e.g., for ordering), avoid using `whereHas` for filtering. Instead, apply direct `where` clauses onto the joined table's columns to eliminate the subquery execution and significantly improve performance.

## 2026-03-31 - [Collection Filtering inside Loops]
**Learning:** Performing `$collection->filter()` inside a loop (like a 7-day or 30-day date loop) evaluates the closure on the entire collection every iteration, creating an O(N * D) complexity where N is the number of records and D is the number of days.
**Action:** Always pre-process collections before the loop using `$collection->groupBy()` based on the loop's key (e.g., date string). This turns the data preparation into an O(N) operation and the loop lookups into O(1) operations.

## 2026-04-01 - [Consolidating & Optimizing Dashboard Stats]
**Learning:** Consolidating multiple deferred Inertia props (e.g., weekly volume and workout distributions) into a single analytical prop significantly reduces HTTP overhead and XHR requests. Furthermore, combining this with `toBase()` queries and native PHP date parsing (`strtotime`) instead of Eloquent models and Carbon objects inside analytical loops provides a massive boost in memory efficiency and execution speed.
**Action:** Always look to merge deferred props that load simultaneously and ensure their underlying queries use `toBase()` to avoid unnecessary model hydration in analytical paths.

## 2026-04-02 - [Granular Deferred Loading for Stats Dashboard]
**Learning:** Consolidating multiple independent deferred props into a single `deferredData` object reduces HTTP overhead and background requests (Inertia 2.0). Combining this with the removal of large, page-level `<Deferred>` wrappers allows immediate, lightweight metadata (like latest weight and exercise counts) to render instantly. This improves perceived performance and prevents "all-or-nothing" loading states for the user.
**Action:** Always consolidate deferred props at the controller level but keep their frontend `<Deferred>` wrappers localized to the specific components that need them. This ensures immediate data is never blocked by heavy analytical queries.
## 2024-05-18 - Optimized RecommendedValuesService Cache Put
**Learning:** Performing `Cache::put()` operations inside a loop introduces N+1 caching overhead.
**Action:** Replaced the loop-based `Cache::put()` calls with an array collection pattern and a single `Cache::putMany()` call to reduce cache interaction overhead and improve performance.

## 2025-06-03 - [whereHas vs INNER JOIN on belongsTo]
**Learning:** Using `whereHas` on a simple `belongsTo` relationship (like checking if a workout belongs to a user inside `WorkoutLine`) creates an `EXISTS()` subquery. In MySQL, especially as the main table grows, this subquery evaluation can be significantly slower than a direct `INNER JOIN` to the related table combined with a `WHERE` clause.
**Action:** For simple parent relationship filtering or sorting, replace `whereHas` with `join()` and `where()` on the joined columns. Always ensure to add `select('main_table.*')` to prevent the joined columns from overriding model attributes.
## 2025-06-15 - [Carbon vs Native Date Math in Loops]
**Learning:** Instantiating heavy objects like `Carbon` inside large loops (e.g., calculating maximum consecutive streaks over hundreds or thousands of dates) causes significant `O(N)` object instantiation overhead and memory pressure.
**Action:** Replace `Carbon::parse()` and related methods (like `diffInDays()`) with native PHP timestamp math (`strtotime`, `abs`, `round(diff / 86400)`) within performance-critical loops that evaluate date sequences. Track variables between iterations to eliminate redundant parsing.

## 2025-06-16 - [Optimizing workout date extraction in AchievementService]
**Learning:** Using `toBase()` before `pluck()` on a datetime column prevents Eloquent from hydrating dummy models and instantiating Carbon objects for every row during serialization. Using `substr($date, 0, 10)` for date extraction is significantly more efficient than using Carbon's `format()`. For 1000 records, this reduced memory by ~77% and time by ~45%.
**Action:** Always use `toBase()` when only raw column values are needed from a large dataset, especially for casted columns like datetimes.

## 2024-05-24 - Upsert instead of updateOrCreate in Loops
**Learning:** `updateOrCreate` inside a loop fires individual `SELECT` and `INSERT`/`UPDATE` queries per iteration, leading to N+1 performance bottlenecks. Bulk `upsert()` resolves this but skips Eloquent model lifecycle events (like `saving`, `updated`) and Observers.
**Action:** Always prefer `upsert()` for batched insertions/updates to optimize database queries, but strictly verify that the target model does not rely on observers or lifecycle events before applying the optimization. When using `upsert()` to replace relationship methods, remember to explicitly include the foreign key (e.g., `user_id`) in the payload.

## 2026-04-10 - [Native PHP Date Math in Analytical Loops]
**Learning:** Instantiating 'Carbon' objects inside large analytical loops causes significant O(N) object instantiation overhead and memory pressure. Native PHP 'strtotime()' and 'date()' are 80-90% faster in these scenarios.
**Action:** Replace 'Carbon::parse()' with native 'strtotime()' and 'date()' within performance-critical loops that evaluate date sequences for charts or distributions.

## 2024-05-25 - [toBase() Type Safety with Datetimes]
**Learning:** Using `toBase()->value('column')` on a datetime column bypasses Eloquent's attribute casting and returns a raw string instead of a `Carbon` instance. This can break existing logic that relies on `instanceof Carbon` checks or Carbon-specific methods.
**Action:** When using `toBase()` for performance on datetime columns, always explicitly parse the result with `Carbon::parse()` if the subsequent logic expects a Carbon object, or rely on native PHP string/date functions for even better performance.

## 2024-05-26 - [Consolidating Collection Methods into Single Loop]
**Learning:** Chaining multiple Laravel collection methods like `map()`, `filter()`, and `values()` across the same dataset creates multiple O(N) iterations. When these methods also perform redundant expensive operations like `strtotime()` and `date()` parsing, the performance penalty is compounded.
**Action:** Consolidate multiple collection transformations into a single `foreach` loop. Parse raw data (like datetimes) once and reuse the results to populate multiple destination arrays, reducing both iteration overhead and redundant function calls.

## 2026-04-14 - [Consolidating Collection Chains to Foreach Loops]
**Learning:** Chaining multiple collection methods (`map`, `filter`, `toArray`) on the same dataset creates multiple O(N) iterations. In high-frequency statistical methods like `getVolumeTrend` or `getVolumeHistory`, this adds significant function call overhead and memory pressure.
**Action:** Consolidate multiple collection transformations into a single `foreach` loop to reduce execution time and memory overhead, especially when processing analytical data. Reuse expensive results (e.g., date parsing) within the single pass to further minimize redundant processing.
