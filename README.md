# Tutor Learning Suite — UStudy integration

**Plugin version:** 1.3.48  
**Current release:** [CHANGELOG.md](CHANGELOG.md) (1.3.48 — ads ordering upgrades and sponsorship redesign)  
**Release notes:** [Changelog](CHANGELOG.md) · [Upgrade guide](UPGRADE-1.3.33.md) · [Private-session model](docs/archive/PRIVATE-SESSION-MODEL-1.3.6.md) · [Audit remediation](docs/archive/AUDIT-REMEDIATION-1.3.4.md) · [Age-safety notes](docs/archive/AGE-SAFETY-1.3.4.md) · [Older notes](docs/archive/)  
**Compatibility baseline:** Tutor LMS Core 4.2.1 and Tutor LMS Pro 4.2.0 (the exact versions in the supplied source archives). Other versions need compatibility testing.  
**Theme:** Not included. Keep your current Tutor-LMS-compatible theme active.

Tutor Learning Suite consolidates the supplied UStudy/Tutor LMS modules and adds the UStudy integration layer. Tutor LMS remains the canonical source of truth for student/instructor accounts, courses, lessons, course progress, quizzes and course enrollments. The suite adds UStudy family/guardian access, age-based safety controls, privacy, instructor profile/catalog presentation, private tutoring and tutor-direct offline-payment records.

The plugin does not include or modify Tutor LMS Core, Tutor LMS Pro, or any WordPress theme.


## 1.3.6 private-session model cleanup

- The calendar is the single source for session format, teaching method, duration, date/time and tutor timezone. The request form no longer repeats visible method, format, requested-hours or date/time controls.
- Requests save the preferred slot in structured `requested_*` fields; the final `session_*` schedule fields are populated only when a tutor sends a proposal (or approves a free tryout at its selected time).
- A preferred slot is not reserved by a pending request. A proposal/scheduled session blocks overlapping times. Declining a proposal clears its unaccepted final slot so the tutor can offer a fresh time.
- Policy text snapshots, matching hashes and consent timestamps are recorded with each new request.
- See [the full field mapping and model boundaries](docs/archive/PRIVATE-SESSION-MODEL-1.3.6.md).

## 1.3.4 security and reliability remediation

- **Calendar privacy:** private-session calendar REST requests are restricted to the signed-in account, with a site-administrator exception. Events booked by a guardian for a learner are included in that learner's own calendar. Tentative `approved` sessions are no longer represented as fully confirmed calendar events.
- **Age-policy freshness:** parent progress/booking checks synchronously refresh the learner's age band and relationship state before allowing access; scheduled sync is reconciliation, not the authorization boundary.
- **Family migration:** legacy family records use a versioned keyset cursor and bounded batches, including sites that had already marked the earlier 5,000-record migration complete. Family management displays bounded pages rather than truncating at 50.
- **Payment and session states:** offline payment decisions use a connection-scoped per-order MySQL advisory lock and allowed source states. Private-session transitions use expected-old-state conditional updates; bulk approval uses the same proposal eligibility rule as single approval. Cancellation is implemented as a state transition with role and notice-window checks. Temporary checkout courses are deleted only after their state transition succeeds.
- **Invitation abuse:** invitation responses do not disclose whether an email belongs to an eligible account; actor and target cooldowns limit repeated requests.
- **Ad tracking:** events require a short-lived signed token issued with a rendered ad, validate active campaign/placement, and use per-render deduplication plus visitor rate limiting. This prevents arbitrary ID fabrication and replay but cannot prove that an ad was actually visible in a human viewport.
- **Private documents:** new identity-document and payment-receipt uploads are stored outside the public web root and served through authenticated handlers. Legacy files migrate in bounded batches. Nginx sites must immediately deny direct access to the old upload paths during migration; see the upgrade guide.
- **DOB and audit reliability:** encrypted DOB writes are read back before legacy data is deleted; audit schema creation is verified before its version is recorded; audit event details use an allowlist.
- **Enrollment and ratings:** restricted/unknown-age students cannot be manually enrolled by a tutor into paid courses; private-session internal ratings are excluded from public tutor aggregates.
- **Directory performance:** instructor candidates are processed in keyset batches with user-meta caches primed per batch. The implementation still retains the matched lightweight result set to preserve global filters and ranking, so very large directories should be load-tested.
- **Timezone handling:** private-session deadlines, slot validation and reminders use consistent absolute timestamps and tutor/site timezone conversion.

