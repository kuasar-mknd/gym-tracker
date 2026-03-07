## 2025-05-22 - [Fix N+1 query in workout lines]
**Learning:** Globally appended model attributes (using `$appends`) that perform database queries can cause catastrophic N+1 query explosions when serializing collections. A small collection of 5 items with 3 lines each caused 33 queries.
**Action:** Use conditional attributes in API resources (`$this->whenAppended()`) and remove expensive attributes from the model's global `$appends` array. Explicitly call `->append()` only in specific controllers where the data is actually required.
