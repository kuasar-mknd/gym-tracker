# Palette's Journal - UX & Accessibility Learnings

## 2026-03-01 - [Playwright Visibility with Liquid Glass UI]
**Learning:** UI components utilizing the "Liquid Glass" design system (heavy use of `backdrop-blur`, `bg-white/10`, and absolute positioning) can sometimes be reported as "not visible" or "not stable" by Playwright's default visibility checks, even when they are perfectly visible to the user. This is often due to the transparency and blur effects interfering with the driver's opacity or intersection calculations.
**Action:** When verifying 'Liquid Glass' UI components with Playwright, use `force: true` in click actions or `page.evaluate` to interact with elements that are visually present but flagged as non-interactable by automated drivers.
