# Tutor Learning Suite 1.3.19

- Fixed the missing guardian course-enrolment panel on Tutor LMS single-course pages by rendering it through Tutor LMS purchase and single-course template hooks, with the WordPress content filter retained as a fallback.
- Removed silent early exits when a verified adult has no linked child or when linked learners have not completed the required approval/consent steps. The course page now explains what is missing and links to Family & Privacy, including a locked notice for age-unknown logged-in accounts.
- Linked children who have not yet enabled parent course-enrolment consent are reported clearly; only learners whose relationship and enrolment permission are valid can be selected. Already-enrolled children are reported rather than offered twice.
- Renamed the parent action to **Enrol my child** for both free-course assignment and paid Tutor LMS gifting.
- Kept server-side permission checks, nonces, child-specific gift authorization, and audit logging unchanged.
