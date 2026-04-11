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

## 2026-03-10 - Inconsistent Password Validation & Redundant Hashing
**Vulnerability:** Found that API user creation and update endpoints enforced only a minimum length of 8 characters, bypassing the stricter production rules defined in `Password::defaults()`. Additionally, controllers were manually hashing passwords despite the `User` model using a `hashed` cast.
**Learning:** Hardcoding validation rules in FormRequests leads to security gaps when global policies change. Redundant manual hashing in controllers is a "bad smell" that increases the risk of double-hashing bugs and architectural leakage.
**Prevention:** Always utilize `Password::defaults()` in all authentication-related FormRequests to enforce a consistent security posture. Rely on Model attribute casting (`hashed`) to centralize hashing logic and maintain a clean separation of concerns.

## 2026-03-19 - Redundant Hashing & Modern Security Headers
**Vulnerability:** Multiple controllers and actions were manually calling `Hash::make()` on passwords before saving models that already had the `hashed` attribute cast. Also, legacy `X-XSS-Protection: 1; mode=block` was used.
**Learning:** Redundant manual hashing is a "bad smell" that can lead to confusion and is unnecessary in modern Laravel (10+) when using the `hashed` cast, which is smart enough to avoid double-hashing. Legacy XSS auditors in browsers have been deprecated as they can be exploited; `0` is now the recommended value when a CSP is present.
**Prevention:** Centralize hashing logic in the model using the `hashed` cast and disable legacy XSS auditors in favor of a robust Content Security Policy.

## 2026-03-22 - Broken Function Level Authorization in Resource Controllers
**Vulnerability:** Found `WorkoutController@store` (and potentially others like `PlateController`) missing an explicit `$this->authorize('create', ...)` call. These controllers relied on `FormRequest::authorize()` which returned `true`, effectively bypassing the `create` ability in their respective Policies.
**Learning:** Relying solely on the `authorize` method of a `FormRequest` is dangerous if it doesn't actually check the policy. It creates a gap where resource creation is not governed by the central Policy logic, which might include important business rules or permission checks beyond simple ownership.
**Prevention:** Always include an explicit `$this->authorize('create', Model::class)` call in controller `store` methods. Ensure Policies are the single source of truth for authorization logic.

## 2026-03-23 - Broken Function Level Authorization in Resource Controllers (Continued)
**Vulnerability:** Found  (both web and API) and  missing explicit `$this->authorize('create', ...)` calls. These controllers relied on `FormRequest::authorize()` which returned `true`, bypassing the `create` ability in their respective Policies.
**Learning:** This is a recurring issue. Relying solely on the `authorize` method of a `FormRequest` is a consistent vulnerability pattern in this codebase.
**Prevention:** We must always include an explicit `$this->authorize('create', Model::class)` call in controller `store` methods, even if a FormRequest is used.

## 2026-03-23 - Broken Function Level Authorization in Resource Controllers (Continued)
**Vulnerability:** Found `PlateController@store` (both web and API) and `WorkoutTemplatesController@store` missing explicit `$this->authorize('create', ...)` calls. These controllers relied on `FormRequest::authorize()` which returned `true`, bypassing the `create` ability in their respective Policies.
**Learning:** This is a recurring issue. Relying solely on the `authorize` method of a `FormRequest` is a consistent vulnerability pattern in this codebase.
**Prevention:** We must always include an explicit `$this->authorize('create', Model::class)` call in controller `store` methods, even if a FormRequest is used.

## 2026-03-24 - Broken Function Level Authorization in Resource Controllers (Continued)
**Vulnerability:** Found `WorkoutsController@update` and `ExerciseController@store` (both web and API) missing explicit `$this->authorize()` calls. They relied on `FormRequest` authorization which, if misconfigured or bypassed, could allow unauthorized resource manipulation.
**Learning:** This pattern of missing explicit controller-level authorization remains a common source of potential BOLA/BFLA in this project.
**Prevention:** Enforce defense-in-depth by always calling `$this->authorize()` at the start of every resource controller action, regardless of `FormRequest` checks.

## 2026-03-28 - Broken Function Level Authorization in Nested Resource Creation
**Vulnerability:** `HabitLogController@store` was missing a check to ensure the `habit_id` provided by the user actually belonged to them, relying on a policy that always returned `true` for `create`.
**Learning:** For nested resources, simply checking if a user can "create" a resource of that type is insufficient. The authorization must be contextual, verifying ownership of the parent resource (the Habit) before allowing the creation of the child resource (the Log).
**Prevention:** Always pass the parent model instance to the `$this->authorize('create', [Child::class, $parent])` call in the controller and implement corresponding ownership logic in the Policy.

## 2026-03-26 - Insecure Direct Object Reference (IDOR) on API Updates
**Vulnerability:** Multiple API endpoints (`PersonalRecordController@update`, `IntervalTimerController@update`, `GoalController@update`) were missing explicit `$this->authorize('update', $model)` calls, relying solely on `FormRequest::authorize()`.
**Learning:** Relying exclusively on FormRequest authorization is a recurring weakness that bypasses the centralized Policy logic if the FormRequest is not correctly implemented or is bypassed by other middlewares.
**Prevention:** Always call `$this->authorize()` at the beginning of controller actions for all destructive or modification operations to ensure consistent ownership and permission checks.

