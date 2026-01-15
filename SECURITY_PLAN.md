# 🛡️ MASTER SECURITY REMEDIATION PLAN

_Last Audit: 2026-01-15_

## 🚨 CRITICAL (Immediate Action Required)

_Vulnerabilities that pose an immediate threat to data or uptime._

- [ ] **Application-Level DoS (Missing Throttling)**: All state-changing routes (Workouts, Sets, Exercises, Goals, Journal) are authenticated but lack rate limiting. A single authenticated user can automate requests to flood the database with millions of records, leading to service disruption or excessive storage costs.
- [ ] **Broad Proxy Trust**: `bootstrap/app.php` trusts all proxies (`at: '*'`). This is dangerous in production as it allows IP spoofing if the application is not behind a correctly configured load balancer.

## ⚠️ HIGH PRIORITY

_Vulnerabilities that are serious but require specific conditions to exploit._

- [ ] **Insecure Account Linking (Social Login)**: `SocialAuthController.php` links accounts solely based on the email provided by Socialite providers. If a provider's email can be changed without re-verification, an attacker could hijack an existing local account by matching the email.
- [ ] **XSS Pattern Violation**: `BottomNav.vue` uses `v-html="icons[item.icon]"`. While currently sourced from hardcoded data, this pattern is a "silent killer" that can lead to XSS if the `icons` object or `item.icon` key ever becomes user-influenced.

## 🔒 MEDIUM / HARDENING

_Best practices, defense-in-depth, and "bad smells"._

- [ ] **Missing CORS Configuration**: `config/cors.php` is missing. The application relies on framework defaults which might be too permissive, potentially allowing unauthorized cross-origin data access.
- [ ] **Untracked Mass Assignment**: Some models (e.g., `WorkoutLine`, `Set`) have `$fillable` arrays that may include delicate internal state if not strictly audited.
- [ ] **SQL "Raw" Habits**: `StatsService.php` uses `DB::raw()` for calculations. While static strings are currently safe, this encourages a pattern that is highly prone to injection if developers later introduce variable concatenation.

## 📝 NOTES & ARCHITECTURE

- **Global Headers**: `SecurityHeaders` middleware is implemented and active—this is a strong baseline.
- **Login Defense**: Auth routes use `throttle:6,1` and `LoginRequest` has internal rate limiting—excellent.
- **Mass Assignment (user_id)**: Core entities have been hardened against `user_id` spoofing—good progress.
- **General Posture**: The application has a solid authentication foundation (Sanctum/Socialite), but needs more aggressive traffic control (throttling) and stricter pattern enforcement (XSS/SQL).
