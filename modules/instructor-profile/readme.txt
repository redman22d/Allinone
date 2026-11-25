- 1.7.4: Restores visible replacement social SVGs and limits the verified sticker to public profile headings, excluding Tutor account/avatar menus.

- 1.7.1: Security/performance fixes. ID front/back images are now uploaded to a locked-down uploads subfolder (blocked via .htaccess, not the normal public uploads path) and are only viewable through a new capability-gated, nonce-checked admin endpoint instead of a public attachment URL. The new-application notification email no longer includes the ID number, phone, or address in plaintext. Once a decision is made, the stored ID number is redacted to its last 4 characters (duplicate detection still works via the separate salted hash). Fixed a bug where a legitimate reapplication with the same real ID number was incorrectly blocked as a duplicate of the applicant's own earlier submission. The verified-instructor badge script no longer runs a site-wide database query on every front-end page load — it only runs on the actual Tutor profile page being viewed. The teachable-categories tree now loads with one query instead of one query per tree node, and the verification admin list is now paginated instead of always loading up to 200 applications at once.
- 1.7.0: Added verification anti-fraud protection: pending/verified applications are blocked from duplicate submission; configurable maximum lifetime attempts (default 3); configurable rejection cooldown (default 30 days); hashed identity-number duplicate detection; 60-second rapid-resubmission rate limiting; per-account verification history with rejection reasons; automatic lock after the maximum attempts with an admin Unlock verification control. The ID number itself is not used as the duplicate index; a salted HMAC hash is stored for matching. Existing verification image deletion behavior remains unchanged.
- 1.6.9: Instructor verification now requires Legal full name, Email, Phone, ID type, ID number, Address, ID front image, and ID back image. Additional information remains optional. New front/back ID images are stored as separate private attachments, shown separately to admins while pending, and permanently deleted after a verification decision. The instructor form displays a privacy notice explaining the deletion. The admin verification page now shows all submitted application data, including address and additional information, while retaining compatibility with older single-image/legacy Wilaya submissions.
=== Tutor LMS Instructor Profile Extra ===

Version 1.7.4

Extends Tutor LMS instructor profiles with:
- Instagram, Telegram, WhatsApp, TikTok and YouTube links
- Private tutoring formats
- Algerian wilaya and municipality
- Repeatable education qualifications (qualification, institution/place, date)
- Repeatable work experience (job/role, place, years)
- Multiple Tutor LMS course categories and sub-categories the tutor can teach
- Multiple spoken languages from a curated 50-language global list, with Arabic consolidated rather than regional dialect labels
- A structured public instructor profile section displayed directly after the Tutor LMS bio

Frontend location:
Tutor Dashboard > Settings > Profile (fields are injected after the Bio section).
Social accounts: Dashboard > Settings > Social (learners and instructors).

Public profile:
The extra profile block is rendered after the Tutor LMS profile.bio template and only shows sections that contain saved data.

Admin:
WordPress user profile screens also expose the extra instructor fields. Education and experience are stored as JSON in textareas for admin editing.


= 1.6.0 =
* Fixed fatal error "Illegal offset type in isset or empty" on the dashboard profile and public instructor profile when a wilaya/municipality was actually selected. Wilaya and Municipality are stored as arrays (multi-select), but the location-label lookups were still being called with the whole array instead of one code at a time, which PHP 8 rejects as an array-as-key access. Added an id-based commune lookup and a small formatter that turns the saved wilaya/commune arrays into one readable "Wilaya A, Wilaya B — Commune X, Commune Y" string, used consistently on both the dashboard and the public profile.
* Fixed the WordPress admin "Edit User" screen, which read the wilaya/commune meta as a plain string and rendered single-select dropdowns. Since the value is actually an array, this both displayed nothing useful and — if an admin saved the profile from that screen — silently overwrote an instructor's multiple wilaya/municipality selections with a single value. The admin screen now uses proper multi-selects that read and write the same array format as the dashboard.

