<?php
// Since we don't have pint, we can just replace the enum usage manually.
// Keyword::SELF etc are strings usually, but if they are Enums, we'll use `->value`
// Wait! `Keyword::SELF` in spatie/laravel-csp 2.8+ are mostly string constants.
// Ah, the error specifically says "Cannot access offset of type Spatie\Csp\Directive on array".
// This confirms `Directive` is an object, meaning it's an Enum (or at least an object) in the version we are using.
// And `Keyword` might be string constants. `Keyword::SELF` is typically `"'self'"`.
// But to be completely safe, we can check if it's an enum, or use string interpolation if `Keyword::SELF` is a string,
// wait, we can just use `Keyword::SELF->value ?? Keyword::SELF` but that's ugly.
// Actually, in spatie/laravel-csp v2.12+, `Keyword` and `Directive` are Enums.
// To support Enums, I'll use `(is_object(Keyword::SELF) ? Keyword::SELF->value : Keyword::SELF)` or assume it's `Keyword::SELF->value`.
// Since the project requires PHP >= 8.4, they are definitely Enums.
// And the pint error: "new_with_parentheses". I'll format the whole file nicely.
