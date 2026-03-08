## 2025-03-02 - SQL DB::raw() Usage
**Vulnerability:** Found `DB::raw()` being used directly within a standard `select()` method across services and actions (e.g., `StatsService.php`, `FetchSupplementsIndexAction.php`).
**Learning:** Even though the current usage uses static strings and is technically safe, this pattern is inherently fragile and prone to SQL injection vulnerabilities if future developers naively inject or concatenate user input into these closures.
**Prevention:** Rather than using `select(DB::raw(...))`, we should utilize `selectRaw(...)` which supports parameterized bindings and clearly separates standard select clauses from complex, raw SQL aggregates.

## 2025-05-15 - API Authorization Bypass (AdminPolicy)
**Vulnerability:** Found `AdminPolicy` returning `true` for all methods, allowing regular users to list, view, create, update, and delete administrative accounts via the API.
**Learning:** Defaulting policies to `true` for administrative resources is extremely dangerous, particularly when the application's default authentication guard resolves to a regular `User` model.
**Prevention:** Always default policy methods to `false` and implement a strict "deny-by-default" posture for administrative resources.
