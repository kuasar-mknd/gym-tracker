## 2026-03-07 - [Directive-based interaction feedback]
**Learning:** Using a Vue directive for common interactions like press scaling and haptics is more maintainable than repeating Tailwind classes. It also allows for centralized logic to handle edge cases like the `disabled` state, which is often forgotten in manual class-based implementations.
**Action:** Always prefer the `v-press` directive for buttons and interactive elements in this app to ensure consistent feedback and avoid redundant logic.

**Learning:** Static, synchronous registration of Vue directives in `main.js` is critical for hydration and initial render. Dynamic imports can lead to missing directive warnings or delayed interaction feedback on the first view.
**Action:** Ensure all core directives are statically imported in the application's entry point.

## 2026-03-24 - [Standardizing Haptics for Toggle vs. Neutral Actions]
**Learning:** For components with state-switching buttons (like Play/Pause or a primary "Create" FAB), `v-press="{ haptic: 'toggle' }"` provides a more distinct tactile signature that confirms a state change. Conversely, for actions that already trigger specialized feedback (like a "Skip" button that leads to a 'timer' vibration), the directive's haptic should be disabled with `{ haptic: false }` to avoid overlapping or redundant feedback.
**Action:** When migrating to `v-press`, always match the `haptic` option to the button's semantic role (neutral, toggle, or suppressed) and remove manual `triggerHaptic` calls from the logic methods.
