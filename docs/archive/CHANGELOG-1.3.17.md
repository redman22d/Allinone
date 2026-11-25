# Tutor Learning Suite 1.3.17

- Added the Family & Privacy entry to the learner dashboard for tutor accounts as well as learner accounts, using a Tutor LMS user-group icon before the page name. It remains a learner-dashboard entry and is not added to the instructor navigation.
- Allowed tutor accounts to load the combined Family & Privacy page from the learner dashboard.
- Removed course taxonomy tags from course cards.
- Added a short course description with a **More** link to the full course page.
- Moved course categories to the lower card metadata area, below the description and course statistics.
- Replaced restricted-action error pages with a styled, accessible pop-up notice. Client-side action interception provides immediate feedback; server-side enrolment, payment, review, comment and community restrictions remain enforced. AJAX requests receive structured errors, and ordinary blocked POSTs return to the referring page with a one-time notice.