= 1.5.8 =
* Wilaya and Municipality are now a multi-select "check dropdown" (search box + checkbox list, same widget used for teachable categories and spoken languages) instead of a native multi-select list box. Municipality options automatically filter to the selected wilaya(s), and choices already saved on the account are preserved.
* Fixed incorrect social icons on the public instructor profile: Instagram, Telegram, WhatsApp, TikTok and YouTube links were showing Tutor's fallback globe/phone glyph on top of the correct brand icon because the fallback SVG was only hidden inside the dashboard settings form, not on the public profile. The fallback glyph is now hidden everywhere these icons are rendered so only the correct brand icon shows.

= 1.3.7 =
* Public Tutor/learner profile social links are de-duplicated when Tutor renders responsive/duplicate social containers.
* Extra social icons are normalized to one SVG style and size without stacking fallback icons.
* LinkedIn suggested URL now uses `https://linkedin.com/in/username`.
* Instructor public extra information is grouped into one collapsible drawer.

= 1.3.3 =
* Social icons on the settings form now sit on the same line as their URL input for every network (Facebook through TikTok), for both learners and instructors.
* Public profile icons use one size, one color (inherited), and one baseline — leftover globe/phone fallbacks are gone.
* “What I can teach” no longer prints a parent category twice. A parent is listed alone only when none of its children are selected.

= 1.3.2 =
* Align custom social icons with Tutor LMS native icons.

= 1.3.1 =
* Consistent social-icon alignment and grouped teachable categories.

= 1.3.0 =
* Location selectors, hierarchy, extra social links.

= 1.2.0 =
* Tutor-only profile fields are guarded server-side.
* Wilaya and Municipality now use persistent native selects backed by the saved meta values, including correct Municipality restoration after reload.
* Education and Experience use compact repeatable rows.
* What I can teach uses a searchable checkbox dropdown with hierarchical Tutor LMS course categories/sub-categories.
* Spoken languages uses a searchable checkbox dropdown with 50 global languages; Arabic is consolidated and Cantonese is separate from Mandarin.
* Public tutor profile presentation is more compact and information-dense.


1.3.7
- Removed public-profile social visibility/deduplication JavaScript that could hide all social links.
- Left Tutor LMS public social rendering untouched; custom social icons are styled without DOM removal.

* v1.3.8: Restored Tutor LMS responsive social behavior by showing only the desktop social block (`profile-rating-media content-for-desktop`) on desktop and only the mobile social block (`tutor-social-container content-for-mobile`) on small screens.
* v1.3.9: Uses Tutor LMS's `content-for-desktop` / `content-for-mobile` classes correctly: only the alternate social block is hidden at each breakpoint, while the active block keeps Tutor/theme layout rules.


1.4.5: Front-loads the instructor verification CTA, submits through front-end AJAX, and requires a private ID image upload. Adds instructor verification applications, admin review/approve/reject, notification recipient settings, verified blue avatar mark, and desktop social-container right alignment.


1.4.8: Fixes revoke processing, repairs the verification badge JavaScript, broadens verified Tutor avatar detection, and routes admin verification actions through admin-post.

1.4.9: Keeps verified tutor photos fully visible, limits avatar detection to real instructor/avatar contexts, and adds a blue “Verified” sticker to verified instructors’ course cards and public Tutor LMS profiles.

1.5.5: Removes the verified avatar check mark and places the Verified Instructor label inside Tutor LMS public profile .pp-area, beside profile stats or immediately after the instructor name.

1.5.6: Fixes public profile verification resolution using the actual Tutor profile query var and URL slug fallback, inserts the badge inside .pp-area, and removes the check symbol from the text sticker.
1.5.7: Removes the "Verified Instructor" sticker from course cards everywhere they appear (course archive, search results, loops/lists). The badge still appears beside the instructor's name on their public Tutor LMS profile.


Version 1.6.0 changes:
- Replaced the extra Instagram, Telegram and WhatsApp icons with accurate brand glyphs and improved the TikTok glyph handling in Tutor LMS profile/settings.
- Public tutor locations now group selected municipalities under their corresponding wilaya (e.g. Oran : Bir El Djir, Es Senia ; Tlemcen : ...).
