## 2025-02-18 - v-press directive
**Learning:** `v-press` is actually a valid directive in the project, defined at `resources/js/directives/vPress.js`. It provides scaling effects and haptics for interactive elements, which perfectly aligns with Apple's HIG. Code reviews sometimes mistakenly flag it as undefined if they don't look closely at the project setup.
**Action:** When asked to implement micro-interactions or Apple HIG effects, `v-press` is the preferred approach for buttons and interactive items in this codebase.
