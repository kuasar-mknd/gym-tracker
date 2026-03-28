## 2026-03-25 - Accessibility for Icon-only Buttons
**Learning:** Icon-only buttons (like delete 'X' or trashcan icons) within dynamic lists or tool pages often lack semantic context for screen readers. Using `aria-label` alongside `title` provides both screen reader accessibility and helpful hover tooltips for sighted users.
**Action:** Always check interactive elements that rely solely on icons (like Material Symbols) and ensure they have a descriptive `aria-label` and ideally a `type="button"` attribute to prevent accidental form submissions.

## 2026-03-28 - Keyboard Accessibility in Custom Inputs
**Learning:** Interactive elements embedded within custom inputs (like clear buttons or password toggles) sometimes use `tabindex="-1"` to prevent them from interrupting the main form flow, but this entirely breaks keyboard accessibility. These elements must remain reachable via keyboard and have visible focus states (`focus-visible:ring-2`, etc.) to be usable by everyone.
**Action:** When creating or modifying custom input components, ensure all interactive inner elements are focusable (no `tabindex="-1"`) and provide clear `focus-visible` styling that matches the design system's focus indicators.
