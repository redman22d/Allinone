# Tutor Learning Suite 1.3.2 — Upgrade Guide

## Before updating

1. Back up the WordPress database and `wp-content`; perform the update on staging first.
2. Confirm the Tutor LMS Core/Pro versions used by your site are supported by your existing TLS installation. The package's recorded baseline is Tutor LMS Core 4.2.1 and Pro 4.2.0; other combinations need compatibility testing.
3. Keep the existing TLS DOB encryption key and WordPress authentication salts unchanged. **Do not regenerate or replace the encryption key during this visual/availability update.** If you are upgrading from a release earlier than 1.3.1, complete the 1.3.1 age-safety/key setup requirements first.
4. Keep Tutor LMS Core, Tutor LMS Pro and your current theme active. Do not activate the bundled component plugins separately.

## Install

1. In WordPress, open **Plugins → Add New → Upload Plugin**.
2. Upload `tutor-learning-suite-1.3.2.zip` and choose **Replace current with uploaded** if WordPress offers that option; otherwise deactivate the old suite, upload the ZIP and reactivate it.
3. Do not delete Tutor LMS, TLS component data, the Tutor profile fields or the private availability table.
4. Clear page, object and CDN caches.
5. Open **Settings → Permalinks** and save once if Tutor dashboard routes or public profile links do not resolve as before.

## What to check on staging

### Tutor profile form and public page

- Open an instructor account and confirm its existing location, subject, language, tutoring-mode, education, experience and contact values remain in the dashboard form.
- Save one profile with education/experience entries and confirm they render below the bio in the blue public profile card.
- Confirm contact/social links appear as icons under the tutor name and open the correct destination.
- Enable the profile's public-contact privacy option and confirm the icons are hidden.
- Confirm tutors who have not enabled private sessions do not get a private-booking button or booking calendar.

### Course archive

- Check the archive on desktop and mobile.
- Apply each existing Course Search Pro filter and confirm the course result set, filter selections and pagination still work.
- Confirm course cards render type, format, level, category, tutor, rating, duration, learner count, tags and price where those values exist.

### Weekly private-session availability

- Open the tutor dashboard's **Private Sessions → Settings**.
- Confirm all seven days and both time blocks render, together with the tutor's timezone.
- Choose a weekday, enable one time block and save. Confirm the schedule is reflected in the public booking calendar.
- Change a start/end time and save; try an end time earlier than the start and two overlapping blocks on the same day. The server should reject invalid schedules.
- Confirm an unchecked block is not bookable and a new tutor's suggested times remain unavailable until enabled.
- Confirm editing weekly availability does not alter existing booking records or confirmed sessions.

## Data and migration notes

- 1.3.2 does not add or alter database columns. It reuses `tlms_private_availability`.
- Existing saved availability rows are not reset. If a tutor is missing a weekday/period row, the plugin creates it disabled with suggested hours.
- Existing Tutor profile meta keys are retained. No profile form migration or re-entry of tutor data is required.
- Do not install the bundled component add-ons separately while Tutor Learning Suite is active.

## Known testing limitation

The optional Course Format browser tests require `jsdom`, which was not available in the build environment. The PHP regression suite and static syntax/smoke checks passed, but this package has not been verified on your live/staging WordPress site. Complete the staging checklist above before deploying to production.
