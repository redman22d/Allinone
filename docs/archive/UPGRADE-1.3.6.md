# Upgrade to Tutor Learning Suite 1.3.6

## Before you update

1. Back up the WordPress database, `wp-content`, and `wp-config.php`.
2. Upgrade on a staging copy first.
3. Keep Tutor LMS Core and Pro active, and keep the site's current theme active.
4. Preserve the existing `TLS_AGE_ENCRYPTION_KEY` and WordPress authentication salts. Do not regenerate or replace them during this update.
5. If the component plugins are installed separately, deactivate duplicate copies before activating the bundled suite. Do not delete their data.

## Install

1. Upload `tutor-learning-suite-1.3.6.zip` through **Plugins → Add New → Upload Plugin** and update the existing Tutor Learning Suite installation.
2. Let the plugin run its normal non-destructive database upgrade. Private Sessions schema version 25 adds missing requested-slot and consent-evidence columns; it does not drop existing booking records.
3. Visit **Settings → Permalinks** and save once if Tutor dashboard routes need refreshing.
4. Clear page, object, browser and CDN caches so the versioned calendar script is refreshed.

## Private-session checks

Run these tests on staging with a tutor account and separate student/guardian accounts:

- Select a date, teaching method, session format and duration in the calendar. Confirm the selected-slot summary shows the same values.
- Submit a request. Confirm the request record stores `requested_session_date`, `requested_start_time`, `requested_end_time`, `requested_timezone` and `requested_duration_minutes`.
- Confirm that `session_date`, `start_time`, `end_time` and `session_timezone` remain empty while the request is waiting for a tutor proposal.
- Confirm the tutor proposal writes the final schedule and does not silently replace the original requested-slot fields.
- Make two independent requests for the same preferred slot before a tutor sends a proposal. Both may be submitted because a preference is not a reservation. Once a tutor sends a proposal, the proposed slot must no longer be offered to another learner.
- Decline a proposal. Confirm its unaccepted final time is cleared and the tutor can send a fresh proposal.
- Test a free tryout using the tutor-configured duration. Confirm approval writes the final date/time and timezone.
- Change the tutor timezone after loading a learner's booking page. The stale page must reject slot lookup/submission and ask the learner to refresh.
- Confirm the policy snapshot, its SHA-256 hash, acceptance timestamp and consent boolean are saved for both policy checkboxes.
- Confirm guardian bookings keep the parent as requester/contact and the linked child in `learner_user_id`.

## Model notes

- The calendar is an availability/preference picker added on top of the existing request → tutor proposal → learner acceptance → direct-to-tutor payment flow. It is not a direct reservation or payment checkout.
- One request is one session. It does not create a recurring series or package.
- Group format is recorded as a manual arrangement preference. There is no multi-student group roster/capacity system in this version.
- Old `preferred_time` and `requested_hours` fields remain populated for compatibility with existing dashboard/admin workflow. New structured `requested_*` fields are authoritative for preferred time and duration.

## Operational limitation

Static syntax and source-contract tests do not replace staging tests against the site's actual WordPress, database, web server, and installed Tutor LMS Core/Pro versions. Do not treat this update as a live-site verification.
