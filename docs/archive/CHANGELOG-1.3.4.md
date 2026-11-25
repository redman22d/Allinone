# Tutor Learning Suite 1.3.4 — Changelog

**Release type:** Security and reliability remediation  
**Upgrade from:** 1.3.3  
**Date:** 20 November 2026

## Security and authorization

- Restricted private-session calendar REST queries to the signed-in user; only users with the site `manage_options` capability can request another account's calendar. The response injector applies the same ownership rule.
- Included guardian-booked learner sessions in the learner's own calendar. Tentative `approved` sessions are no longer shown as final calendar events.
- Refreshed learner age and guardian status synchronously before delegated parent progress/booking access is authorized. Scheduled synchronization is now reconciliation only.
- Required an explicit admin review/attestation before a newly linked family relationship can receive delegated permissions. The attestation documents the site's check; it is not independent digital identity verification.
- Changed legacy family migration to a versioned keyset cursor and batches of 200 so old installations that already marked the earlier 5,000-record migration complete are not skipped.
- Made valid-looking family invitation responses generic and added per-parent and per-target cooldowns.
- Replaced audit-detail denylist filtering with event-field allowlists and value limits.
- Verified audit-table existence and required columns before recording its schema version.
- Read back and decrypt-verified DOB ciphertext before deleting legacy DOB data or reporting a successful migration.

## Booking, payment and enrollment integrity

- Added expected-old-status conditional updates to private-session transitions and applied proposal eligibility checks to bulk approval.
- Added cancellation with participant/admin authorization, status checks and the configured payer notice window. Ustudy does not process refunds or hold funds; payment settlement remains outside the plugin.
- Prevented temporary checkout course deletion before the related session state transition succeeds.
- Applied a connection-scoped per-order MySQL advisory lock and allowed-state checks to offline payment approve/reject/reopen/expiry workflows. The connection-scoped MySQL advisory lock serializes Suite payment decisions through Tutor payment hooks. External Tutor/custom order writers that do not use this lock still require live integration testing.
- Blocked tutors from manually enrolling restricted/unknown-age learners into paid courses. Manual enrollment remains available for free courses.
- Excluded private-session internal ratings from public tutor rating aggregates.
- Normalized private-session deadline and reminder calculations using absolute timestamps and the appropriate site/tutor timezone.

## Files, data and performance

- Moved new instructor-verification images and payment receipts to a private directory outside the public web root; downloads are served through authenticated handlers.
- Added batched migration for legacy verification images and receipts. Failed legacy-file moves remain flagged for administrator review and are not silently treated as complete.
- Validated instructor-verification notification email settings before saving.
- Validated both ID image uploads before attaching them and rolled back attachments if a later step fails.
- Added bounded keyset batches and user-meta cache priming to tutor directory candidate processing. Global filtering/ranking still retains the matched lightweight result set in memory.
- Added bounded pagination to the family management list and removed linked-student email addresses from that list.
- Added a safe fallback for ad database ordering.
- Public ad tracking now requires a short-lived signed render token tied to an ad and placement, validates campaign activity using the WordPress site timezone, and adds event deduplication and rate limiting.

## Tests and limitations

- Added 29 audit-remediation source-contract checks.
- Validated 70 PHP files with `php -l` and 19 JavaScript files with `node --check`.
- Existing included suites pass: 11 age-safety, 10 family-revocation, 25 layout/booking, 28 audit-remediation and 162 Course Format checks.
- These tests are local unit/stub/source-contract checks, not live WordPress/Tutor LMS integration tests or a penetration test. The browser suite requiring `jsdom` was not run because that dependency is unavailable in this environment.
