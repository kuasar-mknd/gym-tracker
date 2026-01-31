## 2025-02-19 - Component Variants & Accessibility
**Learning:** This app uses distinct input variants ('fat' vs standard) for different contexts (workout logging vs forms). Global component updates must respect these variants to avoid breaking specialized UIs.
**Action:** Always check `variant` props in shared components before adding global features like toggle buttons.
