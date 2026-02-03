## 2026-02-04 - [Surgical Cache Invalidation]
**Learning:** Surgical cache invalidation is highly effective in avoiding the "Scorched Earth" policy of clearing all caches on any change. Specifically, separating metadata changes (name/notes) from structural changes (dates/completion) allows keeping expensive volume aggregations cached even when the user makes minor edits.
**Action:** Always analyze which data is actually included in a cache key/closure before deciding to clear it. Implement granular invalidation methods in services to support this.
