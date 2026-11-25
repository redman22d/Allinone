# Tutor Learning Suite 1.3.6

## Private-session request model cleanup and data integrity

- Removed duplicate visible booking controls for teaching method and session format. The calendar is the single place to choose **session format**, **teaching method**, **session duration**, date and available time.
- Removed the separate requested-hours field from the public request form. One request represents one session; the session duration comes from the calendar. The legacy `requested_hours` field remains populated from the selected duration for old dashboard/admin workflow compatibility.
- Removed the standalone date/preferred-time input from the form. The calendar selection summary is the visible confirmation, and the exact selected date/start/end are submitted in hidden canonical fields.
- Added the tutor timezone ID to slot lookups and booking submission. The server rejects stale pages when the tutor's timezone changes after the calendar was rendered.
- Clarified that the selected slot is a preference, not a hold. A pending request no longer blocks another learner from selecting the same slot; a tutor proposal or scheduled booking does.
- When a learner declines a proposal, the unaccepted proposed date/time is cleared, price/proposal details are reset, and the original requested duration is restored. A later proposal must use a fresh available time.
- Policy hashes are now computed from the exact policy snapshots saved with the request, alongside the acceptance timestamps.
- Confirmed that free tryout duration is included in the configured calendar duration list and that tryout approval writes the final scheduled date/time before marking the session confirmed.
- The tryout checkbox remains compact. Session format and teaching method are labelled separately throughout tutor, student and confirmed-session views.
- Database version 25 adds/ensures structured requested-slot and policy-consent snapshot fields without dropping existing columns or records.

## Model boundaries

- One booking request is one session. Recurring series and multi-session packages are not represented by the legacy `requested_hours` field.
- Group format remains a manual arrangement preference only. The current private-session model does not create a shared group roster, participant capacity, seat allocation or a join-existing-group flow.
- Preferred times are not reserved while a request waits for the tutor. The tutor's proposal is the point where a final session time is assigned.