## 1.3.3 compact tutor profile, booking sidebar and family-link review

- The public tutor profile uses a responsive two-column layout: the tutor profile and course content on the left, and a private-booking panel on the right. Long teaching details and credentials are expandable instead of taking up the full initial page.
- Tutor contact links keep their custom plugin classes and now show built-in fallback pictograms when the theme or Tutor icon assets do not load. Public-contact privacy remains enforced.
- The private-session calendar uses the tutor's timezone for its initial date and available slots, and displays that timezone to visitors. The server rechecks the selected slot before it accepts a new request.
- Optional learning goals and notes, as well as tutor terms and cancellation policy text, are collapsed by default; required policy agreement remains. Requested total tutoring hours are distinguished from the selected first-session duration.
- Child and transition-age students cannot directly remove an active guardian link. They can report a concern for administrator review. Parents can unlink a student, adult students at/above the configured age threshold can unlink themselves, and administrators can review and end a relationship. Ending a link removes delegated permissions, not the student's learning history.
- No new database tables or columns are added.

## 1.3.2 tutor profile, course cards and weekly availability

- Reuses the existing Instructor Profile Extra form and its saved user-meta keys. The education, experience, location, tutoring-type, subject, language and contact fields are not replaced or migrated.
- The public tutor profile now uses the full-width blue profile card: contact/social links appear as accessible icons under the tutor name, biography follows, and location, teaching modes and languages appear as compact icon facts. Education, teaching experience and subject chips are moved into the same card instead of a sidebar.
- Public contact icons respect the tutor's existing public-contact privacy setting. The private-booking button and booking section appear only when the tutor has enabled private sessions.
- Course cards now show the course image, type, delivery format, level, categories, tutor identity/verification, rating, duration, learners, live-session count, tags and price. The existing Tutor Course Search Pro filter shortcode, main filtered WordPress query and pagination remain in place; the course-search module is unchanged.
- Tutor private-session settings now include a recurring weekly availability editor with seven days, two editable time blocks per day, tutor timezone display, time validation and overlap prevention. The tutor must explicitly enable each block; suggested times are prefilled but not published by default.
- The weekly editor reuses the existing `tlms_private_availability` table. No new database schema migration is introduced. Existing availability rows are preserved; missing day/time-block rows are added without turning them on.

## 1.3.1 review-attribution hardening

- Restricted student accounts remain blocked from public Tutor course/tutor ratings, Q&A, lesson comments and WordPress comments at server-side entry points (AJAX, REST and WordPress comment processing).
- Guardian private-session reviews now persist the `(Parent/guardian)` label in the comment author snapshot as well as review metadata. The comment's `user_id` remains the parent's own WordPress account.
- Guardian review submissions are written to the TLS safety audit table without recording the review text or personal contact details.
- A guardian review is only available for a private session booked under that guardian's own account and only after completion. A parent cannot post a course review as a proxy for a child.

## Installation and upgrade

