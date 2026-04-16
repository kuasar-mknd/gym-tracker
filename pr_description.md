🎯 **What:**
Added missing tests for the `getRecommendedValuesAttribute` method in `app/Models/WorkoutLine.php`. This simple getter fetches default JSON values or resolves them via the service when not set.

📊 **Coverage:**
Three new scenarios are covered:
1. When `recommended_values` is set and contains valid JSON (verifies JSON decoding and array return)
2. When `recommended_values` is set but contains invalid JSON (verifies fallback to default values)
3. When `recommended_values` is missing (verifies delegation to `RecommendedValuesService::class` through integration testing)

✨ **Result:**
Full branch path test coverage for the `getRecommendedValuesAttribute` function logic, improving model safety and guarding against unintended behavior during hydration or access.
