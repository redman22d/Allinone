# Tutor Learning Suite 1.3.3 — Changelog

## Public tutor profile

- Reworked the public tutor page into a responsive two-column composition: profile and course content on the left, private-booking panel on the right.
- Kept the existing Tutor LMS Instructor Profile Extra form and saved user-meta fields. No profile-field migration is required.
- Put education, experience, locations, teaching methods, languages and subjects into compact expandable sections so long lists no longer dominate the page.
- Kept the booking panel visible beside the tutor profile on desktop and moved it below the profile on smaller screens.
- Made custom-plugin social/contact links use visible built-in pictograms as a fallback when Tutor/theme icon fonts or their icon CSS are not available. Public-contact privacy remains respected.

## Private-session booking

- Moved private-session booking into the public-profile sidebar for tutors who have enabled private sessions.
- Made the availability calendar use the tutor's configured timezone for the initial date, month validation and displayed times.
- Shows the timezone next to the calendar to reduce confusion for students in another region.
- Keeps method-specific session durations and the configured tryout duration in sync with the booking request form.
- Requires a selected live-availability slot for new requests. The server rechecks the selected date, method, duration, end time, weekly availability, minimum notice and conflicts before creating a request.
- Clarified that requested total tutoring hours are separate from the length of the first preferred session.
- Collapsed optional learning goals, notes, tutor terms and cancellation policy by default; agreement checkboxes remain required.
- Preserves the tutor-proposal and direct-to-tutor payment model. Sending a request does not confirm a session or collect payment.

## Family-link safety

- Under-18 and transition-age student accounts cannot directly unlink an active parent/guardian relationship.
- Those students can submit a private family-link concern for administrator review. The administrator can record that the concern was reviewed while keeping the link, or end the link and remove delegated access.
- Parents can still unlink a linked child from their own Family & Guardian dashboard.
- Adult students at or above the configured threshold (default 19) can end their own guardian link.
- Server-side authorization remains authoritative; hiding the button is not the security boundary.
- Ending a relationship removes the link and delegated permissions but does not delete student accounts, course enrollments, learning history or booking history. If a concern was already filed, its reason and outcome remain available in the administrator-only history even when a parent ends the link first.

## Compatibility and data

- No new database tables or columns are introduced.
- Existing weekly availability is reused.
- Existing tutor profile form values, age-encryption key, family relationship metadata and private-session request history are retained.
- Tutor LMS Core, Tutor LMS Pro and the active theme are not modified.
