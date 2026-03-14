## 2025-02-18 - v-press directive
**Learning:** `v-press` is actually a valid directive in the project, defined at `resources/js/directives/vPress.js`. It provides scaling effects and haptics for interactive elements, which perfectly aligns with Apple's HIG. Code reviews sometimes mistakenly flag it as undefined if they don't look closely at the project setup.
**Action:** When asked to implement micro-interactions or Apple HIG effects, `v-press` is the preferred approach for buttons and interactive items in this codebase.

## 2025-02-19 - Stateful Toggle Button A11y
**Learning:** For components that represent a stateful toggle (like `ThemeToggle`), adding a dynamic `aria-label` that includes the current state (e.g., `aria-label="Changer le thème. Actuel : ${themeLabels[theme]}"`) significantly improves the context for screen reader users compared to static text labels. Additionally, these custom interactive elements frequently miss keyboard focus indicators.
**Action:** When working with toggle buttons, always ensure a dynamic `aria-label` or `aria-pressed` attribute is present alongside explicit `focus-visible` styles (`focus-visible:ring-2 focus-visible:outline-none`) for keyboard navigation.
