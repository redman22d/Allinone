# Private-session model and field mapping — 1.3.6

## What the model represents

**One request = one private tutoring session.** A student or approved guardian requests one preferred slot. The tutor reviews the request and sends a final proposal with the confirmed date/time, duration and price. The student accepts the proposal, follows the tutor's direct payment instructions if the session is paid, declares payment, and the tutor confirms receipt. A free tryout follows a separate tutor approve/reject path.

The calendar is an availability picker layered onto this request/proposal model. It does not reserve a time and does not replace the tutor proposal.

## Single source of truth for scheduling choices

The calendar owns these selections:

- **Session format:** `one_to_one` or `group`.
- **Teaching method:** `online`, `tutor_home` or `student_home`.
- **Session duration:** minutes selected from the tutor's allowed durations (or the tutor-configured tryout duration).
- **Preferred date and slot:** tutor-local date, start and end.
- **Timezone:** tutor's IANA timezone identifier, taken from the calendar that was rendered.

There are no duplicate visible date/time, method, format or requested-hours controls in the booking form. The form retains hidden canonical values that the calendar updates when a slot is selected.

## Data flow

### 1. Calendar slot lookup

The browser calls `tlms_ps_slots` with:

| Field | Purpose |
|---|---|
| `tutor_id` | Tutor whose published availability is being queried |
| `date` | Tutor-local calendar date |
| `type` | Teaching method |
| `session_type` | Session format |
| `duration` | Requested duration in minutes |
| `timezone` | Timezone shown by the rendered calendar |

The server checks the tutor, selected method/format, allowed duration, tutor timezone, enabled weekly availability, minimum notice and conflicts with actual proposed/scheduled sessions. A pending request preference is not a reservation and does not block other learners from choosing that same time.

### 2. Calendar selection → request form

Clicking an available slot copies the selected data into hidden booking fields:

| Booking field | Value from calendar |
|---|---|
| `type` | Teaching method |
| `session_type` | Session format |
| `preferred_date` | Tutor-local date |
| `preferred_start` | Slot start |
| `preferred_end` | Slot end |
| `preferred_duration` | Minutes |
| `preferred_timezone` | Calendar's tutor timezone |

The visible selected-slot summary displays date, start/end, duration, format and teaching method. Changing format, method, duration or tryout selection clears the old slot and reloads availability; users must choose a fresh slot.

### 3. Request form fields

| Field | Meaning / persistence |
|---|---|
| `tutor_id` | Selected tutor |
| `learner_user_id` | Actual learner when a guardian books for a linked child; otherwise empty/zero for self |
| `student_id` | Account that initiated the request (student or parent/guardian) |
| `student_name`, `student_email`, `student_phone` | Contact details for the requester/guardian or student, as labelled in the form |
| `teach_category_id` | Selected subject term ID |
| `subject` | Server-derived subject name snapshot |
| `goal` | Optional learning goal |
| `notes` | Optional message/context for the tutor |
| `request_tryout` | Whether this is a free tryout request; server checks tutor setting and fixed tryout duration |
| `terms_accepted`, `cancellation_accepted` | Required independent consent booleans |
| `request_token` | Idempotency token to avoid duplicate requests on retry |
| `resubmitted_from_id` | Original declined/rejected request when resubmitting; requires a new calendar slot |

The server does not trust the hidden fields alone. It verifies parent authority, tutor eligibility, subject, method/format settings, date and time syntax, timezone equality, duration, exact computed end time, current availability, minimum notice, tryout settings and both non-empty tutor policies.

### 4. Database fields written when request is created

| Database field | Value |
|---|---|
| `requested_session_date` | Preferred tutor-local date |
| `requested_start_time` | Preferred start |
| `requested_end_time` | Preferred end |
| `requested_timezone` | Verified tutor timezone ID |
| `requested_duration_minutes` | Calendar duration |
| `preferred_time` | Server-generated legacy display string, retained for older dashboard code |
| `requested_hours` | Derived compatibility value: selected minutes divided by 60; no separate user input |
| `duration_minutes` | Initially the requested duration; updated to proposal duration when tutor proposes |
| `session_date`, `start_time`, `end_time`, `session_timezone` | NULL until a tutor proposal assigns the final schedule (or the tutor approves a tryout at its requested slot) |
| `tutoring_type` | Teaching method |
| `session_type` | Session format |
| `student_id` | Requesting account |
| `learner_user_id` | Linked learner, when guardian books |
| `terms_accepted`, `cancellation_accepted` | Consent booleans |
| `terms_accepted_at`, `cancellation_accepted_at` | Site-local acceptance timestamps |
| `terms_policy_snapshot`, `cancellation_policy_snapshot` | Exact sanitized policy text shown for this request |
| `terms_policy_hash`, `cancellation_policy_hash` | SHA-256 of the exact saved snapshot text |

### 5. Tutor proposal and final schedule

When the tutor sends a proposal, the server validates the proposed date, start, duration, current availability and overlap constraints. It writes `session_date`, `start_time`, `end_time`, `session_timezone`, `duration_minutes`, `price`, and proposal metadata, then changes status to `proposal_sent`.

A proposal is a provisional final offer. The student must accept it; payment confirmation follows according to the private-session flow. When a student declines a proposal, its unaccepted final date/time, price and proposal text are cleared and the request returns to `pending_tutor`. The tutor may send a new proposal. The original requested-slot fields remain for reference.

A free tryout uses its selected calendar slot. Tutor approval rechecks the slot and writes the final session date/time/timezone before the status becomes `confirmed`.

## What is intentionally not supported

- **No reservation hold at request time.** The same preference can be requested by more than one learner until a tutor sends a proposal. The server rechecks the slot when the tutor proposes it.
- **No recurring series or package.** A booking row represents one session only. `requested_hours` is retained for compatibility and equals the selected session length; it must not be interpreted as a multi-week package.
- **No automated group roster or seats.** `group` is currently a manual arrangement preference stored on one request. It does not invite additional learners, create a shared group booking, enforce capacity or let another student join an existing group. A real group-class model requires a parent group-session entity, capacity/seats, participant rows and group-specific collision rules.
- **No direct payment at request time.** The request has no charge. The tutor's proposal/acceptance/payment-declaration workflow remains separate.

## Compatibility and migration

Private Sessions schema version 25 creates missing structured preferred-slot and consent snapshot fields using non-destructive `ALTER TABLE` additions. Existing booking rows, the legacy `preferred_time`/`requested_hours` fields, Tutor LMS courses and enrollments are not deleted. Existing old requests without structured preferred-slot fields can still be reviewed, but the stricter tryout approval path requires a fresh calendar request before it can confirm a schedule.