1. Back up the WordPress database, `wp-content`, and `wp-config.php`. Test on staging first.
2. Confirm Tutor LMS Core 4.2.1 and Pro 4.2.0, or arrange compatibility testing for your installed versions.
3. **Keep the separate DOB encryption key configured before upgrading the suite** as described in [AGE-SAFETY-1.3.4.md](docs/archive/AGE-SAFETY-1.3.4.md). Keep existing WordPress authentication salts unchanged until legacy encrypted dates have been migrated.
4. If the six component plugins are installed separately, deactivate their duplicates before activating this suite: OStudy Ads, Tutor Course Format, Tutor Course Search Pro, Tutor Instructor Offline Payment, Tutor LMS Instructor Profile Extra, and Tutor LMS Private Sessions. Do not delete their data.
5. Keep Tutor LMS Core, Tutor LMS Pro and your existing theme active.
6. Upload `tutor-learning-suite-1.3.44.zip` through **Plugins → Add New → Upload Plugin**, then activate it.
7. Visit **Settings → Permalinks** and save once to refresh Tutor dashboard routes.
8. Visit **Tools → TLS Child Safety**. If upgrading from 1.3.0, no DOB re-encryption migration is required. Review any pending guardian relationships using the site's documented verification procedure.
9. Follow the staging acceptance checklist in [UPGRADE-1.3.4.md](docs/archive/UPGRADE-1.3.4.md), the audit status in [AUDIT-REMEDIATION-1.3.4.md](docs/archive/AUDIT-REMEDIATION-1.3.4.md), and the family-link notes in [AGE-SAFETY-1.3.4.md](docs/archive/AGE-SAFETY-1.3.4.md).

Do not activate the component plugins separately while the suite is active. Doing so can register duplicate hooks/classes and process the same events twice. Deactivation does not delete data.

## Age categories and enforcement

Tutor LMS's existing student role is preserved. The suite uses an internal age category and centralized server-side permissions; it does **not** create a competing WordPress/Tutor LMS role.

| Category | Default age | UStudy handling |
|---|---:|---|
| Child | Under 18 | Restricted public interaction and financial actions; guardian relationship needs student acceptance where an existing account is linked and site-admin review before delegated parent access. |
| Transition | 18 to under the configured threshold | Restricted by default. Guardian relationship requires site-admin review; progress and booking sharing remain off until the student explicitly enables each permission. |
| Adult | Configured threshold and older | Ordinary student permissions. A parent can see or act only after the adult student explicitly consents; consent is revocable. |
| Unknown | DOB/key unavailable or unreadable | Fail-closed: treated as restricted; parent view/booking are denied. |

The default adult-age threshold is 19, configurable with the `tls_age_of_majority_threshold` filter (clamped to 18–25). This is a platform setting based on the product brief, not a legal determination. Obtain Algerian legal advice before launch.

### Restricted account behaviour

Child, transition and age-unknown Tutor LMS student accounts can continue learning through courses assigned to their account, including accessible course content, lessons, quizzes and assignments. They cannot self-enrol in free or paid courses. They also cannot:

- publish Tutor LMS course reviews or public private-tutoring ratings;
- post WordPress comments, Tutor LMS lesson comments or course Q&A/discussion posts;
- create public BuddyPress activity or use BuddyPress private messages (including receiving them);
- use the Tutor LMS billing/review dashboard destinations;
- purchase paid Tutor LMS courses through Tutor checkout;
- use WooCommerce add-to-cart or checkout while logged in with a restricted student account.

The WooCommerce restriction is deliberately site-wide when WooCommerce is active, not limited to Tutor LMS course products. Test this carefully if the website also sells other goods.

A parent or guardian may request a course for a child, but checkout by itself does not enrol the child. A site administrator must manually assign the course after checking the approved guardian relationship. Tutors and guardians cannot create a direct enrolment record for a restricted child account. The parent can submit a private-tutoring booking only when the family relationship, age status, site review and the specific booking permission all pass server checks. Approved guardians can view a child’s recent enrolled courses and progress, plus recent quiz and assignment results, only when the verified relationship and learner-sharing permission allow it.

BuddyPress messaging is conservatively disabled for restricted students and for messages addressed to them. This also blocks tutor-to-child BuddyPress direct messages. Use the approved parent/guardian booking contact path or the site's published support contact until a moderated, guardian-included communication channel is implemented. Other third-party messaging plugins are not automatically governed by these BuddyPress hooks and need their own integration review.

