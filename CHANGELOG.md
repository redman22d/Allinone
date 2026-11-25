# Tutor Learning Suite – Changelog

# Unreleased – deactivation and uninstall hygiene
- Fix: deactivating the suite only removed the age-safety cron job. The bundled modules register their own deactivation hooks on their module file, which WordPress never fires for a bundled plugin, so `tioc_*`, `ostudy_ads_daily_orders`, `tcsp_process_scheduled_sponsorships` and `tlms_ps_maintenance` kept running after deactivation. A root `tls_deactivate()` now clears every suite cron hook (listed once in `tls_cron_hooks()`), drops the offline-payment rewrite flag and flushes rewrite rules.
- Fix: `uninstall.php` did not clear the three offline-payment cron hooks or the age-safety sync hook, and the "delete all data" path left `_transient_timeout_*` rows (and `tlms`/`ostudy` transients) behind.
- Ads: `ostudy_ads_order_renew` now goes through the same per-visitor `tls_rate_limited()` guard as the other public order endpoints.
- README updated to 1.3.48; links to archived release notes now point to `docs/archive/`.

# 1.3.48 – ads ordering upgrades and sponsorship redesign

## Ads 1.7.0
- New `[ostudy_advertise]` hub page: business-ad order form, course/tutor sponsorship plans, and order tracking in tabs (`?ad_tab=order|sponsor|track`).
- Optional fixed prices per placement (per week), long-booking discounts (30/60/90 days) and a currency label. The order form shows a live total and fills "Amount due" automatically.
- Slot limits ("max ads per placement at the same time"): live availability check in the form, refusal of full placements on the server, and an availability panel on the order screen.
- Image rules per placement (minimum width and matching shape), checked in the browser and again on the server; can be disabled in Settings.
- Renewals: "Renew this ad" button on the tracker, 7- and 3-day ending reminders, and a daily job (`ostudy_ads_daily_orders`) that completes finished campaigns and pauses their ads. The root uninstall also clears this job.
- Setup check and test email on Ads > Orders (order page, payment text, prices, uploads, private receipt storage, cache, cron).
- Fix: five `$d = 'ostudy-tutor-lms-ads'` text-domain variables in the orders class were missed by the single-domain change, so order statuses, durations, JS messages and submit/proof errors never used the `tutor-learning-suite` translations.
- Receipt storage is unchanged (the private-storage implementation from 1.3.47 is kept).
- Database: `reminders_sent` column on `{prefix}ostudy_ads_orders` (added automatically, DB 1.7.0).

## Course Search Pro 4.2.29
- Sponsorship ordering redesigned as one guided flow for courses and the tutor profile: choose target, pick a plan card, choose duration, with a live order summary (total, discount, and a new/extend/plan-change schedule estimate). It uses the blue palette and header of the 1.3.47 portal refresh.
- Status tab rebuilt as order cards with progress bars, days left, Pay now / Cancel, and a History section. Styles and script moved to `assets/css/tcsp-sponsorship.css` and `assets/js/tcsp-sponsorship.js`.
- Sponsorship expiry reminders (7 and 3 days) once a day from the existing scheduled job; "Renew now" / "Sponsor again" buttons pre-select target, plan and units (`?tcsp_target=course:ID&tcsp_plan=KEY&tcsp_units=N`).
- The order form also accepts a combined `target=type:id` field; the server logic and payment hand-off are unchanged. Optional currency label via the `tcsp_sponsorship_currency` filter.
- Refreshed public sponsored look: frosted tier badges for courses and tutors, restyled carousel and cards.

# 1.3.47 – review follow-up (also covers the 1.3.40 follow-up items)
- One translation domain (`tutor-learning-suite`) for all modules; one `languages/tutor-learning-suite.pot`.
- Root `uninstall.php`: data is kept by default; a checkbox under Settings > General deletes tables (including `ostudy_ads_orders`), options, user meta and sponsorship records on uninstall. The unused module uninstall file was removed.
- Changelogs merged into this file (old notes in `docs/archive`).
- Public AJAX endpoints (slots, month availability, carousels, suggest, ad tracking, ad order submit/track/proof upload) are rate limited per visitor.
- Fix: the sponsorship "notifications" setting was never saved, so it could not be switched off.
- Filter counts fall back to a 45 s transient when no persistent object cache exists.
- Private sessions: one reminder email halfway through the payment window.
- Offline payments: a reused reference number is flagged in the order history and audit log.
- Manual enrollment: enrolling/removing a student is logged per course (last 200 entries).
- Tests no longer hard-code the version; new suite-hygiene regression test.



# Tutor Learning Suite 1.3.46

## Offline Payment Details endpoint fix

- Registered `offline-payment-setup` as a hidden instructor dashboard endpoint (`show_ui => false`). The Payment Details page remains accessible from the existing tabs and does not add a duplicate sidebar item.
- Added a one-time rewrite-rule refresh when the offline-payment module version changes, so existing installations receive the new Tutor dashboard route after updating the plugin.
- Bumped the suite to 1.3.46 and the bundled offline-payment module to 1.6.11.

## Validation

- PHP syntax checks passed for the updated files.
- Dashboard route registration and template-map references were checked for matching slugs.
- Full package PHP/JavaScript syntax and ZIP integrity checks are recorded with the release build.
- Live route resolution still needs confirmation on the target WordPress/Tutor LMS installation.


# Tutor Learning Suite 1.3.45

## Dashboard hotfix

- Removed the Tutor CourseModel SQL-rewrite filter introduced in 1.3.44. It was unnecessary for the public-course exclusion path and could interfere with instructor dashboard course data queries.
- Removed the custom floating dashboard-mode switch. Tutor LMS 4 provides its own profile-menu switch and `tutor_switch_profile` handler; the suite now leaves that native control untouched.
- Kept the supported Tutor LMS 4 learner navigation filter registrations and legacy compatibility filters for suite dashboard pages.

## Validation

- PHP and JavaScript syntax checks passed for the package.
- ZIP integrity verified. Live dashboard API behavior still requires testing on the target WordPress installation.


# Tutor Learning Suite 1.3.44

## Dashboard compatibility fixes