## 2026-03-27 - Missing Function Level Authorization in API Controllers
**Vulnerability:** Found `MacroCalculationController` (API) missing explicit `$this->authorize()` calls in `index()` and `store()` methods, unlike its web counterpart and other API resource controllers.
**Learning:** Even when queries are scoped to the authenticated user (e.g., `$user->macroCalculations()`), missing explicit authorization bypasses the central Policy logic. This can lead to security gaps if the Policy is intended to enforce broader restrictions (e.g., account status, feature flags, or global "deny" rules) beyond simple ownership.
**Prevention:** Enforce defense-in-depth by always calling `$this->authorize()` at the start of every resource controller action (including `index` and `store`), regardless of relationship scoping or `FormRequest` checks.

## 2026-03-29 - Broken Function Level Authorization via HasMiddleware Anti-pattern
**Vulnerability:** Found `AchievementController` and `AdminController` (API) relying solely on the `HasMiddleware` interface with `can` middleware for authorization, missing explicit `$this->authorize()` calls in their CRUD methods.
**Learning:** Relying on `HasMiddleware` or `Middleware` definitions within controllers is a fragile pattern. It separates the authorization logic from the execution flow of the method, making it easy to accidentally bypass if the middleware configuration is changed or if the controller is refactored.
**Prevention:** Always enforce defense-in-depth by calling `$this->authorize()` at the beginning of every controller action, even when using middleware-based authorization or FormRequests.
## 2026-04-04 - Rate Limiting on Destructive Actions\n**Vulnerability:** The account deletion endpoint () lacked rate limiting for password validation, allowing an attacker with a hijacked session to brute-force a user's password.\n**Learning:** FormRequest validation rules like  protect against IDOR but do not natively protect against brute-force attacks on the session.\n**Prevention:** Implement rate limiting via the `RateLimiter` facade inside `prepareForValidation()` and `withValidator()` for any FormRequest that handles sensitive destructive actions or password confirmation.
## 2026-04-04 - Rate Limiting on Destructive Actions
**Vulnerability:** The account deletion endpoint (DeleteUserRequest) lacked rate limiting for password validation, allowing an attacker with a hijacked session to brute-force a user's password.
**Learning:** FormRequest validation rules like current_password protect against IDOR but do not natively protect against brute-force attacks on the session.
**Prevention:** Implement rate limiting via the RateLimiter facade inside prepareForValidation() and withValidator() for any FormRequest that handles sensitive destructive actions or password confirmation.

## 2026-04-05 - Broken Function Level Authorization via Implicit FormRequest Authorization
**Vulnerability:** Found `SetController` and `WorkoutTemplateLineController` relying implicitly on `FormRequest` authorization which returned `true`, bypassing explicit `$this->authorize()` checks for nested creation.
**Learning:** Relying solely on `FormRequest::authorize()` can bypass Policy logic completely, leading to Broken Function Level Authorization (BFLA/IDOR) on nested resource creation if explicitly checked properties (like the parent resource ownership) are ignored in the `store` method.
**Prevention:** Enforce defense-in-depth by explicitly resolving the parent model via `findOrFail()` and calling `$this->authorize('create', [ChildModel::class, $parentModel])` within the controller's `store` method.

## 2026-04-06 - Rate Limiting on Password Updates
**Vulnerability:** The password update endpoint (`PasswordController@update`) lacked per-user rate limiting for the `current_password` validation rule, exposing users to brute-force attacks on their current session password.
**Learning:** Global route throttling is insufficient for sensitive credential verification. Brute-force protection should be enforced at the request level using a user-specific throttle key.
**Prevention:** Encapsulate sensitive credential verification in a dedicated `FormRequest`. Use `prepareForValidation()` to check for throttling and `withValidator()` to increment the failure count on specific validation errors (e.g., `current_password`).

## 2026-05-18 - AdminPolicy Authorization Bypass
**Vulnerability:** `AdminPolicy` methods were type-hinted with `App\Models\User`, allowing standard users to bypass authorization checks if they were somehow granted admin-like permissions, or simply because the policy defaulted to checking properties on a standard user model rather than strictly requiring an `Admin` instance.
**Learning:** When creating policies for administrative resources that rely on a separate model (e.g., `Admin` vs `User`), type-hinting the broader `Authenticatable` interface and explicitly checking `instanceof Admin` is crucial for defense-in-depth. This prevents a standard `User` model from ever evaluating the permission checks within an admin-specific policy.
**Prevention:** Always use explicit `instanceof` checks in Policies when the application supports multiple user types or guards to ensure that only the intended model type can ever be evaluated for permissions.

## 2026-04-11 - Broken Access Control in UserPolicy
**Vulnerability:** Found `UserPolicy` instance-level methods (`view`, `update`, etc.) incorrectly missing the target `User` model as a parameter and lacking ownership checks. This bypassed ownership-based authorization in favor of global Spatie-style permissions.
**Learning:** For resource policies that govern specific model instances, the policy method must accept that instance as a second argument. Relying solely on the authenticated user model to check for global permissions (`Update:User`) creates a gap where regular users cannot manage their own resources, and users with the permission can manage ALL resources of that type.
**Prevention:** Always implement instance-level policy methods with two parameters: `AuthUser $authUser` and `Model $model`. Combine ownership logic (`$authUser->id === $model->user_id`) with permission-based overrides (`$authUser->can('Update:Model')`) to ensure proper access control.
