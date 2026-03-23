## 2025-05-15 - Standardizing Micro-interactions with v-press

**Learning:** The GymTracker design system uses a custom `v-press` directive to centralize scaling and haptic feedback. Manually implementing these (e.g., with `active:scale-95` and `triggerHaptic`) leads to inconsistent UX and "double-haptic" bugs when both the directive and manual calls are present on the same element.

**Action:** Always prefer the `v-press` directive for interactive elements. When a button triggers an action that has its own specialized haptic pattern (like a timer completion), use `v-press="{ haptic: false }"` to prevent overlapping vibrations while still benefiting from the standard scaling animation.
