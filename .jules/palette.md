## 2025-02-14 - Accessible Form Validation
**Learning:** `GlassInput` components lacked `aria-describedby` linking inputs to error messages, leaving screen reader users unaware of validation failures.
**Action:** When creating form components, always generate unique IDs for error containers and link them to inputs using `aria-describedby` and `aria-invalid`.