### Reviews and comments

Restricted students cannot create public reviews/comments. A parent/guardian who booked a private tutoring session for a linked learner may submit a review from their **own account** only after that session has been marked `completed` and the other session-rating checks pass. The review's WordPress author ID remains the parent's account, and the stored author display name is labelled `(Parent/guardian)` so the label is not dependent only on a theme/template filter. A restricted child never authors a public review. A parent cannot submit a Tutor LMS course review on a child's behalf; a course review is permitted only when the parent personally satisfies Tutor LMS's own enrollment/eligibility checks.

## Family and guardian permissions

- A parent account must have a decryptable DOB in the Adult category to request or manage family links.
- Parents can create multiple child accounts or request a link to an existing Tutor LMS student account.
- For an existing account, the parent sends an expiring email link; the student signs in, enters their DOB, and accepts or rejects the request.
- Child and Transition relationships remain pending until a site administrator reviews them. The admin must confirm the site's own verification procedure using the explicit checkbox. The checkbox is an audit acknowledgement only; this plugin does not verify identity documents or determine legal guardianship.
- For a Child, the approved guardian may be allowed progress/attendance and booking access. A child cannot directly unlink an active guardian relationship; they can privately report a concern for administrator review. They may still reject an invitation before the link is established. A parent can unlink the child, and an administrator can end the link after reviewing a concern.
- For Transition accounts, the site review does not enable parent access. The student must explicitly enable progress sharing and/or booking after the review. Transition-age students cannot directly unlink an active guardian relationship; they can request administrator review.
- For Adult accounts, all parent sharing is off unless the student explicitly consents. An adult student at or above the configured threshold may end their own guardian link. When a known age band changes, delegated permissions are revoked and require fresh consent where relevant.
- Parent views are restricted to the linked child and the permissions currently recorded for that relationship. Teacher-private notes and unrelated records are not made visible by this policy.

The platform relies on parent/guardian attestation and a site-admin verification procedure. It does not provide government identity verification, legal guardianship validation or an evidence-document workflow. Define and document that procedure with counsel before collecting real children's data.

## Privacy and encryption

- DOBs are encrypted at application level using AES-256-GCM and a separate 32-byte key (`TLS_AGE_ENCRYPTION_KEY`). Do not use a WordPress auth salt as the new key.
- If the key is missing or ciphertext cannot be decrypted, the account is age-unknown and restricted.
- Keep the key secret and include it in secure configuration backups. Never put it in browser code, logs, tickets or chat.
- A user's DOB cannot be changed through the ordinary privacy form after it has been recorded. If it is wrong, use a documented administrator correction procedure; ensure the old value and the correction event are not exposed in logs.
- The audit table records actor/account IDs, event keys, timestamps and sanitized non-DOB details. It is an application audit trail, not tamper-proof storage against a database administrator.
- The daily age refresh uses WordPress Cron. For reliable birthday transitions on low-traffic sites, configure a real server cron to invoke `wp-cron.php`.

See [AGE-SAFETY-1.3.4.md](docs/archive/AGE-SAFETY-1.3.4.md) for the current family-link review, age transition and consent behaviour. Keep the existing encryption key; this update does not change the DOB encryption format.

## Other integration changes

### Tutor LMS dashboard

- Adds one **Family & Privacy** destination to the learner dashboard, with accessible **Family & Guardian** and **Privacy & Security** tabs; the settings are not added to the instructor dashboard.
- Existing student account linking requires a secure email invitation, student sign-in/acceptance, and a DOB entry.
- Parent accounts can link multiple students and create multiple child accounts.
- Child and Transition students cannot directly revoke an active parent link; they can request administrator review. Adult students at/above the configured threshold can end their own link. Parents can unlink children. Progress and booking sharing remain governed by age-band permissions.

