# Tutor Learning Suite 1.3.4 — Age and family-safety notes

## Age bands

The suite preserves Tutor LMS's Student role and derives an internal category from the encrypted DOB:

- **Child:** under 18.
- **Transition:** 18 to below the configured threshold (default 19).
- **Adult:** at or above the configured threshold (default 19).
- **Unknown:** DOB missing, malformed, undecryptable, or unavailable; restricted by default.

The default threshold is a product setting, not a legal conclusion. Confirm the applicable Algerian rules with qualified local counsel before enabling this workflow for real learners.

## Parent link and approvals

A parent invitation is sent to the email on an existing Tutor LMS Student account. The student signs in and accepts the tokenized, expiring link. New relationships enter an administrator review queue. The administrator must affirm that the site's documented identity and legal-authority checking procedure was completed. This checkbox records the administrator's attestation; it does not mean the software independently verifies an identity document or legal relationship.

Delegated permissions are separate:

- Parent learning-progress/attendance visibility.
- Parent booking requests for the linked learner.

Under-18 accounts receive the configured approved relationship defaults after administrator review. Transition-age and adult students must explicitly opt in to each permission. Unknown-age accounts receive no delegated permissions.

Every authorization check synchronously refreshes the learner's age category and family status before granting parent access. Scheduled synchronization remains useful for reconciliation and reporting, but is not the authority for access decisions. Crossing an age-band boundary revokes delegated permissions and requires the new category's consent/review policy before they can be restored.

## Revocation and concerns

- A parent/guardian can unlink a child.
- A site administrator can end a relationship after review.
- A transition-age student cannot directly unlink the parent in this policy; they can submit a concern for administrator review.
- An adult student at or above the configured threshold can end their own link.
- A student concern is retained in administrator-only history when the relationship is ended; unlinking does not delete the student's learning, booking or payment history.

The suite does not automatically grant a parent access to all private educational records or teacher-private notes. The parent sees only the data allowed by the individual delegated permission and the relevant feature's own authorization checks.

## DOB encryption

DOB ciphertext uses AES-256-GCM with a fresh nonce and authentication tag. Existing configured encryption material must be preserved. A DOB write is read back and decrypted before the legacy value is deleted. If the value cannot be read/decrypted, the account remains Unknown/restricted.

The DOB is still self-entered. Encryption protects stored data; it does not prove the date is accurate. The administrator's guardian attestation likewise depends on the site's real-world verification procedure.

## Operational privacy notes

- Keep the DOB encryption key outside the public web root and out of source control.
- Back up the encryption key separately from the database; database backups alone cannot restore encrypted DOB access.
- Do not expose full student email addresses in family lists or tutor search results for restricted learners.
- Family concern reports contain free text. Restrict admin access and avoid asking users to include unnecessary sensitive information. The retention period remains an owner/legal-policy decision and is not automatically deleted by this release.
- Do not treat a child/transition student's learning participation as consent to public reviews, public social posting or unrestricted direct messaging.
