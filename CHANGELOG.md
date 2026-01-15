# Changelog

All notable changes to GymTracker will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.3] - 2026-01-15

### Added

- **E2E Testing:** Comprehensive Laravel Dusk browser tests (`PageBrowserTest.php`) covering 20+ user flows.
- **Factories:** Added `GoalFactory`, `PlateFactory`, and `AchievementFactory`.

### Fixed

- **Production Blank Page:** Fixed CSP headers preventing script execution in some environments.
- **Routing:** Updated all Ziggy route calls to v2 format (object syntax) to prevent JS crashes.
- **Cache:** Fixed `BadMethodCallException` by removing `tags()` from `StatsService` for file driver compatibility.
- **Auth Forms:** Added missing `name` attributes to Register components.

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

[Unreleased]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/kuasar-mknd/gym-tracker/releases/tag/v1.0.0
