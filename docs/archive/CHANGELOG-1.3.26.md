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
