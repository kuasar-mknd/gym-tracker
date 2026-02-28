## 2025-05-15 - [A11y] Avoiding Screen Reader Chatter in Timers
**Learning:** High-frequency UI updates (like a 1-second countdown timer) must avoid `aria-live="polite"`. Applying this attribute causes screen readers to announce every single second, creating an unusable experience for visually impaired users.
**Action:** Use `role="timer"` without `aria-live` for continuous updates, and provide human-readable snapshots via `aria-valuetext` on progress indicators that users can query as needed.

## 2025-05-15 - [UX] Standardized Haptic Feedback
**Learning:** Consistency in tactile feedback is key for mobile-first "Liquid Glass" interfaces. Direct use of `navigator.vibrate` should be avoided in favor of a centralized `triggerHaptic` utility that maps to specific interaction patterns (tap, toggle, success, etc.).
**Action:** Always check for `useHaptics` composable before implementing vibration logic.
