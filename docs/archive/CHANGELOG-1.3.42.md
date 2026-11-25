# Tutor Learning Suite 1.3.42

## Security, workflow and UI maintenance

- Advertising payment proofs are now stored outside the public WordPress uploads directory. Administrators view receipts through a nonce-protected, capability-checked streaming endpoint. Existing Media Library receipts are migrated in bounded batches; files that cannot be moved remain flagged for administrator review. Configure `TLS_PRIVATE_STORAGE_DIR` to a writable absolute path outside the web root if the default parent-of-WordPress location is not suitable.
- Advertising order confirmation now tells the customer whether the confirmation email was sent or whether the order number should be saved because email delivery failed.
- Sponsorship request records now use server-side pagination (25 per page), filters for status and promotion type, manager-scoped queries, and complete dashboard totals rather than truncating the manager view at 200 records.
- Private-session dashboard request panels no longer force a tall nested scroll area. Tables and tabs are more usable on narrow screens, and form controls have clearer focus states.
- Sponsorship manager cards, request filters, pagination and mobile table handling have been visually refined.
- Updated the main README release number and upload filename.

## Upgrade notes

- No existing Tutor LMS course, user, booking, payment, sponsorship or advertising records are intentionally deleted by this release.
- The Ads orders table adds `payment_proof_path`; the plugin upgrades it through its normal schema upgrade routine.
- Legacy advertising payment receipts are migrated gradually in admin requests. Verify the private storage directory is writable and outside both `ABSPATH` and the web document root.
- Back up the database and `wp-content`, and test the update on staging before production deployment.
- This package retains the suite's existing Tutor LMS Core 4.2.1 / Tutor LMS Pro 4.2.0 compatibility baseline; other versions still require compatibility testing.