### Public instructor profile and course discovery

- Replaces Tutor LMS's public instructor profile template only when Tutor LMS makes that instructor profile public.
- Replaces Tutor LMS course archive and course-category archive presentation with the suite's course catalog and richer cards.
- Cards can show title, price, format/type, instructor, verification badge, category, rating/review count, enrollment count and duration where Tutor LMS provides the data.
- Single-course detail pages remain native Tutor LMS pages.
- Existing search, sponsorship, profile metadata and verification modules are reused. Search completeness depends on the site's taxonomy/meta values; no bulk taxonomy migration is silently performed.

### Private tutoring and direct-to-tutor payments

- The monthly calendar is a slot-selection/request interface; a displayed time is not a reserved slot.
- A learner or authorized parent submits a request. The tutor proposes/accepts the final schedule; tutor approval remains authoritative.
- For a paid proposal, the payer sees the tutor's active offline payment instructions and pays the tutor directly.
- The payer can declare payment and provide an optional reference. This declaration is not proof UStudy verified payment.
- The tutor independently checks their own payment records and confirms receipt. UStudy does not collect, hold, escrow, refund or transmit these funds.
- Public tutor reviews for private sessions are available only after the session is marked completed. Restricted students cannot submit them; a parent who booked may review from the parent's own account and is labelled `(Parent/guardian)`.

## Staging acceptance checklist

1. Confirm the encryption key is configured and legacy DOB migration is completed in batches.
2. Test Child, Transition, Adult and Unknown accounts using test data; verify Unknown always fails closed.
3. Test the 18th and 19th birthday transition; ensure delegated parent permissions are revoked and cannot silently return.
4. Test existing-account parent invitations: valid/expired token, student acceptance, student rejection, admin approve/reject and missing/orphan guardian record.
5. Test parent-created child accounts, two or more children per parent, and cross-parent access denial.
6. Verify Child/Transition parent access stays denied until admin review; verify Transition also needs explicit student sharing/booking consent.
7. Verify Adult parent sharing requires fresh, revocable student consent. Verify only Adult students can self-unlink; Child/Transition students can submit a safety concern instead.
8. Attempt public reviews, WP comments, Tutor Q&A/lesson comments, BuddyPress messages/activity and direct REST/AJAX calls as restricted students; all must be denied.
9. Verify free learning, quizzes and assignments still work for restricted students.
10. Verify restricted-student course/WooCommerce checkout is blocked; verify adult/parent checkout separately and remember parent checkout does not enroll a child.
11. Verify parent private booking only with verified relationship and booking permission; test the direct AJAX endpoint as well as the UI.
12. Verify private-session reviews are available only after `completed`; a parent-booked review stores the parent account as the author, displays `(Parent/guardian)`, and writes an audit entry. Verify a child cannot submit the same review directly.
13. Test tutor profile privacy, course archive/search filters and single-course native pages.
14. Test Arabic RTL and French/English LTR, mobile screens, keyboard navigation and screen-reader labels.
15. Review logs, REST responses, caches, exports and backups for accidental personal-data leakage.

## Verification performed for this package

- PHP syntax validation: all bundled PHP files.
- JavaScript syntax validation: all bundled JavaScript files.
- Targeted age-policy checks: age 17/18/19 classification, encrypted DOB round-trip, tampered ciphertext fails closed, Unknown students restricted, and guardian permissions require the verification marker.
- Tutor Course Format PHP regression suite: 162 passed, 0 failed (from the supplied test run).
- Optional Course Format browser suite not run because `jsdom` was unavailable in the build environment.
- Tutor LMS Core 4.2.1 extension points and student marker were inspected in the supplied source archive.
- ZIP integrity check performed after packaging.

No live WordPress/Tutor LMS staging site was available. The acceptance checklist remains required before production. This release does not certify legal compliance.


## Child course enrolment policy (1.3.22)

