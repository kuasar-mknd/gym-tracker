# Changelog

All notable changes to GymTracker will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[Unreleased]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.7...HEAD
[1.4.7]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.6...v1.4.7
[1.4.6]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.5...v1.4.6
[1.4.5]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.0...v1.4.5
[1.4.0]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.3.1...v1.4.0
[1.3.1]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/kuasar-mknd/gym-tracker/releases/tag/v1.0.0
