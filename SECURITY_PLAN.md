# 🛡️ MASTER SECURITY REMEDIATION PLAN

_Last Audit: 2026-01-15_

---

## 🚨 CRITICAL (Immediate Action Required)

_Vulnerabilities that pose an immediate threat to data or uptime._

- [x] **API Route Throttling Missing** ✅ FIXED: All API routes (`routes/api.php`) are protected by `auth:sanctum` but **lack rate limiting**. An authenticated user can flood endpoints (`/api/v1/workouts`, `/api/v1/sets`, `/api/v1/exercises`) with automated requests, leading to database bloat, excessive storage costs, or denial of service.
    - **File**: `routes/api.php`
    - **Fix**: Add `throttle:60,1` middleware to the API group.

- [x] **Debug Mode Enabled in Local `.env`** ⚠️ DOCUMENTED: The `.env` file has `APP_DEBUG=true`. While acceptable for local development, this file should **never** be committed or used as a template for production. Debug mode exposes stack traces, environment variables, and database credentials.
    - **File**: `.env` (line 4)
    - **Fix**: Ensure production `.env` has `APP_DEBUG=false`. Add `.env` validation to deployment scripts.

---

## ⚠️ HIGH PRIORITY

_Vulnerabilities that are serious but require specific conditions to exploit._

- [x] **Email Verification Bypass in Local Environment** ✅ FIXED: `SocialAuthController.php` (line 37) allows email verification to be bypassed when `app()->environment('local')`. This is intentional for local dev but **must be tested to ensure production is not accidentally set to `local`**.
    - **File**: `app/Http/Controllers/Auth/SocialAuthController.php:37`
    - **Fix**: Add logging/alerting if email verification is skipped. Ensure CI/CD sets `APP_ENV=production`.

- [x] **CSP Nonces Implemented** ✅ FIXED: Created `CspNonce` middleware that generates unique nonces per request. `SecurityHeaders` now uses dynamic nonces instead of `unsafe-inline`/`unsafe-eval`. Vite configured via `useCspNonce()`.
    - **File**: `app/Http/Middleware/SecurityHeaders.php:24`
    - **Fix**: Transition to nonce-based CSP and remove `unsafe-inline`/`unsafe-eval`. This may require Vite configuration changes.

- [x] **VAPID Keys Committed to `.env`** ⚠️ DOCUMENTED: The `.env` file contains `VAPID_PUBLIC_KEY` and `VAPID_PRIVATE_KEY`. While not critical secrets, these should be treated as environment-specific and not committed.
    - **File**: `.env` (lines 69-70)
    - **Fix**: Move VAPID keys to environment-specific secrets management (e.g., CI/CD secrets, `.env.local`).

---

## 🔒 MEDIUM / HARDENING

_Best practices, defense-in-depth, and "bad smells"._

- [x] **SQL `DB::raw()` Usage** ✅ FIXED: `StatsService.php` and `AchievementService.php` use `DB::raw()` for aggregate calculations. While currently safe (static strings), this pattern is prone to injection if future developers concatenate user input.
    - **Files**: `app/Services/StatsService.php:59,186,193`, `app/Services/AchievementService.php:60`
    - **Fix**: Add PHPDoc warning comments. Consider using `DB::selectRaw()` with bound parameters if user input is ever introduced.

- [x] **CORS `allowed_origins` Tied to `APP_URL`** ⚠️ DOCUMENTED: CORS is configured to only allow `APP_URL`. This is secure but may break if the frontend and API are on different domains.
    - **File**: `config/cors.php:22`
    - **Fix**: Document this behavior. If multi-origin support is needed, use an explicit whitelist rather than `*`.

- [x] **`supports_credentials` is `false` in CORS** ⚠️ DOCUMENTED: If cookies or auth headers need to be sent cross-origin, this will block them.
    - **File**: `config/cors.php:32`
    - **Fix**: Set to `true` if cross-origin authenticated requests are needed, but ensure `allowed_origins` is explicit (not `*`).

---

## ✅ VERIFIED SECURE (No Action Required)

_Items that were audited and found to be properly implemented._

| Category                        | Status | Details                                                                                                                       |
| ------------------------------- | ------ | ----------------------------------------------------------------------------------------------------------------------------- |
| **Route Protection**            | ✅     | All user routes use `auth` or `auth:sanctum` middleware                                                                       |
| **Web Route Throttling**        | ✅     | State-changing web routes wrapped in `throttle:60,1`                                                                          |
| **Login Rate Limiting**         | ✅     | Auth routes use `throttle:6,1` + `LoginRequest` internal limiting                                                             |
| **Password Hashing**            | ✅     | `User` model casts `password` as `hashed`                                                                                     |
| **Mass Assignment (`user_id`)** | ✅     | Core models (`Set`, `WorkoutLine`, `Workout`) do NOT include `user_id` in `$fillable`                                         |
| **Proxy Trust**                 | ✅     | `bootstrap/app.php` trusts only private IP ranges                                                                             |
| **Security Headers**            | ✅     | `SecurityHeaders` middleware sets `X-Frame-Options`, `X-XSS-Protection`, `X-Content-Type-Options`, `Referrer-Policy`, and CSP |
| **XSS (`v-html`)**              | ✅     | No `v-html` directives found in `resources/js/**/*.vue`                                                                       |
| **Command Injection**           | ✅     | No `exec()`, `shell_exec()`, `system()` calls in app code                                                                     |
| **Hardcoded Secrets**           | ✅     | No hardcoded API keys or secrets in app code                                                                                  |

---

## 📝 NOTES & ARCHITECTURE

### Overall Security Posture: **GOOD** with minor gaps

**Strengths:**

- Solid authentication foundation (Sanctum + Socialite)
- CSRF protection via Laravel defaults
- Security headers middleware is well-implemented
- Mass assignment is properly constrained
- Web routes have appropriate throttling

**Areas for Improvement:**

1. API throttling is the most critical gap
2. CSP hardening requires frontend tooling changes
3. Local `.env` should not be used as production template

### Recommended Priority Order:

1. Add `throttle:60,1` to API routes (5 min fix)
2. Audit production `.env` for `APP_DEBUG=false` and `APP_ENV=production`
3. Plan CSP nonce migration (requires Vite + middleware changes)
4. Add SQL injection warning comments to raw queries
