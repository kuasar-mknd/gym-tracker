## 2026-01-13 - Missing Authentication on API Endpoints
**Vulnerability:** The `exercises` API resource in `routes/api.php` was exposed without any authentication middleware, allowing unauthenticated users to create, update, and delete exercises.
**Learning:** `Route::apiResource` does not apply auth middleware by default. Separating API routes from Web routes (which had `middleware('auth')` group) led to this oversight.
**Prevention:** Always wrap API routes in `middleware('auth:sanctum')` group unless explicitly intended to be public. Default to secure.
