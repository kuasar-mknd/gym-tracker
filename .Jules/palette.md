## 2026-03-25 - Accessibility for Icon-only Buttons
**Learning:** Icon-only buttons (like delete 'X' or trashcan icons) within dynamic lists or tool pages often lack semantic context for screen readers. Using `aria-label` alongside `title` provides both screen reader accessibility and helpful hover tooltips for sighted users.
**Action:** Always check interactive elements that rely solely on icons (like Material Symbols) and ensure they have a descriptive `aria-label` and ideally a `type="button"` attribute to prevent accidental form submissions.
