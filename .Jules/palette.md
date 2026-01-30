## 2026-01-13 - Icon-Only Button Accessibility Pattern
**Learning:** The application heavily relies on icon-only buttons (FABs, header actions, list item actions) for primary interactions but consistently lacks `aria-label` attributes, making them inaccessible to screen readers.
**Action:** When working on any Vue component in this repo, automatically check for icon-only buttons and add `aria-label` (and `aria-hidden="true"` on the SVG) as a standard practice.

## 2026-01-17 - Button Accessibility States
**Learning:** Reusable button components often lack explicit accessibility states for loading and decorative elements. Adding `aria-busy` for loading states and `aria-hidden="true"` for decorative icons/spinners significantly improves screen reader experience without visual changes.
**Action:** When creating or modifying button components, always ensure loading states include `aria-busy` and visual-only elements (icons, spinners) are hidden from assistive technology.

## 2026-01-20 - Form Input Label Association
**Learning:** Custom input components (like `GlassInput`) often wrap native inputs but fail to propagate `id`s for `label` association, breaking accessibility.
**Action:** Always ensure custom input wrappers generate a unique ID (if one isn't provided) and bind it to both the `input` and the `label`'s `for` attribute.

## 2026-05-23 - Visual Regression with Transparent Glass Overlays
**Learning:** Applying transparent/glassy backgrounds (`bg-white/60`) to elements positioned over borders (like dividers) causes the underlying border to show through the text, looking like a strikethrough.
**Action:** When using glass effects on overlay elements, restructure the layout (e.g., using flexbox with side lines) to ensure the background behind the transparent element is clear, rather than relying on opacity masking.

## 2026-06-15 - Duplicate Labels in Variant-Based Components
**Learning:** Components that render different layouts based on a `variant` prop (like `GlassInput`) may accidentally render the label multiple times if the label logic is not conditional on the variant, causing confusion for screen readers.
**Action:** When implementing variant-based components, explicitly check if the label is handled inside the variant block and suppress the default label rendering to avoid duplication.
