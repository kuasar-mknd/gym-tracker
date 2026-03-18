## 2026-03-07 - [Directive-based interaction feedback]
**Learning:** Using a Vue directive for common interactions like press scaling and haptics is more maintainable than repeating Tailwind classes. It also allows for centralized logic to handle edge cases like the `disabled` state, which is often forgotten in manual class-based implementations.
**Action:** Always prefer the `v-press` directive for buttons and interactive elements in this app to ensure consistent feedback and avoid redundant logic.

**Learning:** Static, synchronous registration of Vue directives in `main.js` is critical for hydration and initial render. Dynamic imports can lead to missing directive warnings or delayed interaction feedback on the first view.
**Action:** Ensure all core directives are statically imported in the application's entry point.

## 2026-03-15 - [Unified Interaction Feedback & Localization]
**Learning:** When standardizing interactions with a global directive like `v-press`, it is crucial to audit and remove legacy `:active` scale transformations in both Tailwind classes and custom CSS utilities to prevent "doubled" scaling effects. Additionally, ARIA labels must be localized (e.g., "Add" to "Nouvelle séance") to match the application's UI language for a seamless assistive technology experience.
**Action:** Always check for and remove redundant `:active` styles when applying `v-press`, and ensure all new accessibility labels are localized to the user's primary language.

## 2026-03-19 - [Standardizing Interaction Feedback & Preserving Delight]
**Learning:** Standardizing interaction feedback using a global directive like `v-press` improves consistency, but it's important to preserve component-specific "delight" features (like the `active:bg-neon-green` flash in `GlassFatInput`) that don't conflict with the scaling. Additionally, avoid applying aggressive scaling animations to text inputs (`TextInput`) as they can interfere with focus and typing; stick to subtle scaling or none at all for these elements.
**Action:** When standardizing press effects, audit each component to see if existing CSS-based feedback should be kept alongside the directive, and always exclude `TextInput` from aggressive scaling.
