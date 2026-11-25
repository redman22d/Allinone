# Tutor Learning Suite 1.3.2 — Changelog

## Public tutor profile

- Kept the supplied Instructor Profile Extra form and its existing field/meta registrations intact.
- Reworked the custom public tutor page into a wider blue hero card.
- Moved contact and social links to accessible icon links below the tutor name, respecting the existing public-contact privacy flag.
- Placed the biography before compact icon facts for location, tutoring modes and spoken languages.
- Moved education, teaching experience and subjects into the blue profile card; removed the duplicate metadata sections outside the card.
- Show the private-booking CTA and calendar only when the tutor has enabled private sessions.

## Course catalog

- Refreshed course cards with course-type, delivery-format and level badges; category chips; instructor identity and verification; ratings; duration; learners; live-session count; tags; and price.
- Kept the existing TCSP filters, filtered main WordPress query and archive pagination intact.
- Left the Instructor Profile Extra and Course Search Pro component modules unchanged from the previous suite release.

## Private-session availability

- Added a tutor dashboard editor for recurring weekly availability: Sunday–Saturday, two time blocks per day, editable start/end times and timezone display.
- Added server-side 24-hour time validation, end-after-start checks and same-day overlap prevention.
- Schedule changes persist through the existing `tlms_private_availability` table; no new schema migration was introduced.
- New schedules use suggested hours but remain disabled until the tutor opts in. Existing saved schedules are preserved; missing weekday/period rows are safely created.
- Settings validation runs before schedule persistence so a failed required-policy validation does not partially update availability.

## Validation

- PHP syntax check: all plugin PHP files.
- JavaScript syntax check: all plugin JavaScript files.
- Existing Course Format PHP suite: 162 passed, 0 failed.
- Age-safety smoke suite: 11 passed, 0 failed.
- Layout/availability smoke suite: 17 passed, 0 failed.
- The optional Course Format browser suite could not run because `jsdom` is not installed in this environment. Run it in the normal development environment with its test dependency installed.
- Live WordPress/Tutor LMS staging acceptance tests are still required before production deployment.
