## 2026-03-30 - [Checkbox component focus and semantics]
**Learning:** Custom checkboxes using the `peer` class with CSS state selectors (`checked:`) often use `focus:` utility classes which apply a visual ring on both mouse click and keyboard focus. This creates an awkward visual experience for mouse users. Furthermore, internal SVGs used merely as visual checkmarks are sometimes left visible to screen readers, causing redundant or confusing announcements since the actual `<input type="checkbox">` handles the semantically correct checked state.
**Action:** When implementing custom interactive inputs, always prefer `focus-visible:` classes (e.g., `focus-visible:ring-2`) over `focus:` to ensure focus styles are only shown when navigating via keyboard. Always add `aria-hidden="true"` to purely decorative SVG elements within these components.

## 2026-03-31 - [Dropdown Accessibility and Layout]
**Learning:** Using a `div` as a dropdown trigger is an accessibility anti-pattern. It is not focusable by keyboard users and does not naturally support Space/Enter to activate. Additionally, applying `w-full` to a `block` element that has horizontal margins (like `mx-2`) will cause it to exceed the width of its container, leading to layout overflows.
**Action:** Always use semantic `<button type="button">` for interactive triggers to ensure keyboard focusability and interaction. When using horizontal margins on block-level links or buttons, avoid `w-full` as the element will naturally fill the available width while respecting the margins.

## 2026-04-04 - [Auto-select input data on focus]
**Learning:** In high-frequency data entry contexts (like logging workout sets), users often want to replace the previous value entirely rather than append to it. Forcing manual deletion is a friction point.
**Action:** Implement `@focus="$event.target.select()"` on high-frequency numeric or data-entry input fields to allow users to rapidly overwrite previous values, significantly improving efficiency for both mobile and desktop users.
