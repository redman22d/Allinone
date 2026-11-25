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
