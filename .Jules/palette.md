## 2026-01-13 - Icon-Only Button Accessibility Pattern
**Learning:** The application heavily relies on icon-only buttons (FABs, header actions, list item actions) for primary interactions but consistently lacks `aria-label` attributes, making them inaccessible to screen readers.
**Action:** When working on any Vue component in this repo, automatically check for icon-only buttons and add `aria-label` (and `aria-hidden="true"` on the SVG) as a standard practice.

## 2026-01-17 - Button Accessibility States
**Learning:** Reusable button components often lack explicit accessibility states for loading and decorative elements. Adding `aria-busy` for loading states and `aria-hidden="true"` for decorative icons/spinners significantly improves screen reader experience without visual changes.
**Action:** When creating or modifying button components, always ensure loading states include `aria-busy` and visual-only elements (icons, spinners) are hidden from assistive technology.