The suite uses two separate paths and leaves Tutor LMS as the source of truth for course enrolments:

- **Free courses:** a restricted child account may enrol itself using Tutor LMS's normal free-course enrolment UI only after the verified, administrator-approved guardian enables **Allow this child to enrol themselves in free courses** in **Tutor Dashboard → Family & Privacy → Family & Guardian**. This permission is default-off and is separate from progress sharing and private-session booking.
- **Paid courses:** the child cannot purchase or enrol itself. The parent/guardian signs in to their own adult account, opens the paid course, selects an approved linked child in the small recipient selector, and uses Tutor LMS Pro's native **Gift this Course** form and checkout. The plugin does not create a duplicate gift form, checkout, order, or custom claim workflow.
- Tutor LMS Pro handles paid gift checkout, scheduling, delivery, the child's **Reveal Gift** action and final enrolment. The suite only pre-fills the native form with a selected linked child's account details and verifies a restricted learner's gift claim against the live Pro AJAX request, scheduler record, recipient email, course, approved guardian/purchaser and completed order.
- The free-course permission is revoked when the guardian link is ended, an administrator revokes the relationship, age becomes unknown or crosses the configured age boundary, or guardian verification is no longer valid. Re-enabling requires the guardian permission form after the relationship is approved again.
- The test uses Tutor LMS's canonical course price type (`_tutor_course_price_type` / `tutor_utils()->price_type()`), not the discounted cart total. A paid course remains restricted even if a discount or coupon makes the checkout total zero.
- Free self-enrolments and successful native paid-gift claims are audit logged and carry attribution metadata on the Tutor LMS enrolment record.

Tutor LMS Core and Tutor LMS Pro must both remain active. This integration was source-reviewed against the supplied Core 4.2.1 and Pro 4.2.0 archives. Live checkout and gift-claim tests are still required on the site's staging environment.

## Child feedback and moderation (1.3.31)

- Restricted children with an approved guardian relationship can submit eligible course reviews, course/lesson comments, lesson replies, Tutor Q&A and reviews for their own completed private tutoring sessions.
- Child feedback keeps the child as the database author but displays publicly as **Learner** without a child email address.
- Every restricted-child feedback item first enters the linked guardian's **Family & Privacy → Family & Guardian → Children’s Feedback** queue. The guardian can approve for administrator review, reject, or request edits with a note.
- Parent approval is not publication. The item remains pending until an administrator approves it through the relevant Tutor/WordPress moderation screen. Private-session tutor reviews are also listed under **Tutor → Private Tutor Reviews**.
- The child can see feedback status in **Family & Privacy → Family & Guardian → My feedback status**.
- A parent can only moderate feedback from the currently approved linked child. Unlinking, revoked verification or consent invalidates the moderation relationship.
- Tutor LMS 4.2.1 Q&A queries use direct SQL and do not respect `comment_approved`; the suite therefore scopes those queries so pending child questions/replies are not exposed to other course users before approval.
- Administrator moderation is enabled by default for all native Tutor LMS course reviews and all WordPress comments on course/lesson posts, including adult and parent-authored feedback. Site owners can explicitly override the default with the `tls_feedback_admin_moderation_enabled` filter.
- Restricted-child feedback requires guardian approval first, then administrator approval. Adult learner and parent-authored private-session reviews go directly to administrator moderation; they are not routed through the child guardian queue.
- Legacy `admin_pending` or already-published child feedback is not assumed to have passed guardian review. The parent dashboard restores it to the guardian queue unless matching guardian, child, reviewer and approval-timestamp metadata prove that the parent already approved it. Tutor LMS and WordPress admin approval paths enforce the same rule.
- Existing child review edits/resubmissions return to guardian approval. No parent-child relinking or manual feedback metadata migration is required.
- Translated course progress percentages and private-session durations are assembled without `sprintf()` percent placeholders to avoid malformed translated format strings.
