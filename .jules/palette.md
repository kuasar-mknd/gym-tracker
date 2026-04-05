## 2026-03-07 - [Directive-based interaction feedback]
**Learning:** Using a Vue directive for common interactions like press scaling and haptics is more maintainable than repeating Tailwind classes. It also allows for centralized logic to handle edge cases like the `disabled` state, which is often forgotten in manual class-based implementations.
**Action:** Always prefer the `v-press` directive for buttons and interactive elements in this app to ensure consistent feedback and avoid redundant logic.

**Learning:** Static, synchronous registration of Vue directives in `main.js` is critical for hydration and initial render. Dynamic imports can lead to missing directive warnings or delayed interaction feedback on the first view.
**Action:** Ensure all core directives are statically imported in the application's entry point.

## 2026-03-29 - [Accessibility in custom form inputs]
**Learning:** Custom input components like `GlassInput.vue` often use interactive elements (like clear buttons or password toggles) nested within the input area. Setting `tabindex="-1"` on these elements to bypass focus breaks keyboard navigation. Additionally, validation error components like `InputError.vue` need an `id` prop and `role="alert"` so they can be explicitly linked to inputs via `aria-describedby` and announced by screen readers immediately upon appearance.
**Action:** Never use `tabindex="-1"` on functional interactive elements. Always provide clear `:focus-visible` styling (e.g., using brand colors like `ring-electric-orange`). For validation errors, ensure an `id` is passed and link it via `aria-describedby` on the corresponding input.

## 2026-04-05 - [Race-Condition Protection for Flash Messages]
**Learning:** Flash messages triggered in rapid succession can lead to race conditions where older `setTimeout` calls clear newer messages.
**Action:** Always store `setTimeout` IDs and use `clearTimeout` in Vue `watch` functions before setting a new timer for the same type of notification.
