🎯 **What:**
Added comprehensive unit tests for the `useHaptics` composable, which provides standardized vibration patterns for mobile interactions. To support this, Vitest and jsdom were introduced to the project as development dependencies, along with a base `vitest.config.js`.

📊 **Coverage:**
The new test suite `tests/js/useHaptics.test.js` covers the following scenarios:
*   `isHapticsSupported`: Correctly identifies when the `navigator.vibrate` API is available vs missing.
*   `triggerHaptic`: Verifies correct patterns are sent to the `vibrate` API based on type, checks fallback patterns, and ensures graceful handling (returning `false`) when haptics are unsupported or when the API throws an error.
*   `stopVibration`: Ensures `navigator.vibrate(0)` is called correctly, and handles unsupported/error states.
*   `useHaptics` composable: Validates the correct shape and default values of the returned object.

✨ **Result:**
Test coverage and reliability for mobile interaction feedback are improved. A solid foundation for testing other Javascript logic via Vitest is also established for the project.
