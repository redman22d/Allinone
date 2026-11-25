=== Tutor LMS Course Format ===
Contributors: yourname
Tags: tutor lms, course, lms, course type, live class
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds Course Type (Prerecorded / Direct / Hybrid), delivery format, sessions number and session length to Tutor LMS courses, plus an instructor dashboard page for manually enrolling students.

== Description ==

Tutor LMS Course Format adds four fields to the Tutor LMS course editor:

* **Course Type** - Prerecorded (default), Direct, or Hybrid (Prerecorded + Direct)
* **Delivery Format** - Group or 1v1 (shown only for Direct / Hybrid)
* **Sessions Number** - whole number (shown only for Direct / Hybrid)
* **Session Length (hours)** - decimals allowed, e.g. 1.5 = 1h 30m (shown only for Direct / Hybrid)

The fields appear in the Tutor LMS course builder (Basics section) and in the classic
wp-admin course editor sidebar. Values are shown on the course page in both the
Learning Area layout and the legacy single course layout.

It also adds an **Enroll Students** page to the frontend instructor dashboard, where an
instructor can search existing WordPress users by username or email and enroll or remove
them from their own published courses without the student going through checkout. An
admin-only switch at **Settings > Course Format** lets you allow or block that feature
site-wide at any time.

Built and tested against Tutor LMS 4.0.7.

== Installation ==

1. Upload the `tutor-course-format` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Edit a course: Tutor LMS > Courses > edit, or the frontend dashboard course builder.
4. The fields appear in the Basics section, under the Options panel.

== Frequently Asked Questions ==

= Why are the fields under "Options" and not in the right-hand sidebar? =

The Tutor LMS 4.x course builder only exposes two extension slots for the Basics
section: `after_description` and `after_settings`. Basics has no sidebar slot.
`after_settings` renders directly beneath the Options panel, which is the closest
available position. Change it with the `tcf_builder_slot` filter.

= Where is the data stored? =

Post meta on the course: `_tcf_course_type`, `_tcf_course_format`,
`_tcf_session_count`, `_tcf_session_length`.

= What happens when I switch a course back to Prerecorded? =

The three live-session meta values are deleted, so no stale data is displayed
or returned by the REST API.

== Frequently Asked Questions ==

= How do I turn off manual enrollment for instructors? =

Go to Settings > Course Format and uncheck "Allow instructors to manually enroll
students". This immediately hides the "Enroll Students" link from the instructor
dashboard menu and blocks the search/enroll/remove AJAX actions for everyone; no
plugin deactivation or code change is needed. Re-check it to turn the feature back
on.

== Changelog ==

= 1.4.0 =
* New: Settings > Course Format admin page with a single switch, "Allow instructors
  to manually enroll students" (checked by default, so existing sites keep working
  unchanged).
* When unchecked, the "Enroll Students" dashboard menu item and page, and the
  underlying `tcf_search_students` / `tcf_enroll_student` / `tcf_remove_student`
  AJAX actions, are all hidden/blocked for every user.
* New filter `tcf_manual_enrollment_allowed` for programmatic control.

= 1.3.0 =
* New: "Enroll Students" page on the frontend instructor dashboard. Instructors
  can search WordPress users by username/email and enroll or remove them from
  their own published courses, bypassing checkout.
* New AJAX endpoints `tcf_search_students`, `tcf_enroll_student`,
  `tcf_remove_student`, each nonce-protected and scoped to courses the current
  user (or an administrator) is allowed to manage.

= 1.2.0 =
* Course page: the Course Type row now names the type itself - "Prerecorded
  lessons", "Direct lessons", or "Direct lessons and prerecorded" for hybrid.
* Course page: the study format moved to its own row labelled "Study Type", so
  Direct and Hybrid courses show three rows instead of two.
* Course page: the session count now reads "10 live sessions for 1.5 hours per
  session".
* New filters `tcf_display_type_labels` and `tcf_display_type_label` for the
  Course Type wording.

= 1.1.0 =
* Course builder: the fields now use the `after_description` Basics slot, so they
  render as one block directly below the Course Description editor and above the
  Options fields instead of underneath them.
* Course page: the display was reworked. A prerecorded course now shows a single
  "Prerecorded" row. A Direct or Hybrid course shows two rows - "Course Type:
  Group study / 1v1 study" and "Number: 10 sessions for 1.5 hours per session".
* Format option labels are now "Group study" and "1v1 study" (values unchanged).
* Fixed three of the four legacy icon classes, which did not exist in Tutor's
  icon font and so rendered nothing: the sidebar now uses
  `tutor-icon-video-camera`, `tutor-icon-user-group`, `tutor-icon-user-line` and
  `tutor-icon-calender`, all of which are real.

= 1.0.1 =
* Fix: custom field values were silently discarded when an instructor saved
  their own *published* course. The save gate relied on
  `current_user_can( 'edit_post', $id )`, which - because the `courses` post
  type uses `map_meta_cap` - additionally requires
  `edit_published_tutor_courses`, a capability Tutor never grants the
  `tutor_instructor` role. Tutor's own course builder authorises that request
  with `tutor_utils()->can_user_edit_course()`, so the save is now accepted if
  either check passes. Admins, editors and draft/pending courses were never
  affected.

= 1.0.0 =
* Initial release.


Admin controls (Settings > Course Format):
- Hide the Tutor course-builder Add Category control for instructors while keeping existing categories selectable. The restriction is also enforced server-side.
- Set one global Assignment & Quiz maximum submission file size in MB. The per-assessment instructor size field is locked to this value, and existing saved limits are capped when the setting changes.


Version 1.6.0
- Adds separate instructor image upload limits for course featured images, instructor profile images, and other/normal images.
- Oversized instructor image uploads are rejected before they are stored.
