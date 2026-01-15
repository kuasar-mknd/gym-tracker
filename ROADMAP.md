# Roadmap v1.0.2: Production Hardening

## Goal

The goal of version `v1.0.2` is to transition from a "working prototype" to a "production-ready" application by implementing professional security standards and architectural best practices.

## Roadmap Steps

### 1. Foundation Hardening (Security)

- [ ] **Policy Migration**: Move all ownership checks to Laravel Policies (`WorkoutPolicy`, `SetPolicy`, etc.).
- [ ] **Model Safety**: Remove sensitive foreign keys (`user_id`) from `$fillable`.
- [ ] **Security Headers**: Implement custom middleware for CSP, HSTS, and Frame Options.

### 2. Architectural Cleanup

- [ ] **Form Requests**: Migrate all inline validation to dedicated classes.
- [ ] **Casts Modernization**: Move all `$casts` arrays to `casts()` methods (Laravel 11+ way).
- [ ] **Enum Integration**: Use PHP Enums for fixed types like `Exercise.type`.

### 3. Performance Tuning

- [ ] **Index Audit**: Ensure all composite queries found in `StatsService` have matching database indexes.
- [ ] **Eager Loading**: Set up strict eager loading on common relations to prevent N+1 regressions.

### 4. Testing & Verification

- [ ] **Boundary Testing**: Add tests specifically for "Unauthorized Access" attempts on all endpoints.
- [ ] **Smoke Test**: Full manual walkthrough with the liquid glass UI.

---

**Target Release Tag**: `v1.0.2`
