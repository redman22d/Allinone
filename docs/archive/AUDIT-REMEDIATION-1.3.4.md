# Full audit remediation — Tutor Learning Suite 1.3.4

**Source reviewed:** 1.3.3 package and supplied 20 November 2026 audit.  
**Release prepared:** 1.3.4.  
**Method:** static source review, code changes, PHP syntax, JavaScript syntax and bundled local tests. No live WordPress/Tutor LMS installation or production database was available.

## Findings and disposition

| Finding | 1.3.4 disposition | Notes / residual risk |
|---|---|---|
| H1 Calendar REST cross-user disclosure | Fixed in source | Permission callback limits requests to the current user or `manage_options`; response injection uses the same target check. Learner events booked by a guardian are included. Requires live REST matrix test. |
| H2 Stale guardian permissions after age change | Fixed in source | Parent-view and parent-book checks synchronously call `sync_user()` and revalidate current age/status/consent. Cron is reconciliation only. Depends on DOB accuracy and admin review process. |
| H3 Invalid/racing offline payment decisions | Hardened, not fully integration-proven | Approve/reject/reopen/expiry use connection-scoped per-order MySQL advisory lock and restrict source states to active unpaid/pending states. Tutor's internal order update is still external code; test concurrent decisions and webhook writers on the deployed Tutor version. |
| H4 Bulk approval bypasses proposal workflow | Fixed in source | Bulk approval reuses proposal eligibility constraints and does not allow tutor approval of a proposal-based request before learner acceptance. |
| H5 Fabricated public ad tracking | Mitigated | Tracking requires a short-lived signed server-render token tied to ad/placement, active ad validation, per-token dedupe and visitor rate limits. A token rendered into public HTML cannot prove human viewport visibility. Not billing-grade impression verification. |
| H6 5,000-record legacy family migration cutoff | Fixed in source | New versioned keyset cursor, 200-record batches; completion is recorded only after a final empty batch. |
| M1 Family invitation enumeration/spam | Fixed in source | Generic response for valid-looking addresses; per-actor and per-target cooldowns. Transient-based throttling is defense-in-depth, not a distributed rate-limit service. |
| M2 Private-session transition races | Fixed in source | Expected-old-status conditional updates; proposal and bulk transitions follow state eligibility checks. Needs concurrent DB tests on supported MySQL/MariaDB. |
| M3 Audit table schema falsely marked current | Fixed in source | Verifies table and required columns before storing schema version; missing/incomplete table may retry. |
| M4 DOB write success reported before persistence | Fixed in source | Readback/decrypt validation before old DOB deletion or success. |
| M5 Tutor directory unbounded metadata work | Partially fixed | Candidate rows and user-meta priming are processed in 200-ID keyset batches. Matching lightweight records are still retained for global ranking/filtering, so peak memory remains proportional to matches. |
| M6 Orphaned ID image after partial upload | Fixed in source | Both uploads are validated first; if a later attachment or metadata step fails, newly created attachments are deleted. Needs live filesystem failure test. |
| M7 Invalid verification notification email | Fixed in source | Uses `is_email()` and preserves existing setting on invalid input. |
| M8 Internal private-session ratings in public averages | Fixed in source | Public tutor rating query excludes private-session internal rating markers. |
| M9 Insufficient tests | Improved; integration gap remains | Added 29 source-contract checks. Existing tests pass. They do not invoke a complete WordPress/Tutor lifecycle or prove database concurrency/HTTP access. |
| M10 Legacy receipts accessible on Nginx | Fixed for new files; legacy migration needs deployment action | New files are outside web root and served through authenticated handlers. Legacy uploads migrate in batches. Nginx deny rules must be installed immediately and retained until migration completes. |
| Earlier finding: missing session cancellation handler | Fixed in source | Cancellation handler validates nonce, actor, state and payer notice window; history remains. Ustudy does not process refunds. |
| Earlier finding: paid manual enrollment bypass | Fixed in source | Tutors cannot manually enroll restricted/unknown-age learners into paid courses. Free enrollment remains available. Parent-paid course checkout still needs a separate product workflow. |
| Earlier finding: verification storage in public uploads | Fixed for new submissions; legacy migration in progress | New verification images live outside web root; old files are batch migrated. Nginx deny rule required during migration. |
| L1 Family list capped at 50 | Fixed in source | Bounded pagination added. |
| L2 Integer ID list in SQL | No material vulnerability found | Input IDs are normalized through `absint`; prepared placeholders may still be preferable in a future refactor. |
| L3 Ad order-by sanitizer fallback | Fixed in source | Invalid sanitized order expressions fall back to fixed known-safe ordering. |
| L4 Plaintext concern-report retention | Not changed | Reports are admin-only; no retention period was invented. UStudy owner and counsel must decide retention, access and deletion policy. |
| L5 Audit payload denylist | Fixed in source | Event-specific allowlist with sanitized scalar values and bounded lengths. |
| L6 Linked-user emails in family list | Fixed in source | Child list no longer displays email addresses. Invitation flow still uses the target email to deliver a request. |
| L7 Calendar `approved` state appears final | Fixed in source | Tentative `approved` status is excluded from final calendar event list. |

## Additional behavior and operational notes

- Private-session cancellation retains historical records and does not issue refunds. The current business model records tutor/guardian payment declarations; money does not pass through a Ustudy wallet or escrow.
- Unknown-age and restricted learners cannot be tutor-manually enrolled into paid courses. Parent checkout/enrollment into paid course content remains a product gap, not an implied capability of this patch.
- Instructor IDs and payment receipts use `TLS_PRIVATE_STORAGE_DIR` (or the default directory above `ABSPATH`) outside the web root. Existing public files are moved over multiple admin requests; failed transfers remain flagged for review.
- Family concern report text remains plaintext user metadata and must have an owner-approved retention policy.
- DOB is encrypted but self-reported. Administrator verification is a recorded human attestation, not digital identity proof.

## Verification performed

| Check | Result |
|---|---:|
| PHP syntax (`php -l`) | 70 files, all pass |
| JavaScript syntax (`node --check`) | 19 files, all pass |
| Age-safety smoke suite | 11 passed, 0 failed |
| Family revocation smoke suite | 10 passed, 0 failed |
| Profile/layout/booking suite | 25 passed, 0 failed |
| Audit-remediation source-contract suite | 29 passed, 0 failed |
| Course Format suite | 162 passed, 0 failed |
| Browser suite requiring `jsdom` | Not run; dependency unavailable |
| Live WordPress/Tutor integration | Not run; no instance/database available |

## Release recommendation

1.3.4 contains significant source-level fixes, but this is **not a security clearance or live penetration-test result**. Do not deploy publicly until the staging matrix passes with the actual WordPress/PHP/Tutor LMS Core/Pro versions, private storage path and web-server configuration. Run negative authorization tests and concurrent payment/session transition tests with a real database. Keep Nginx legacy-path deny rules in place while sensitive files migrate.
