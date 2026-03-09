## 2025-03-02 - SQL DB::raw() Usage
**Vulnerability:** Found `DB::raw()` being used directly within a standard `select()` method across services and actions (e.g., `StatsService.php`, `FetchSupplementsIndexAction.php`).
**Learning:** Even though the current usage uses static strings and is technically safe, this pattern is inherently fragile and prone to SQL injection vulnerabilities if future developers naively inject or concatenate user input into these closures.
**Prevention:** Rather than using `select(DB::raw(...))`, we should utilize `selectRaw(...)` which supports parameterized bindings and clearly separates standard select clauses from complex, raw SQL aggregates.

## 2025-05-15 - API Authorization Bypass (AdminPolicy)
**Vulnerability:** Found `AdminPolicy` returning `true` for all methods, allowing regular users to list, view, create, update, and delete administrative accounts via the API.
**Learning:** Defaulting policies to `true` for administrative resources is extremely dangerous, particularly when the application's default authentication guard resolves to a regular `User` model.
**Prevention:** Always default policy methods to `false` and implement a strict "deny-by-default" posture for administrative resources.

## 2026-03-09 - Mass Assignment of Denormalized Statistics
**Vulnerability:** Found `total_volume` in `User` and `workout_volume` in `Workout` models were included in the `$fillable` array and `UpdateUserRequest`.
**Learning:** Denormalized fields used for performance (like aggregates) should never be mass-assignable. Even if they are not explicitly exposed in the UI, they can be manipulated via direct API calls if not protected at the model level.
**Prevention:** Strictly exclude denormalized and calculated fields from `$fillable` properties and FormRequest validation rules. Use explicit `increment()`/`decrement()` or dedicated service methods for these updates.
