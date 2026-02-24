## 2025-02-19 - Component Variants & Accessibility
**Learning:** This app uses distinct input variants ('fat' vs standard) for different contexts (workout logging vs forms). Global component updates must respect these variants to avoid breaking specialized UIs.
**Action:** Always check `variant` props in shared components before adding global features like toggle buttons.

## 2026-02-12 - Icon Standardization & Accessibility
**Learning:** The app uses `Material Symbols Outlined` as its primary icon system. Replacing legacy inline SVGs with `<span class="material-symbols-outlined">icon_name</span>` improves maintainability but **must** include `aria-hidden="true"` to prevent screen readers from announcing the ligature text (e.g., "inventory_2").
**Action:** When refactoring icons, always add `aria-hidden="true"` to the icon span and verify text labels exist on the parent button.

## 2026-02-03 - Dynamic Labels for Badged Buttons
**Learning:** Buttons with status badges (like notification counts) need dynamic aria-labels (e.g., "Notifications (3 unread)") because the badge content itself is often just a number and separate from the icon.
**Action:** Use computed properties or template literals for aria-labels on buttons with state counters.

## 2026-02-19 - Interactive Image Links
**Learning:** Links containing only images (like social login buttons) should have an `aria-label` on the `<a>` tag itself. Relying solely on the image's `alt` text can be less robust for keyboard/screen reader navigation.
**Action:** Always add descriptive `aria-label` to icon-only or image-only links.

## 2025-05-22 - Notification Accessibility
**Learning:** Action buttons in notification lists that only use an icon (like a checkmark for "Mark as read") should have an explicit `aria-label` even if a `title` is present, to ensure clarity for screen reader users.
**Action:** Added `aria-label="Marquer comme lu"` to the mark-as-read button in the Notifications index page.
