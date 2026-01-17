## 2026-01-13 - Missing Authentication on API Endpoints
**Vulnerability:** The `exercises` API resource in `routes/api.php` was exposed without any authentication middleware, allowing unauthenticated users to create, update, and delete exercises.
**Learning:** `Route::apiResource` does not apply auth middleware by default. Separating API routes from Web routes (which had `middleware('auth')` group) led to this oversight.
**Prevention:** Always wrap API routes in `middleware('auth:sanctum')` group unless explicitly intended to be public. Default to secure.

## 2026-01-14 - Information Disclosure via Shared Cache
**Vulnerability:** The `WorkoutsController::show` method cached the list of exercises using a global key (`exercises_list`), which combined with an unscoped query (`Exercise::all()`), exposed private user exercises to other users.
**Learning:** Performance optimizations (caching) can introduce IDOR vulnerabilities if the scope of the data (User vs System) is not considered in the cache key.
**Prevention:** Ensure cache keys for user-specific data include the user ID (e.g., `key_{user_id}`). Always verify that queries filter by ownership (`where('user_id', Auth::id())`).
