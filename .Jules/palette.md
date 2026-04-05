## 2025-05-15 - [Race-Condition Protection for Flash Messages]
**Learning:** Flash messages triggered in rapid succession can lead to race conditions where older `setTimeout` calls clear newer messages.
**Action:** Always store `setTimeout` IDs and use `clearTimeout` in Vue `watch` functions before setting a new timer for the same type of notification.
