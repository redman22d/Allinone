# Tutor LMS Private Sessions (bundled module)

This module is bundled inside Tutor Learning Suite and should not be activated separately while the suite is active.

## 1.3.6 request/calendar model

- One request represents one session. The calendar is the sole source for session format, teaching method, duration, preferred date/time and tutor timezone.
- The selected slot is a preference, not a time hold. Pending requests do not block other learners; a tutor proposal/scheduled session does.
- Preferred slot fields are stored in `requested_session_date`, `requested_start_time`, `requested_end_time`, `requested_timezone` and `requested_duration_minutes`. Final scheduled fields remain empty until the tutor proposal (except tutor-approved free tryouts).
- The form records the exact displayed policy snapshots, hashes and acceptance timestamps.
- Group is only a manual-arrangement preference in this version; shared group rosters/capacity/seats are not implemented.
- See the bundled `PRIVATE-SESSION-MODEL-1.3.6.md` for the full field mapping.

## New private-session payment flow

- The student submits a request for a tutor and chooses a preferred date/time from the tutor's published availability.
- The tutor reviews the request and sends a final proposal.
- The student accepts the proposal. This does not add a product to the Tutor LMS cart or create a Tutor LMS order.
- For a paid request, the student reads the tutor's configured offline payment instructions and pays the tutor directly.
- The student may declare that payment was made, with an optional reference. The declaration is an unverified statement.
- The tutor checks their own payment account/cash records and explicitly confirms receipt. Only then is the booking marked confirmed.
- For a free request, the accepted proposal is confirmed without payment.

UStudy does not collect, hold, escrow, refund, transmit or verify private-session funds. No private-session native checkout is used for new requests.

## Age and parent delegation

A parent can request a booking for a linked child only when the child's date of birth can be decrypted and the child's family booking permission is enabled. A minor requires the approved guardian relationship/attestation. An adult requires a fresh timestamped booking consent. Unknown age is denied. The age threshold is configured by the `tls_age_of_majority_threshold` filter (default 19, subject to legal review).

## Legacy records

Legacy Tutor order listeners and temporary-course cleanup remain to support previously created sessions/orders. They are not used by the new request/proposal/payment flow. Review older pending records during upgrade.
