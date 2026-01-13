## 2026-01-13 - Icon-Only Button Accessibility Pattern
**Learning:** The application heavily relies on icon-only buttons (FABs, header actions, list item actions) for primary interactions but consistently lacks `aria-label` attributes, making them inaccessible to screen readers.
**Action:** When working on any Vue component in this repo, automatically check for icon-only buttons and add `aria-label` (and `aria-hidden="true"` on the SVG) as a standard practice.
