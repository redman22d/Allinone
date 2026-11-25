# Tutor Learning Suite 1.3.3 — Upgrade Guide

This is a plugin-only update from Tutor Learning Suite 1.3.2. It changes the public tutor profile layout, contact icon fallback, family-link revocation policy and private-session booking presentation/validation.

## Before updating

1. Back up the WordPress database, `wp-content`, and `wp-config.php`.
2. Test this update on a staging copy first.
3. Keep Tutor LMS Core, Tutor LMS Pro and your current Tutor-compatible theme active.
4. Keep the existing `TLS_AGE_ENCRYPTION_KEY` unchanged. Do not rotate or remove it as part of this update.
5. Do not activate the component plugins separately while Tutor Learning Suite is active.

## Install

1. Go to **Plugins → Add New → Upload Plugin**.
2. Upload `tutor-learning-suite-1.3.3.zip` and replace/update the existing Tutor Learning Suite installation.
3. If WordPress does not offer a replace option, deactivate the current suite, upload the new ZIP, then activate the suite again. Do not delete the plugin's data or component tables.
4. Visit **Settings → Permalinks** and click **Save Changes** once to refresh Tutor dashboard routes.
5. Clear page, object and CDN caches so the new profile and booking CSS/JavaScript are loaded.

## What is preserved

- Tutor profile fields and saved values from Instructor Profile Extra.
- Tutor course listings, course filters, filtered query and pagination.
- Weekly private-session availability rows and tutor timezone settings.
- Existing private-session requests, proposals and payment declarations.
- Family links, age data and the existing DOB encryption key.
- Existing Tutor LMS courses, lessons, enrollments and progress.

No new database schema migration is introduced by 1.3.3.

## Staging acceptance checklist

### Tutor public profile

- Open a tutor profile at desktop width and confirm the information column and booking sidebar appear side by side.
- Open the page at tablet and phone widths; the sidebar should move below the profile.
- Confirm long locations, teaching methods, languages and subjects are collapsed under **Teaching details**.
- Confirm education and experience are collapsed under **Education & experience**.
- Verify each configured social/contact link displays a visible pictogram and opens the correct destination.
- Confirm a tutor who disables public contact does not reveal contact links.
- Confirm the existing profile form still saves and reloads education, experience, locations, methods, languages, subjects and contacts.

### Private-session booking

- Configure a tutor timezone and weekly availability; test with a visitor browser set to a different timezone.
- Confirm the calendar displays the tutor timezone and the tutor-local current date.
- Confirm disabled days and unavailable time windows cannot be selected.
- Change the teaching method and session length; confirm available slots reload and stale selections are cleared.
- Select a slot, submit a request, and confirm a request is created without charging or confirming the session.
- Try submitting by forging or altering the date, method, duration or end time; the server must reject unavailable/invalid slots.
- Test a linked learner booking from a parent account with booking permission. Confirm restricted student accounts cannot submit paid/private booking requests themselves.
- Confirm tutor proposal, direct-to-tutor payment instructions and payment declaration still work.

### Family-link safeguards

- Test an under-18 student and a transition-age student: neither should see a self-unlink button.
- Submit a family-link concern as one of those students and confirm the relationship stays active until review.
- As an administrator, test both outcomes: **Review — keep link** and **End family link**.
- Confirm ending a link removes delegated progress/booking permissions but leaves course records and session history intact.
- Confirm a parent can unlink a student, and an adult student at/above the configured age threshold can end their own link. If a student concern was open, confirm its reason and parent link outcome remain in the administrator-only closed history.
- Confirm a student cannot bypass the UI restriction by posting the self-unlink action directly.

## Important

The plugin does not itself verify a parent’s identity or legal authority. Before enabling family-link approvals for real children, configure and follow a documented safeguarding/verification process and obtain Algerian legal advice on age, guardian authority, privacy and consent requirements. The default adult threshold is 19 and can be changed through the `tls_age_of_majority_threshold` filter after legal review.

Static tests are not a substitute for staging tests on your exact WordPress, Tutor LMS Core, Tutor LMS Pro, theme, cache and PHP environment. Do not update production until the staging checklist passes.
