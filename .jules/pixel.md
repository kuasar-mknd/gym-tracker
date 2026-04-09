
## 2024-03-26 - Exercise Library Mobile Viewports
**Vulnerability:** The Exercise Library lifecycle test only ran on `resizeToIphoneMini()`.
**Learning:** For complete QA coverage across mobile device bounds, critical user paths like CRUD operations on exercises must be explicitly tested on all three target resolutions (Mini, Normal, Max) to catch boundary issues like elements not fitting in view, or touch targets breaking on specific widths. It is effective to extract the test logic into a helper method that takes the resolution macro name.
**Prevention:** Follow the established QA guidelines in `AGENTS.md` and explicitly build tests checking `resizeToIphoneMini`, `resizeToIphone15`, and `resizeToIphoneMax` for all feature tests.

## 2024-03-27 - Workout Completion Mobile Viewports
**Vulnerability:** The Immutable Workout UI test only ran on `resizeToIphoneMini()`, meaning other screen sizes could regress unchecked.
**Learning:** To ensure full UI testing coverage, we extracted the test logic into `performImmutableWorkoutCheck` and added tests for `resizeToIphone15` and `resizeToIphoneMax`.
**Prevention:** Verify all frontend tests are tested against the three defined iPhone screen boundaries.

## 2024-04-09 - Naming Conventions for iPhone Tests
**Vulnerability:** Mobile test methods were generally named "mobile" (e.g., `test_login_flow_on_mobile_devices`) rather than specifically stating "iphone" to match our target boundaries.
**Learning:** For clarity and to ensure we strictly adhere to the iPhone Mini, Normal, and Max boundaries mandated by the Golden Standards, all relevant Dusk test methods specifically targeting these viewports should include "iphone" in their names (e.g., `test_login_flow_on_iphone_devices()`, `test_exercise_library_responsive_layout_on_iphone()`).
**Prevention:** Verify all test names use the `_on_iphone` suffix when iterating through the standard iPhone viewports.
