## 2025-02-18 - v-press directive
**Learning:** `v-press` is actually a valid directive in the project, defined at `resources/js/directives/vPress.js`. It provides scaling effects and haptics for interactive elements, which perfectly aligns with Apple's HIG. Code reviews sometimes mistakenly flag it as undefined if they don't look closely at the project setup.
**Action:** When asked to implement micro-interactions or Apple HIG effects, `v-press` is the preferred approach for buttons and interactive items in this codebase.
## 2026-03-22 - Standardizing Interaction Feedback
**Learning:** Transitioning from manual `triggerHaptic()` and CSS `active:scale-*` to the declarative `v-press` directive ensures consistent feedback and prevents additive scaling issues.
**Action:** Always prefer `v-press` for standardized interactions and remove redundant haptic/scaling code from script and style blocks.
