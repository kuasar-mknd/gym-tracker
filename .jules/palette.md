## 2024-04-09 - Ensure all custom "buttons" have accessibility tags when busy
**Learning:** Found that custom "Dashboard Action" button blocks, which show loading states with `autorenew`, missed `aria-busy` declarations. Complex, visually styled interactive elements might miss standard button a11y properties that components like `GlassButton` abstract away.
**Action:** When adding or verifying loading state animations on large custom touch targets, explicitly check for `aria-busy` and visual cursor feedbacks (`cursor-wait`), ensuring they mirror the a11y patterns of standard buttons in the system.
## 2026-04-11 - Add aria-labels to main dashboard quick actions\n**Learning:** When creating large, visually styled custom components (like the QuickActions blocks) that serve as primary navigation or action triggers, standard accessibility attributes like `aria-label` are easily missed because the component doesn't use the standard `GlassButton` or `Link` components directly.\n**Action:** Always explicitly verify and add `aria-label` attributes to custom interactive blocks, especially when their visual meaning (e.g., icons and stacked text) might not be fully conveyed or easily parsed by screen readers.
## 2026-04-11 - Standardize character count for textareas
**Learning:** Adding a visible character counter to textareas (like Workout Notes or Journal entries) improves UX by providing immediate feedback on validation limits before submission.
**Action:** Always include a `{{ current / max }}` indicator for textareas with `maxlength` constraints, using the `text-red-400` class for limit warnings to maintain consistency with the 'Liquid Glass' design system.
## 2026-04-12 - Add aria-labels to icon-only buttons
**Learning:** Icon-only buttons (like those using only `material-symbols-outlined`) often miss `aria-label` attributes, making them inaccessible to screen readers. This is common in lists where actions like 'Edit' or 'Delete' are represented solely by icons to save space.
**Action:** Always ensure that any button containing only an icon has a descriptive `aria-label` attribute (e.g., `aria-label="Modifier le complément"`) to convey its purpose to assistive technologies.

## 2024-05-15 - Accessible character counters for textareas
**Learning:** While visible character counters improve UX, they are often invisible to screen readers if not properly linked.
**Action:** Always link textarea character counters using `aria-describedby` on the textarea and a matching `id` on the counter element to ensure the limit is communicated to assistive technologies.

## 2026-04-18 - Enhanced Supplement Interaction & Toast Accessibility
**Learning:** Adding localized loading states to list items (e.g., supplement consumption) significantly improves perceived performance and prevents duplicate actions. Standardizing these interactions with `GlassButton` ensures consistent haptic feedback and accessibility patterns. Global toast notifications benefit from explicit ARIA roles and live regions to ensure they are announced to screen reader users immediately.
**Action:** Always implement `consumingId` or similar state for per-item async actions in lists. Ensure all flash messages use `role="alert"` and appropriate `aria-live` settings based on severity.
