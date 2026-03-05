## 2025-03-02 - SQL DB::raw() Usage
**Vulnerability:** Found `DB::raw()` being used directly within a standard `select()` method across services and actions (e.g., `StatsService.php`, `FetchSupplementsIndexAction.php`).
**Learning:** Even though the current usage uses static strings and is technically safe, this pattern is inherently fragile and prone to SQL injection vulnerabilities if future developers naively inject or concatenate user input into these closures.
**Prevention:** Rather than using `select(DB::raw(...))`, we should utilize `selectRaw(...)` which supports parameterized bindings and clearly separates standard select clauses from complex, raw SQL aggregates.

## 2026-03-05 - Information Disclosure via Unscoped Validation
**Vulnerability:** Found `exercise_id` filter in `PersonalRecordController::index` being validated with an unscoped `exists:exercises,id` rule.
**Learning:** While the primary query was scoped to the user's PRs, the validation allowed authenticated users to probe for the existence of private exercise IDs belonging to other users (Information Disclosure).
**Prevention:** Always scope `exists` validation rules for filter parameters to the same set of records the user is authorized to access.
