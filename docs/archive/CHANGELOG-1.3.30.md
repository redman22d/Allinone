# Tutor Learning Suite 1.3.30

## Review submission reliability
- Course reviews now use Tutor LMS Core's native AJAX handler for nonce validation, enrolment checks, database persistence and JSON responses.
- Restricted-child course reviews are checked before the native handler and moved to guardian-first moderation after Tutor fires `tutor_after_rating_placed`.
- Private-session review submission now catches unexpected PHP Throwables and returns a safe error message with a stable support reference. Technical logs include only exception class/file/line; review text and account data are never logged.
- The private-session review flow continues to stage child feedback in the same guardian-first queue.

## Compatibility
- Intended for the previously supplied Tutor LMS Core 4.2.1 / Pro 4.2.0 source baseline.
- No database migration or parent-child relinking is required.
