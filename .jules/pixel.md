
## 2024-03-26 - Exercise Library Mobile Viewports
**Vulnerability:** The Exercise Library lifecycle test only ran on `resizeToIphoneMini()`.
**Learning:** For complete QA coverage across mobile device bounds, critical user paths like CRUD operations on exercises must be explicitly tested on all three target resolutions (Mini, Normal, Max) to catch boundary issues like elements not fitting in view, or touch targets breaking on specific widths. It is effective to extract the test logic into a helper method that takes the resolution macro name.
**Prevention:** Follow the established QA guidelines in `AGENTS.md` and explicitly build tests checking `resizeToIphoneMini`, `resizeToIphone15`, and `resizeToIphoneMax` for all feature tests.
