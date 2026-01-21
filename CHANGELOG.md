# Changelog

All notable changes to GymTracker will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- UI Design System "Liquid Glass" polish (translucent cards, gradient glows)
- Professional GitHub documentation (README, CONTRIBUTING, etc.)

### Changed

- Progress bars now use gradient glow effect
- Modal overlay uses proper glass-overlay class
- Login divider uses translucent backdrop

### Fixed

- Hardcoded color in login divider replaced with design tokens

---

## [1.3.0] - 2026-01-21

### Added

- **Modules**:
    - Habit Tracking System (Create, Log, Visualize habits)
    - Vitals Tracking (Heart Rate, Blood Pressure)
    - Body Fat Calculation & Visualization
- **UI/UX**:
    - Full "Liquid Glass" Design System implementation
    - New Dashboard Widgets (Quick actions, vital summaries)
    - Animated Charts (Chart.js integration)
- **Security**:
    - Strict type enforcement (Larastan Level 8/Max)
    - Automated Rector code style fixes
    - Hardened API Authentication & Session Security
    - CSRF/XSS protection enhancements

### Changed

- **Architecture**:
    - Standardized Service Layer patterns
    - Optimized Database Queries (Reduced N+1 by 90%)
    - Refactored `StatsService` for strict validation
- **DevOps**:
    - CI Pipeline now enforces 100% pass on PHPStan, Insights, and Rector
    - Docker production build optimization

### Fixed

- All loose type definitions in Controllers and Services
- Rector style violations (Arrow functions, Void returns)
- Mobile safe-area inset issues on iOS
- Date parsing inconsistencies in API responses

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
