# Changelog

All notable changes to GymTracker will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.4.14] - 2026-03-01

### Fixed

- **CI Reliability**: Resolved critical issues in GitHub Actions workflow, restoring MySQL for Dusk tests and ensuring `APP_KEY` preservation.
- **E2E Authentication**: Fixed 401 errors in Dusk by enabling Sanctum stateful API and configuring Axios with credentials.
- **Cache Invalidation**: Fixed a bug in `Exercise` model where versioned cache keys were not correctly invalidated, causing stale UI data.
- **Rate Limiting**: Adjusted throttle limits for testing environments to prevent 429 errors during automated test runs.
- **Dusk Robustness**: Improved selectors and added necessary pauses in `ExerciseManagementTest` to handle animations and asynchronous updates.

## [1.4.13] - 2026-02-28

### Security

- **FormRequests**: Systematically replaced inline controller validation with dedicated FormRequest classes for improved security and type safety.
- **API Hardening**: Improved validation rules for `PushSubscription`, `WorkoutLine`, and `DailyJournal`.

## [1.4.12] - 2026-02-26

### Added

- **Achievement CRUD**: Implemented full backend support for creating, reading, updating, and deleting user achievements.
- **E2E Testing**: Introduced comprehensive workout session E2E tests covering the entire training flow.

## [1.4.11] - 2026-02-20

### Improved

- **Liquid Glass UI**: Refactored `InputLabel` and several form components to strictly adhere to the Liquid Glass design system.
- **Performance**: Optimized volume history queries and improved database indexing for stats dashboard.

## [1.4.10] - 2026-02-15

### Fixed

- **Frontend Dependencies**: Resolved conflicts with Inertia.js and Vue 3 core packages.
- **Formatting**: Unified code style across the application using Laravel Pint and Prettier.

## [1.4.9] - 2026-02-10

### Fixed

- **Pulse Dashboard**: Implemented a definitive architectural fix for Content Security Policy (CSP) conflicts using `ConditionalCspHeaders`. This allows Pulse to manage its own security headers without being overridden by the global web policy.
- **GitHub Actions**: Corrected the ARM64 runner label to `ubuntu-24.04-arm` (from `ubuntu-24.04-arm64`), resolving the "waiting for runner" hang in CI.

### Optimized

- **Docker Build Performance**: Refactored CI workflow to leverage Native ARM64 runners, slashing build times by ~85% (down to ~2.5 minutes from 15+ minutes).
- **Dockerfile Layering**: Implemented `--platform=$BUILDPLATFORM` for builder stages and granular copying for better cache utilization.
- **Multi-Arch Strategy**: Switched to a parallel build and manifest merge strategy, following 2026 industry best practices.

## [1.4.8] - 2026-02-10 [DEPRECATED]

> [!WARNING]
> This version contained an incorrect GitHub Actions runner label and a conflicting CSP configuration. Users should upgrade to v1.4.9 immediately.

## [1.4.7] - 2026-02-10

### 🛡️ Ops

- **Production Fix**: Removed unsupported `--force` from `filament:upgrade` in `entrypoint.sh` to prevent server crash.

---

## [1.4.6] - 2026-02-10

### ⚡ Performance & Offline

- **Axios Migration**: Migrated workout interactions and profile notification preferences to Axios for robust API communication.
- **SyncService**: Introduced centralized synchronization logic to prepare for full offline support.

### 🛡️ Security & Ops

- **Production Fix**: Resolved critical server startup failure caused by Telescope loading in production.
- **CI Stability**: Fixed Dusk test failures (white pages) by isolating Vite assets conflict.

### 🧹 Modernization

- **Rector & Pint**: Applied automated code modernization and style enforcement across the codebase.

---

## [1.4.5] - 2026-02-05

### 💪 UX & Interaction

- **Swipe-to-Action**: Integrated `SwipeableRow` for sets (swipe left to delete, right to duplicate).
- **Smart Timer**: Added haptic-enabled intelligent rest timer.
- **Haptic Engine**: Tactile feedback for gesture completion and timer events.
- **Dynamic Themes**: Added dark/light mode engine with system preference sync.

### 🛡️ Security

- **Fix IDOR**: Prevented unauthorized exercise association in goals/PRs.
- **Mass Assignment**: Hardened user statistics models against unauthorized updates.

### ⚡ Performance

- **N+1 Fix**: Optimized `PersonalRecordService` to eager-load workout/exercise relations (#395).
- **Bolt Optimization**: Reduced dashboard payload size and optimized cache invalidation.

### 🐞 Bug Fixes

- Fixed `TypeError` in `SetsController` (#393).
- Fixed `TypeError` in `Modal.vue` unmount phase for iOS (#394).
- Resolved Larastan audit failures in PR synchronization service.

---

## [1.4.0] - 2026-01-30

### 🛡️ Security & Ops

- Added Multi-Factor Authentication (MFA) for Filament Admin.
- Hardened Content Security Policy (CSP) for backoffice routes.
- Stabilized migration rollbacks for SQLite/CI.

### 📱 PWA & Mobile

- Implemented Offline-first sync with Workbox and Dexie.
- Refined mobile safe-area insets for superior ergonomics.

---

## [1.3.1] - 2026-01-24

### 🐞 Fixed

- Corrected cached notification count `TypeError`.
- Resolved PHP 8.4 deprecation warnings (PDO constants).

---

## [1.3.0] - 2026-01-21

### 🚀 Core Features

- **Habit Tracker**: Full implementation of habit creation, logging, and visualization.
- **Health Vitals**: New modules for Heart Rate, Blood Pressure, and Body Fat tracking.
- **Glass UI**: Implementation of the "Liquid Glass" design system across all pages.

### 🛡️ Security & Quality

- Achieved Larastan Level 8 compliance.
- Enforced 100% Laravel Pint style coverage.
- Optimized database query patterns to reduce overhead.

### 🐞 Fixes

- Resolved mobile layout shifts on iOS Safari.
- Fixed date parsing alignment between API and Frontend.

---

## [1.2.0] - 2026-01-15

### Added

- Workout templates system
- Plate calculator tool
- Performance optimizations (caching, eager loading)
- Security hardening (rate limiting, input validation)

### Changed

- Dashboard stats now cached for 60 seconds
- Exercises list cached for 10 minutes

### Fixed

- N+1 queries in AchievementService
- Missing indexes on frequently queried columns

---

## [1.1.0] - 2026-01-10

### Added

- Personal Records (PR) tracking system
- Achievement/Trophy system with celebrations
- Streak counter for consecutive workout days
- Body measurements tracking
- Daily journal feature
- Custom goals with progress tracking
- Web Push notifications

### Changed

- Dashboard redesigned with quick stats
- Improved mobile navigation

---

## [1.0.0] - 2026-01-01

### Added

- Initial release
- User authentication (email + OAuth via Google, GitHub, Apple)
- Workout session management
- Exercise library with categories
- Sets and reps logging
- Workout history
- Basic statistics
- Mobile-first PWA design

---

[Unreleased]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.9...HEAD
[1.4.9]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.7...v1.4.9
[1.4.8]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.7...v1.4.8
[1.4.7]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.6...v1.4.7
[1.4.6]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.5...v1.4.6
[1.4.5]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.0...v1.4.5
[1.4.0]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.3.1...v1.4.0
[1.3.1]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/kuasar-mknd/gym-tracker/releases/tag/v1.0.0