- Register Family & Privacy, Private Sessions, and My Payments on Tutor LMS 4's dedicated `tutor_student_dashboard_nav` filter while retaining the legacy learner-navigation filter.
- Add defensive array validation to learner navigation callbacks.
- Harden the private-session internal-course exclusion applied to Tutor's instructor CourseModel query: validate the input type, avoid duplicate conditions, use prepared values, and only rewrite the expected Tutor SQL shape.
- Prevent legacy Ads receipt and instructor-verification file migrations from running during admin-AJAX, cron, or REST requests, keeping Tutor dashboard data and profile-switch requests free from unrelated migration work.

## Validation

- PHP syntax checks and JavaScript syntax checks are run against the complete plugin package.
- Live WordPress/Tutor LMS behavior still requires staging-site verification.


# Tutor Learning Suite 1.3.43

## Visible dashboard and portal UI refresh

- Redesigned Private Sessions tutor and student dashboard headers, activity summaries, request cards, proposal areas, status labels, and mobile layouts.
- Restyled the Sponsorship portal with a branded page header, clearer plan cards, improved promotion rows, status panels, and responsive layouts.
- Restyled the Sponsorship Manager administration screen with clearer summary cards, filters, table hierarchy, status labels, and pagination.
- Redesigned the Offline Payments instructor and student dashboards with a visual page header, summary cards, segmented filters, more legible order cards, and responsive search controls.
- Bumped relevant asset versions to force browsers to fetch the refreshed CSS.

## Preserved functionality

- Existing modules, database tables, custom post types, shortcodes, and stored records are retained.
- No database schema migration is introduced by this UI update.
- Earlier 1.3.42 security and workflow fixes are retained.

## Validation

Static PHP and JavaScript syntax checks and ZIP integrity checks should be run before deployment. Live rendering still needs verification on a staging WordPress site using the site's active Tutor LMS and Tutor LMS Pro versions and theme.


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


# Tutor Learning Suite 1.3.41

## Ads module 1.6.1
- Admin-defined default payment instructions (Settings > Ad orders) and per-order amount due.
- Customers upload payment proof (image/PDF) from the tracking page; new status "Payment under review".
- Admin sees the proof on the order screen and is e-mailed when one arrives; status e-mail includes how to pay.
- Database: new payment columns on `{prefix}ostudy_ads_orders` (added automatically, DB version 1.6.1).


# Tutor Learning Suite 1.3.40

## Ads module 1.6.0
- New **ad order system**: `[ostudy_ad_order]` (order form + tracking tab) and `[ostudy_ad_track]` (tracking only).
- Guests receive an order number (`AD-XXXXXXXX`) and follow it with number + email/phone; logged-in users see "My orders".
- New **OStudy Ads > Orders** admin screen: status workflow, message to customer, internal notes, e-mail notifications,
  and one-click "Create ad from this order" (paused draft; order Live/Completed keeps the ad in sync).
- New settings: accept orders, order page URL, notification email, accent colour, max upload size.
- Modernised banner, sidebar and popup design; "Advertise here" hover link; RTL-safe CSS.
- Fix: automatic popups were mounted inside the hidden staging container and never displayed; they now mount on `<body>`.
- Database: new `{prefix}ostudy_ads_orders` table (created automatically on next admin load; DB version 1.6.0).


# 1.3.39
- Hourly rate no longer appears twice on the public profile booking panel.
- Sponsorship and Family & Privacy menu items get proper icons on Tutor 4 (SVG icon names); the CSS fallback icon only runs on older Tutor.
- Private Sessions and My Private Sessions menu items show a count of items needing action (new requests / payments to confirm for tutors; proposals to answer or pay for learners).
- Payment details is now a tab inside Offline Payments (one menu item); the pending-payment count stays in the main menu.


