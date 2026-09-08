# Tutor LMS Suite — focused code review

Reviewed September 8, 2026. Original: `tutor-lms-suite (1).zip` (1.0.1).
Updated source: `tutor-lms-suite/` (1.0.2). The original ZIP is unchanged.

## Fixed findings

| Priority | Finding | Change |
| --- | --- | --- |
| High | Receipt authorization failures exposed the raw order row and instructor metadata when `WP_DEBUG` was enabled. A logged-in unauthorized viewer with a valid, unexpired signed link could reach this output. | Removed debug data from the HTTP denial response; retained signature and ownership checks. |
| High (performance) | Private-session boot ran `dbDelta()` for both tables on every request. Student request submission also inspected columns and unconditionally issued three `ALTER TABLE ... MODIFY` statements. | Moved schema preparation/normalization into versioned upgrades, with a fast return for current schemas. Schema version 22 triggers a one-time normalization for existing installations. Added two missing timestamp fields to the upgrade schema definition. |
| Medium | Autocomplete generated `ORDER BY 0 DESC` when no promotion scores were enabled. MySQL treats an integer here as a select-list position; zero is invalid. | Omit the absent score and preserve the existing sort plus a deterministic ID tie-breaker. Handle an empty existing sort without a leading comma. |
| Low | Course Format flushed rewrite rules in addition to the suite activation flush. | Removed the redundant module flush. The offline-payment deferred flush remains intentional because its dashboard filters must be registered first. |

## Validation performed

- All **45 plugin PHP files** parsed successfully using `token_get_all(..., TOKEN_PARSE)` under PHP 8.5 via `@php-wasm/cli`.
- All **8 JavaScript files** passed `node --check`.
- Standalone regression checks passed for disabled ranking with/without an existing order, enabled paid ranking, no database work on an up-to-date private-session schema, and generic receipt denial with debugging enabled.
- Test entry point: `php tests/regression.php`. WordPress dependencies are stubbed; these are not integration tests.

The sandbox had no native PHP, WordPress, Tutor LMS, or MySQL installation. Native PHP installation was blocked by package-repository connectivity; the PHP WebAssembly runtime was used instead. PHP 7.4 compatibility, real SQL execution/migrations, UI behavior, email, concurrency, and performance timings have **not** been validated. This is a focused review, not a complete security certification.

## Remaining priorities

1. **Verify receipt storage on the production web server.** The module uses an uploads directory protected by `.htaccess`. Nginx does not honor that file; configure a deny rule for the actual `/wp-content/uploads/tutor-offline-payments/` URL (including any custom uploads/CDN path). Random filenames are not access control. Confirm direct requests return 403/404, while authorized signed downloads still work.
2. **Profile search on realistic data.** Autocomplete combines wildcard content searches, taxonomy joins, and five postmeta joins even when ranking is disabled. Measure with Query Monitor and SQL EXPLAIN before adding indexes or caching. Conditional ranking joins and bounded, language-aware caching are possible follow-ups.
3. **Harden migration reliability.** The existing migration system records a version without comprehensively verifying all DDL results and has no migration lock. Test with a database backup, adequate ALTER permissions, and concurrent traffic controlled. Failed or manually damaged schemas will not be repaired on every request; fix the database and rerun the migration deliberately.
4. **Do not activate the old standalone modules alongside this suite.** They retain the same class/function prefixes and can collide.

## Staging checklist before deployment

- Back up files and database; update via WordPress's plugin replacement flow (do not uninstall, which may invoke data cleanup).
- Test a fresh install and a 1.0.1 upgrade. Confirm schema version 22, required timestamp columns, nullable date/time fields, preserved bookings, and no recurring DDL on ordinary page loads or request submission.
- Test autocomplete with both promotion settings off, each on separately, and zero weights; include Arabic/French searches and translated content.
- Test receipt access as the student, owning instructor, administrator, unrelated logged-in user, and guest. Check expired/tampered URLs and direct storage URLs.
- Complete checkout → receipt upload → approval/rejection → enrollment, reminder/expiry jobs, private-session request → proposal → payment, and calendar display.
- Check dashboard permalinks after activation and inspect PHP/database logs with `WP_DEBUG_LOG` on and `WP_DEBUG_DISPLAY` off.
