# Palette's Journal - UX & Accessibility Learnings

## 2025-05-14 - Standardized Accessibility for Timers and Icons
**Learning:** Visual progress indicators and countdown timers in fitness applications require specific ARIA roles to be accessible to screen readers. Icons that are purely decorative or redundant to text labels should be hidden from assistive technologies.
**Action:** Use `role="progressbar"` with `aria-valuenow`, `aria-valuemin`, `aria-valuemax`, and `aria-valuetext` for progress bars. Use `role="timer"` with `aria-atomic="true"` for real-time countdowns. Always add `aria-hidden="true"` to Material Symbols and decorative SVGs.