# 1.3.38
- Hourly rate is shown under "Book a session" at the top of the public profile booking panel.
- Proposal/tryout slot check: the request being answered is no longer counted as its own conflict, and the error now says why (too soon, outside availability, or overlaps booking #N).


# 1.3.37
- Tutors can set an hourly rate in Private session settings. It is shown on the booking form, and proposal prices default to rate x duration/60 when the tutor leaves the price empty.


# 1.3.36 – payments go through the native Tutor cart and checkout
Tutor's cart and checkout only accept real courses, so each payable item uses a temporary hidden course that is deleted after use.

**Private sessions**
- Accepting a proposal puts the session in the student's Tutor cart and opens it; payment then happens at the Tutor checkout with the tutor's offline method (tioc).
- Falls back to the old pay-the-tutor-directly flow when Tutor's native cart is unavailable.
- The temporary course is deleted after payment, cancellation, rejection or timeout, together with the enrolment Tutor creates for it.

**Sponsorship / boosting**
- A request creates a temporary hidden course, adds it to the cart and redirects to the cart. Offline payment goes to the site owner; earnings stay 100% with the site.
- The custom pay page (gateway/offline form) is removed. "Pay now" re-adds the item to the cart; "Cancel" cancels the request.
- The temporary course is deleted when the order is paid, or when the request is cancelled, rejected or expires. Auto-cancel (1.3.35) still applies.
- A paid sponsorship is always set to active now (the "automatically activate" switch is gone). Activation happens the moment Tutor marks the order paid, not on the next page view. If the plan change overlaps a running plan, it is scheduled to start when that one ends, as before.


# 1.3.35
- Abandoned sponsorship requests are now cancelled automatically.
- New setting (Sponsorship > Plans): "Auto-cancel unpaid requests after (days)", default 3, 0 = off.
- Runs in the hourly sponsorship cron. A request is cancelled only if it is still Pending, its native Tutor order is unpaid and incomplete, and no offline payment was submitted for it.
- The native Tutor order is set to Cancelled together with the sponsorship; the tutor gets the usual cancellation email.


# 1.3.34
Sponsorship (boosting) payments no longer depend on hidden or fake Tutor courses.

- The sponsorship record itself is now the item of a real native Tutor order (unpaid until paid). No course post is created, no cart is used, nothing is enrolled.
- New pay step in the Sponsorship area: Tutor's active payment gateways (Stripe, PayPal, manual methods...) and the offline-payment module's methods, with the site administrator as payee. Billing details are collected there when a gateway needs them.
- Sponsorship revenue is recorded as 100% site earnings (no instructor share, no tax line).
- The sponsorship activates when the native order is paid (gateway, or the admin confirming an offline payment). Reconciliation now matches the order's item to the sponsorship record.
- Unpaid requests show "Pay now" in My sponsorship status and can be cancelled.
- One-time upgrade: old hidden checkout products are moved to the trash (not deleted), existing Tutor orders/items that pointed at them are re-pointed to their sponsorship record, and the old product options/pending meta are removed.
- Boosting/ads features (tiers, carousel, stickers, tutor boost, manager) are unchanged.


# Tutor Learning Suite 1.3.33

## Guardian-to-admin moderation workflow
- Parent approval now writes the exact native pending database status directly (`hold` for Tutor course ratings, `0` for WordPress comments), without firing transition hooks that could normalize the Tutor custom status and mark a review published.
- Admin transition handlers now verify the persisted database status before marking feedback published. Tutor's `tutor_course_review_approved` callback also refuses to publish while the record is still held/pending.
- Added **Tutor LMS → Feedback Moderation**, a dedicated administrator queue for all child feedback that has completed guardian approval and still awaits an administrator.
- Administrators can publish or reject from that queue. Publication updates the native Tutor/WordPress status only after an explicit administrator action.
- Removed the parent's **Request changes** action. Parents can approve for administrator review or reject. Existing historical `parent_changes_requested` records remain recognized for compatibility, but no new change requests can be created.
- No data migration, guardian relinking or child permission reset is required.

## Verification
- Updated feedback moderation regression coverage for pending-status persistence, admin queue visibility, guardian approval verification and removal of request-edits UI.
- On opening the administrator queue, recover older reviews that were marked published at parent approval but have no recorded administrator-approval timestamp; return them to the native pending state.
- Recovery also handles the previous transition-hook bug that could create an administrator-approval timestamp within the same request as the guardian decision: an approval timestamp within five seconds of the guardian approval is treated as part of that old parent action, not as a separate administrator decision.
- New final administrator approvals record the administrator user ID as well as the approval timestamp.


# Tutor Learning Suite 1.3.32

## Guardian feedback approval access fix
- Move guardian feedback decisions from `wp-admin/admin-post.php` to the authenticated front-end Tutor Family dashboard. This avoids managed-host/security-plugin rules that deny non-administrator accounts access to admin-post endpoints.
- Keep the authenticated admin-post action as a compatibility fallback.
- Verify the action nonce, active guardian-child relationship, recorded guardian and child IDs, and current guardian user before accepting approve/reject/request-edits decisions.
- Return parents to the Family & Guardian dashboard with a clear success, expired-form or authorization notice instead of a generic access-denied page.
- Keep parent approval separate from final administrator moderation; approved feedback remains pending for the site administrator.
- No data migration or parent-child relinking is required.


# Tutor Learning Suite 1.3.31

## Feedback moderation and review fixes
- Make native Tutor course review staging independent of `$_REQUEST['action']`; Tutor's own `tutor_before_rating_placed` action is the reliable capture point.
- Recover child-authored course reviews, Q&A, comments and private-session ratings that earlier plugin versions saved without guardian-stage metadata, including records whose comment status was already approved or whose stage was set to admin-pending without an actual recorded guardian decision.
- Treat `admin_pending` and `published` as guardian-approved only when matching guardian ID, child ID, guardian reviewer ID and review timestamp are all recorded.
- Block legacy admin approval paths from publishing restricted-child feedback until the guardian decision is recorded; automatically route missing-stage records to the approved guardian queue.
- Keep previously guardian-pending records pending if a third-party moderation screen changes the native comment status.
- Return edited/resubmitted child reviews to guardian approval before administrator moderation, including reviews previously in the admin-pending stage.
- Keep private-session review edits in the same guardian-first flow.
- Remove `sprintf()` use around translated percentage labels and private-session duration labels to avoid PHP 8 `ValueError: Unknown format specifier` when translations alter percent placeholders.
- Rename the booking form's local settings variable for clarity.

## Compatibility
- Retains native Tutor LMS rating/comment records and existing feedback metadata; no database migration or parent-child relinking is required.
- Reviewed against the supplied Tutor LMS Core 4.2.1 and Tutor LMS Pro 4.2.0 source.


# Tutor Learning Suite 1.3.30

## Review submission reliability
- Course reviews now use Tutor LMS Core's native AJAX handler for nonce validation, enrolment checks, database persistence and JSON responses.
- Restricted-child course reviews are checked before the native handler and moved to guardian-first moderation after Tutor fires `tutor_after_rating_placed`.
- Private-session review submission now catches unexpected PHP Throwables and returns a safe error message with a stable support reference. Technical logs include only exception class/file/line; review text and account data are never logged.
- The private-session review flow continues to stage child feedback in the same guardian-first queue.

## Compatibility
- Intended for the previously supplied Tutor LMS Core 4.2.1 / Pro 4.2.0 source baseline.
- No database migration or parent-child relinking is required.


# Tutor Learning Suite 1.3.29

## Feedback moderation defaults
- Enabled administrator moderation by default for every native Tutor LMS course review, using Tutor LMS Core 4.2.1's `enable_course_review_moderation` option filter.
- Held all WordPress comments on course and lesson posts for administrator review by default, including adult learner and parent-authored comments.
- Preserved the restricted-child workflow: guardian review first, then administrator review.
- Adult and parent-authored private-session ratings continue to require administrator moderation; restricted-child session reviews require guardian approval before administrator moderation.
- Added the `tls_feedback_admin_moderation_enabled` filter for site owners who deliberately want to override the default.

## Compatibility and safety
- Does not change the child's underlying user ID or public privacy-safe author label.
- Does not apply public-comment moderation to unrelated WordPress post types or Better Messages conversations.
- No database migration or guardian relinking required.


# Tutor Learning Suite 1.3.28

## Child feedback: guardian review, then administrator moderation
- Added a shared feedback moderation workflow for restricted learner course reviews, course/lesson comments, lesson replies, Tutor Q&A and private-session tutor reviews.
- The learner remains the recorded account author. Public-facing child feedback uses the privacy-safe author label “Learner” and omits the learner email address.
- A submission from a restricted learner is held for the approved guardian first. Guardian decisions are approve for administrator review, reject, or request edits with an optional note.
- After guardian approval, the native WordPress/Tutor record stays pending until an administrator approves it. Native Tutor course-review moderation and the private-session review admin screen cannot publish an item while it is waiting for guardian approval.
- A learner can see whether feedback is awaiting guardian approval, awaiting administrator moderation, or has been returned for changes in Family & Privacy.
- Guardian review is authorized against the current approved link, consent and adult-verification state. A parent cannot moderate another child's feedback.
- Tutor LMS 4.2.1 loads Q&A with direct SQL that does not filter by WordPress comment status. Added a scoped query filter so child Q&A remains hidden from instructors/other learners while awaiting guardian approval and is visible to administrators only after the guardian stage.
- Lesson replies use the same guardian approval gate as new lesson comments.
- Private-session reviews can be submitted by the child who was the designated learner, even when the parent booked the session. Completion, non-tryout and final access conditions remain required.
- Parent-authored private-session reviews remain authored by the parent; the guardian-first stage applies to child-authored feedback.

## Privacy and moderation notes
- Parent approval does not publish feedback. It only sends it to the administrator queue.
- Parent rejection and requested edits keep the previous submission unpublished. Learners are directed to resubmit revised feedback from the course/session page.
- Private messages remain subject to the separate Better Messages child-safety rules; this release does not route private messages through feedback moderation.

## Verification boundary
- PHP syntax and source-level regression suites are run for this release.
- Live WordPress/Tutor LMS/Better Messages behavior still requires staging verification with the installed plugin versions and the active theme.


# Tutor Learning Suite 1.3.26

## Parent learning overview
- Parent course summaries now start from Tutor LMS Core's canonical `get_enrolled_courses_ids_by_user( $child_id, true )` API, then fetch only the corresponding enrollment and course rows for the linked child. This aligns the Family & Guardian dashboard with Tutor's learner dashboard enrollment source and includes bundle-linked courses where Tutor exposes them.
- The prepared lookup still uses the child ID as the enrollment `post_author`, checks Tutor's `tutor_enrolled` record, and limits the display to 20 recent courses.
- Existing progress-sharing consent and guardian authorization remain required. No parent/child links or learner records are migrated or recreated.

## Better Messages child-safety integration
- Added/verified server-side restrictions through Better Messages' own hooks for new conversations, replies, reading, invitations, participant additions, chat-room joining and group-thread access.
- Restricted child accounts may use a supervised conversation containing their approved guardian; a tutor/instructor may be included only when the guardian is also a participant.
- Direct child-to-tutor conversations without the guardian, child-to-child conversations, guests, open chat-room joins and unsupervised group threads are denied.
- Supervised group conversations are explicitly granted through Better Messages' group access filter; unsupervised group conversations remain denied.
- Adult-only conversations retain Better Messages' existing permission behavior.

## Verification boundary
- Source tests cover canonical enrollment lookup and the Better Messages authorization matrix. A live WordPress/Tutor LMS/Better Messages instance is still required to verify actual stored enrollment records and runtime hook behavior for the installed Better Messages version.


# Tutor Learning Suite 1.3.25

## Fix: guardian-approved free enrolment still blocked

- Replaced the runtime free-enrolment permission read with a side-effect-free validation of the approved guardian relationship, stored guardian consent and child free-enrolment permission. The previous path re-entered the general age/relationship synchronization routine during enrolment checks; that routine is intended for reconciliation and can revoke stale metadata while the request is being authorized.
- Parent permission save now verifies the updated meta write and uses the same read-only approval checks used by the child enrolment path.
- Added a single decision function for child free-course enrolment. It returns a specific denial reason for invalid student, age category, guardian approval, missing parental permission, invalid course, paid course, or additional purchase/access requirements.
- Server-side Tutor hooks use the same decision function, and audit events now include a sanitized denial reason.
- Blocked native enrolment posts now return to the course page when the browser referer is missing, rather than falling back to the site homepage.
- Browser detection of Tutor's native free-enrolment form now relies on Tutor's hidden action and course-ID fields, not theme-dependent CSS classes or method attributes. Server-side authorization remains mandatory.

## Security boundaries

- Paid and purchasable courses remain blocked for restricted child accounts.
- Guardian relationship, age band, guardian verification, student acceptance and the explicit child permission are all checked on the server.
- No guardian relinking or data migration is required.

## Validation

- Added runtime tests using mocked WordPress/Tutor APIs for the complete parent permission -> child read -> free enrolment authorization path.
- Added negative tests for paid courses, purchasable courses, disabled permission and unapproved guardian relationship.
- Tested against Tutor LMS Core 4.2.1's `tutor_before_enroll` and `tutor_enroll_data` enrollment path.
- Live WordPress transaction still requires staging verification.


# Tutor Learning Suite 1.3.24

## Fix: free Tutor enrolment was still intercepted in the browser

- Removed the dependency on the server-localized `freeSelfEnrollmentCourseId` when identifying Tutor LMS Core's native free-enrolment form. Theme templates and page caches can make that presentation-only value unavailable even when the saved guardian permission is valid.
- The browser now allows only the native `tutor-enrol-course-form` carrying `tutor_course_action=_tutor_course_enroll_now` and a positive `tutor_course_id` to reach the server.
- This is not an authorization bypass. Server-side `tutor_before_enroll` and `tutor_enroll_data` checks still verify the current learner, age band, approved guardian relationship, saved child permission, canonical Tutor course price type and course purchasability.
- Paid checkout, cart, purchase, reviews, comments, messaging and other restricted actions remain blocked.

## Data and migration

- No guardian relinking or database migration is required. Existing approved family links and the child's saved free-course setting are retained.
- Asset version is bumped to 1.3.24 to invalidate stale cached JavaScript.

## Compatibility

- Reviewed against the supplied Tutor LMS Core 4.2.1 native `tutor-enrol-course-form` and `_tutor_course_enroll_now` implementation.
- Live enrolment still requires staging verification on the active theme and site configuration.


# Tutor Learning Suite 1.3.23

## Fix: permitted free-course enrolment was intercepted in the browser

- The child-safety modal script previously intercepted every enrolment-looking click and form submission for restricted learners, even when a guardian had explicitly enabled free-course self-enrolment.
- The server now localizes a narrowly scoped exception only on a single Tutor course page after verifying the current child, approved guardian relationship, child age band, saved guardian permission, canonical Tutor price type and non-purchasable status.
- The browser exception matches Tutor LMS Core's native `tutor-enrol-course-form`, `_tutor_course_enroll_now` action and exact `tutor_course_id`.
- Only that exact free-course form is allowed through the modal guard. Paid checkout, cart, purchase, review, comment, messaging and other restricted actions remain blocked.
- Server-side `tutor_before_enroll` and `tutor_enroll_data` checks remain authoritative.

## Compatibility

- Reviewed against the supplied Tutor LMS Core 4.2.1 enrolment template and `EnrollmentModel::do_enroll()` implementation.
- Live enrolment still requires staging verification on the active theme and site configuration.


# Tutor Learning Suite 1.3.22

## Simplified child course enrolment

- Child/transition/restricted learner accounts may self-enrol only in courses whose Tutor LMS canonical `_tutor_course_price_type` is `free`, and only when a verified, administrator-approved guardian has enabled the separate free-course permission for that child.
- The permission is default-off, managed by the guardian in **Family & Guardian**, and revoked on unlink, administrator revocation, age-band change, missing age data, or failed guardian verification.
- Paid courses remain blocked from child checkout and ordinary self-enrolment. A parent purchases a paid course for a linked child using Tutor LMS Pro's native Course Gifting flow.
- The custom duplicate gift form and custom parent course-enrolment panel were removed. A small linked-child selector prefills the single native Tutor Pro gift form; Tutor Pro remains responsible for checkout, delivery and claiming.
- A child may claim a paid gift only while Tutor Pro is processing its real gift-claim AJAX action and the scheduler record, recipient account, paid course, approved purchaser/guardian and completed order all match.
- Child free enrolments and guardian gift claims are attribution-logged on Tutor LMS enrolment records.
- Fixed child-side permission display so it no longer incorrectly requires the parent to be the current viewer.

## Compatibility

- Source integration reviewed against supplied Tutor LMS Core 4.2.1 and Tutor LMS Pro 4.2.0 archives.
- Requires Tutor LMS Core and Pro to remain active. Live checkout/claim testing is still required on the site's staging environment.


# Tutor Learning Suite 1.3.21

## Parent-to-child course gifting repair

- Detect Tutor LMS Pro Course Gifting through its actual `TutorPro\\GiftCourse` namespaced classes. The previous release checked nonexistent global aliases and could therefore leave the guardian CTA unavailable.
- Render the guardian selector on Tutor Core's real `tutor_course/single/entry/after` hook, after Tutor Pro has rendered its native Gift Course button and single gift modal.
- Reuse Tutor Pro's one native `#tutor-gift-this-course-form`; do not render a duplicate modal/form. Tutor Pro 4.2.0 binds its checkout JavaScript to the first form with that ID, so duplicate forms could open a modal whose submit event was not connected to checkout.
- Open the native gift modal via the actual Tutor Pro button, with Tutor's modal API as a fallback, and lock the exact `recipient_name` and `recipient_email` fields to the selected linked child.
- Fix the restricted-child claim exception to recognize Tutor Pro's exact `tutor_pro_gift_enrollment` AJAX action and `TutorPro\\GiftCourse\\GiftEnrollment::ajax_gift_enrollment` handler.
- Validate the real processed gift scheduler record, completed Tutor order, course ID, parent purchaser ID and child's account email before allowing the Tutor enrolment hook to proceed.
- Consume the parent-to-child authorization after the child enrolment is created and record the gift reference and guardian attribution on the enrolment.
- Hide ordinary Gift Course initiation from restricted learner accounts; adult accounts continue to use Tutor Pro's normal gifting feature.

## Compatibility and verification

- Target source inspected: Tutor LMS Core 4.2.1 and Tutor LMS Pro 4.2.0 supplied with the project archives.
- This release changes only Tutor Learning Suite. It does not modify Tutor LMS Core, Tutor LMS Pro or the active theme.
- Automated PHP/JS syntax and source-contract regression checks are run for this package. A real checkout and gift-claim still need a staging WordPress instance with the corresponding Tutor LMS versions and payment gateway.


# Tutor Learning Suite 1.3.20

## Guardian course-enrolment and Tutor LMS gifting fixes

- Removed the viewer-sensitive `can_gift_course()` gate from the parent workflow. The panel now checks the site-wide Tutor Pro Course Gifting setting and paid-course status instead of course ownership.
- Rendered Tutor Pro's official `single.course.gift-this-course-modal` template inside the guardian panel with a course-specific modal trigger, so the parent flow no longer depends on Tutor's ordinary Gift button being visible in the purchase box.
- Made the paid-course “Enrol my child” CTA active; clicking without selecting a child now displays an inline instruction.
- Improved AJAX authorization errors to distinguish an expired nonce, unavailable gifting, an unlinked child, missing guardian/learner consent, and an existing enrolment.
- Fixed Tutor student detection to fall back to the Tutor student role and configured student meta key even when `TUTOR\User::is_student()` returns false.
- New child accounts now store both the configured Tutor student meta marker and the suite's legacy marker.
- Expanded native gift-claim context detection to recognize Tutor Pro route/action naming variations and split method/class indicators across stack frames, while retaining the child/course-specific grant and live guardian permission checks.

## Security retained

- Parent must be an approved adult guardian.
- Child must be linked, approved and have explicitly enabled guardian course-enrolment consent.
- Paid gifts are still created and paid for through Tutor LMS Pro; this release does not create a custom paid order.
- Restricted children remain blocked from ordinary self-enrolment and checkout. Only a valid Tutor Pro gift-claim context with a matching parent authorization may pass the restricted enrolment guard.


# Tutor Learning Suite 1.3.19

- Fixed the missing guardian course-enrolment panel on Tutor LMS single-course pages by rendering it through Tutor LMS purchase and single-course template hooks, with the WordPress content filter retained as a fallback.
- Removed silent early exits when a verified adult has no linked child or when linked learners have not completed the required approval/consent steps. The course page now explains what is missing and links to Family & Privacy, including a locked notice for age-unknown logged-in accounts.
- Linked children who have not yet enabled parent course-enrolment consent are reported clearly; only learners whose relationship and enrolment permission are valid can be selected. Already-enrolled children are reported rather than offered twice.
- Renamed the parent action to **Enrol my child** for both free-course assignment and paid Tutor LMS gifting.
- Kept server-side permission checks, nonces, child-specific gift authorization, and audit logging unchanged.


# Tutor Learning Suite 1.3.18

- Added a separate **Allow my parent/guardian to enrol me in courses** permission in the learner's Family & Privacy settings. It is separate from learning-progress sharing and private-session booking, and is revoked when age/guardian verification becomes unknown or changes across policy bands.
- Added a guardian course panel to published course pages. It lists only linked learner accounts whose relationship is approved and whose course-enrolment consent is enabled.
- Free courses can be assigned directly to the selected child by an approved guardian. The enrolment is a normal Tutor LMS `tutor_enrolled` record under the child's account, marked as guardian-assigned, with no payment or fake gift order.
- Paid courses hand off to Tutor LMS Pro's native Gift Course flow. The selected child's existing name/email are populated in the native gift form; Tutor LMS continues to own checkout, payment, delivery and claiming.
- Added a 180-day, single-course, child-specific authorization for restricted learners to claim a guardian-selected native gift. The exception is limited to Tutor LMS Pro Gift Course claim/reveal execution and does not enable ordinary self-enrolment or checkout.
- Added audit events and enrolment metadata for guardian free-course assignments and native gift authorizations/claims.
- Added a responsive parent course panel and browser-side integration for the native Tutor LMS gift modal.


# Tutor Learning Suite 1.3.17

- Added the Family & Privacy entry to the learner dashboard for tutor accounts as well as learner accounts, using a Tutor LMS user-group icon before the page name. It remains a learner-dashboard entry and is not added to the instructor navigation.
- Allowed tutor accounts to load the combined Family & Privacy page from the learner dashboard.
- Removed course taxonomy tags from course cards.
- Added a short course description with a **More** link to the full course page.
- Moved course categories to the lower card metadata area, below the description and course statistics.
- Replaced restricted-action error pages with a styled, accessible pop-up notice. Client-side action interception provides immediate feedback; server-side enrolment, payment, review, comment and community restrictions remain enforced. AJAX requests receive structured errors, and ordinary blocked POSTs return to the referring page with a one-time notice.


# Tutor Learning Suite 1.3.16

- Removed the explanatory sentence under the course search field to reduce sidebar clutter.
- Replaced the all-levels course badge star with a layered-levels icon.
- Combined Family & Guardian and Privacy & Security into one learner-dashboard page with accessible icon tabs; removed these settings from the instructor menu.
- Expanded approved guardian learning summaries with the latest 20 course enrolments and progress, plus recent quiz marks/results and assignment submission grades where the active Tutor LMS schema exposes those records.
- Updated the learner permission description to clearly cover courses, progress, quiz grades and assignment grades.
- Blocked restricted child/transition/age-unverified learner accounts from self-enrolling in free or paid courses and from checkout; existing/admin-assigned learning remains accessible.
- Preserved the existing verified guardian relationship, administrator approval and learner-consent checks before showing child learning data.
- Kept all reviews attributable to the real adult author. The existing completed private-session review flow remains available to the guardian who booked that session and is labelled Parent/guardian; Tutor LMS course reviews are not impersonated on behalf of a child.


# Tutor Learning Suite 1.3.15

- Kept Education and Teaching Experience inside the blue public tutor header in a matching collapsible drawer.
- Confirmed Teaching Details order: Locations, Subjects, Languages, Teaching Methods.
- Tightened desktop course-search sidebar spacing and removed inherited row-child margins/minimum heights that could leave blank vertical gaps.
- Fixed X and GitHub brand glyphs and restored visible SVG icons for Instagram, TikTok, Telegram and WhatsApp after Tutor/theme fallback replacement.
- Further restricted the verified-instructor badge to an actual public-profile heading, excluding account menus, avatar menus, dropdowns and navigation.


# Tutor Learning Suite 1.3.14

- Moved Education and Teaching Experience into a matching collapsible drawer inside the blue public tutor profile header.
- Reordered Teaching Details to show Subjects before Languages and Teaching Methods, swapping Subjects with Teaching Methods.
- Grouped course-search helper copy directly below its search field and removed inherited minimum-height spacing from the sidebar.
- Replaced theme-dependent social glyphs with suite-owned SVGs; added X and GitHub icon recognition and profile fields.
- Restricted the verified-instructor sticker script to the actual public Tutor profile content, preventing it from appearing in the Tutor LMS account/avatar menu.
- Bumped plugin and component asset versions to invalidate cached CSS/JavaScript.


# Tutor Learning Suite 1.3.13

- Improved the course-search sidebar with concise guidance and a short list of the most-used top-level categories.
- Replaced text-based tutor contact pictograms with compact, self-contained SVG icons.
- Reworked education and teaching-experience information into clear, visible profile cards below the profile header.
- Fixed spacing between tutor statistics and their labels.
- Added a private-learner count based on distinct learners with completed, non-tryout private sessions.
- Added pagination to the tutor's public course list, using a dedicated page query parameter.
- Bumped asset/plugin versions so browsers fetch the updated styles.


# 1.3.12
- Privacy: adult students can now change their profile visibility and contact privacy. Under-18, transition and age-unverified accounts stay locked to private.


# 1.3.11
- Each course type (prerecorded, direct, hybrid), format (group, 1v1) and level (beginner, intermediate, expert, all levels) has its own icon and colour; text still shows on hover.


# 1.3.10
- Suite course cards show the Sponsored sticker (top-right of the image).
- Course type / format / level badges are icon-only; text appears on hover or keyboard focus.
- Rating, learners, duration and live sessions show icon + number; full wording on hover/focus.


# 1.3.9
- Sponsored/featured carousel moved out of the filter sidebar to the top of the results column, above the courses.


# 1.3.8
- Course archive/search: filters and search bar now render as a left sidebar and are always visible. Cause: the TCSP bar is hidden until Tutor's own JS wrapper moves it; the suite catalog has no such wrapper, so it stayed hidden. The catalog now forces it visible and lays out sidebar + results (stacks on mobile).
- Course card redesigned: type/format/level badges, category, tutor with verified tick, rating, learners, duration, live sessions, tags, price and "View course".
- Applies to the archive, category pages and [tls_courses]/course shortcodes.


# Tutor Learning Suite 1.3.7

## Courses page: search bar, filters and suite course card

Root cause: the suite's catalog template only replaced Tutor's page on a few exact conditions (course post-type archive, category archive, or the page selected as Tutor's course archive). Any other way of showing courses (a normal page with Tutor's `[tutor_course]` shortcode, a page-builder widget, a theme template) kept Tutor's default cards and had no search/filter bar, and the filter script was not loaded there.

- Tutor's `[tutor_course]` shortcode is now replaced by the suite catalog: search bar + TCSP filters + suite course cards + result count + pagination. Supported attributes: `limit`, `category` (slug list), `author`.
- `[tutor_suite_courses]` now renders the same full catalog (attributes: `limit`, `category`, `author`, `filters="no"` for cards only).
- Any course card Tutor renders itself (widgets, related courses, builder grids) now uses the suite card (`loop/course` template override; dashboard pages are untouched).
- If `[tcsp_filters]` is unavailable or outputs nothing, a built-in search form (keyword, category, price) is shown instead of nothing.
- New filter `tcsp_is_course_context`; the suite uses it so the filter script/styles load on pages that contain `[tutor_course]` or `[tutor_suite_courses]`.
- Catalog queries made by the shortcode exclude sponsorship checkout products.
- `TLS_Integration::course_card()` is public; new `templates/loop-course.php`.

## Tutor public page

- The page is now a centred card (max width 1180px with side margins) instead of running edge to edge (the layout grid was 1440px wide with no margins).

## Not verified

Syntax and the six bundled regression suites pass, and the catalog renderer was run against stand-in WordPress functions. It has not run inside a live WordPress/Tutor LMS: check a page with `[tutor_course]`, the course archive and a tutor profile on staging.


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


# Tutor Learning Suite 1.3.5 — Changelog

## Fixed

- Fixed instructor dashboard lockout caused by verification upload validation running during ordinary dashboard rendering. The eligibility gate now checks only account state, cooldown and rate limits; required image presence, MIME and size are validated only when an application is submitted.
- Added a clear verification form error notice and removed both transient verification query arguments from the cancel action. Submission redirects now sanitize the return URL and avoid stacking stale error flags.
- Restored the TCSP filter bar on the Tutor-configured course archive page, including when Tutor renders that archive through the suite's custom archive template.
- Added the backward-compatible `[tsp_home_search]` shortcode as a search/filter widget. Its form targets the configured Tutor course archive, where existing TCSP filtering, sorting, pagination and sponsored-course logic continue to run.
- Enqueued TCSP filter/autocomplete assets on regular pages that contain `[tcsp_filters]` or `[tsp_home_search]`.
- Kept the existing `[tcsp_filters]` and course archive rendering behavior.

## Compatibility

- No database migration.
- Existing tutor verification records, profile metadata, course data, sponsorship metadata and filter settings are preserved.
- Internal Tutor Course Search Pro asset version bumped to 4.2.24 for cache refresh.


# Tutor Learning Suite 1.3.4 — Changelog

**Release type:** Security and reliability remediation  
**Upgrade from:** 1.3.3  
**Date:** 20 November 2026

## Security and authorization

- Restricted private-session calendar REST queries to the signed-in user; only users with the site `manage_options` capability can request another account's calendar. The response injector applies the same ownership rule.
- Included guardian-booked learner sessions in the learner's own calendar. Tentative `approved` sessions are no longer shown as final calendar events.
- Refreshed learner age and guardian status synchronously before delegated parent progress/booking access is authorized. Scheduled synchronization is now reconciliation only.
- Required an explicit admin review/attestation before a newly linked family relationship can receive delegated permissions. The attestation documents the site's check; it is not independent digital identity verification.
- Changed legacy family migration to a versioned keyset cursor and batches of 200 so old installations that already marked the earlier 5,000-record migration complete are not skipped.
- Made valid-looking family invitation responses generic and added per-parent and per-target cooldowns.
- Replaced audit-detail denylist filtering with event-field allowlists and value limits.
- Verified audit-table existence and required columns before recording its schema version.
- Read back and decrypt-verified DOB ciphertext before deleting legacy DOB data or reporting a successful migration.

## Booking, payment and enrollment integrity

- Added expected-old-status conditional updates to private-session transitions and applied proposal eligibility checks to bulk approval.
- Added cancellation with participant/admin authorization, status checks and the configured payer notice window. Ustudy does not process refunds or hold funds; payment settlement remains outside the plugin.
- Prevented temporary checkout course deletion before the related session state transition succeeds.
- Applied a connection-scoped per-order MySQL advisory lock and allowed-state checks to offline payment approve/reject/reopen/expiry workflows. The connection-scoped MySQL advisory lock serializes Suite payment decisions through Tutor payment hooks. External Tutor/custom order writers that do not use this lock still require live integration testing.
- Blocked tutors from manually enrolling restricted/unknown-age learners into paid courses. Manual enrollment remains available for free courses.
- Excluded private-session internal ratings from public tutor rating aggregates.
- Normalized private-session deadline and reminder calculations using absolute timestamps and the appropriate site/tutor timezone.

## Files, data and performance

- Moved new instructor-verification images and payment receipts to a private directory outside the public web root; downloads are served through authenticated handlers.
- Added batched migration for legacy verification images and receipts. Failed legacy-file moves remain flagged for administrator review and are not silently treated as complete.
- Validated instructor-verification notification email settings before saving.
- Validated both ID image uploads before attaching them and rolled back attachments if a later step fails.
- Added bounded keyset batches and user-meta cache priming to tutor directory candidate processing. Global filtering/ranking still retains the matched lightweight result set in memory.
- Added bounded pagination to the family management list and removed linked-student email addresses from that list.
- Added a safe fallback for ad database ordering.
- Public ad tracking now requires a short-lived signed render token tied to an ad and placement, validates campaign activity using the WordPress site timezone, and adds event deduplication and rate limiting.

## Tests and limitations

- Added 29 audit-remediation source-contract checks.
- Validated 70 PHP files with `php -l` and 19 JavaScript files with `node --check`.
- Existing included suites pass: 11 age-safety, 10 family-revocation, 25 layout/booking, 28 audit-remediation and 162 Course Format checks.
- These tests are local unit/stub/source-contract checks, not live WordPress/Tutor LMS integration tests or a penetration test. The browser suite requiring `jsdom` was not run because that dependency is unavailable in this environment.


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


# Tutor Learning Suite 1.3.1

## Child-safety and review enforcement

- Hardened student-account identification with a WordPress `tutor_student` role fallback when Tutor LMS's student helper or legacy marker is unavailable. Age-unknown student accounts remain restricted.
- Added sanitized audit entries when restricted students attempt public Tutor interactions, WordPress comments, or blocked REST community/review writes. No review text, DOB, email, phone or message content is recorded in these entries.
- Guardian private-session reviews continue to use the guardian's own WordPress account ID. The `(Parent/guardian)` label is now persisted directly in `comment_author` as well as review metadata, so Tutor templates that read the raw comment author do not omit the attribution. The display filter is idempotent for existing 1.3.0 reviews.
- Guardian review audit entries record the booking/session, tutor and learner IDs, not the review text.
- Guardian reviews require the guardian's own booking to be marked `completed` and pass the existing session-rating checks. Children cannot submit private tutor ratings or public comments.
- Course reviews are not submitted on behalf of a child. A parent may submit a course review only when the parent personally satisfies Tutor LMS's native enrollment/eligibility checks.

## Compatibility

- No database schema change and no DOB encryption-format change. Keep the existing `TLS_AGE_ENCRYPTION_KEY` unchanged.
- No Tutor LMS Core/Pro files or active theme files are modified.
- Existing 1.3.0 guardian review metadata remains readable; the author filter still labels older reviews.


# Tutor Learning Suite 1.3.0

## Child safety and age policy

- Added a centralized Child / Transition / Adult / Unknown age policy while preserving Tutor LMS's Student role.
- Added AES-256-GCM DOB encryption using a separately configured 32-byte secret, with support for previous-key migration and legacy AUTH-salt encrypted values.
- Added chunked migration batches for existing DOB records, including processed-record markers to avoid repeatedly scanning the same batch.
- Age-unknown accounts fail closed and do not retain parent learning or booking permissions.
- Any change between known age bands revokes delegated parent access and requires fresh consent where appropriate.
- Child and Transition guardian relationships require administrative review; Transition and Adult sharing remain off until the student explicitly enables each permission.
- Added admin verification marker requirements for Child/Transition parent access and made malformed/orphan requests rejectable.
- Added/retained a sanitized application audit trail and administrative review screen.

## Public interactions and purchases

- Blocked public Tutor LMS reviews, lesson comments, Q&A/discussions, WordPress comments and public BuddyPress activity for restricted students.
- Blocked BuddyPress messaging both from restricted students and to restricted students, including inbox access and REST access.
- Removed Tutor LMS billing/review dashboard destinations for restricted student accounts.
- Blocked paid Tutor checkout and WooCommerce add-to-cart/checkout for restricted student accounts.
- Learning access, lessons, quizzes, assignments and free enrollment remain available.

## Parent-attributed private-session reviews

- Restricted students cannot rate tutors publicly.
- Parent-booked private-session reviews remain under the parent's own account and are labelled `(Parent/guardian)`.
- Private-session tutor ratings are now shown and accepted only after the session is marked completed.

## Privacy and family workflow

- Transition-age existing-account links now wait for the site's administrator review; the student then explicitly enables sharing.
- Adult re-consent is timestamped against the linked parent when the student enables sharing.
- Unlink/revoke clears consent and verification metadata.

## Compatibility and limitations

- No Tutor LMS Core/Pro files or WordPress theme are modified or bundled.
- Compatibility target remains Tutor LMS Core 4.2.1 and Pro 4.2.0 from the supplied archives.
- Paid-course child enrollment is still tutor/admin-managed; parent checkout does not automatically enroll a child.
- BuddyPress is covered by explicit integration hooks; other messaging plugins are not automatically covered.

## Private tutoring UI enforcement

- Restricted student accounts no longer render the private-tutoring request form; the interface directs the student to an approved guardian.
- Guardian-only accounts see only linked learners for whom `can_parent_book()` currently passes. The guardian must explicitly select a learner; the form does not offer a misleading “Myself” choice.
- Server-side AJAX authorization remains authoritative and independently checks every submitted learner ID.


# Tutor Learning Suite 1.2.0

## Integration
- Added a Tutor LMS integration layer for public instructor-profile and course-archive template replacement, while preserving native single-course pages.
- Added Family & Guardian and Privacy & Security destinations in Tutor LMS dashboard navigation.
- Added parent linking for existing accounts with secure expiring invitations and student approval; acceptance records encrypted date of birth for age policy.
- Preserved parent-created child accounts and enabled multiple linked children per parent.
- Added fail-closed age handling: unknown age blocks parent progress/booking access; linked legacy accounts without encrypted DOB must complete age details.
- Added adult-consent renewal when an account reaches the configured age threshold. The default is 19 and is configurable through `tls_age_of_majority_threshold`.
- Added family progress display sourced from Tutor LMS enrollments and allowed private tutoring records.

## Private tutoring
- Replaced the new private-session proposal acceptance path's Tutor LMS cart/checkout behaviour with direct-to-tutor offline payment instructions.
- Added student payment declarations and tutor receipt confirmations, with authorization and atomic state checks.
- Preserved legacy order listeners/cleanup for pre-existing private-session records; new requests do not create hidden Tutor LMS checkout courses.
- Added server-side parent/child age and consent checks to delegated bookings.
- Bumped private-session asset version to invalidate old browser caches.

## Presentation
- Added/updated theme-agnostic styles for family access, availability calendars and direct payment instructions.
- No WordPress theme is included or changed.

## Compatibility
- Reviewed against supplied Tutor LMS Core 4.2.1 and Tutor LMS Pro 4.2.0 source archives.
- Does not modify Tutor LMS Core or Pro files.
