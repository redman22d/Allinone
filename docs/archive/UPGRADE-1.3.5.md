# Upgrade to Tutor Learning Suite 1.3.5

## What this fixes

1. Instructor dashboard no longer runs identity-image validation during page rendering. The `tlms_ipe_verify=1&tlms_ipe_verification_error=1` state is now a recoverable form-error state rather than a dashboard redirect loop.
2. The TCSP filter bar renders on the Tutor-configured course archive page, including the suite custom archive template.
3. `[tsp_home_search]` is registered as a backward-compatible UStudy homepage search/filter widget.
4. TCSP JavaScript (filter dropdowns and autocomplete suggestions) loads on pages containing `[tcsp_filters]` or `[tsp_home_search]`.

## Installation

1. Back up the database and `wp-content`.
2. Keep the existing DOB encryption key and WordPress salts unchanged.
3. Upload `tutor-learning-suite-1.3.5.zip` as an update to the existing Tutor Learning Suite plugin.
4. Clear WordPress/page/CDN caches. The TCSP asset version has changed so browsers should fetch the updated script and stylesheet references.
5. In Tutor LMS settings, confirm the selected Course Archive page is the page used by the Courses menu.
6. Edit the homepage and verify it contains `[tsp_home_search]` exactly (square brackets included), or use `[tcsp_filters]` on an archive page.

## Verification checklist

### Instructor dashboard
- Sign in as an instructor who is not yet verified.
- Open Tutor Dashboard → Home directly and refresh several times.
- Confirm no redirect loop or unexpected `tlms_ipe_verification_error` appears.
- Open Apply for verification; confirm the form appears.
- Submit a deliberately incomplete form and confirm a visible error appears while the dashboard remains accessible.
- Submit valid JPG/PNG/WebP front and back images within the 5 MB limit on a staging account and verify the normal pending state.

### Courses page
- Open the exact page selected as Tutor LMS Course Archive.
- Confirm search, sort, enabled filter groups and Apply/Reset controls appear above the course results.
- Test one category, one price and one wilaya filter; combine two filters; verify result count/cards and pagination.
- Confirm autocomplete works and its suggestions are not broken by active filter state.

### Homepage shortcode
- Add `[tsp_home_search]` to a normal WordPress page.
- Confirm it renders the search and filter form without an embedded duplicate course list.
- Submit a query and confirm it navigates to the configured Tutor Course Archive and preserves the search/filter values.

## Notes

This release does not create or migrate data. If the Courses page still uses a theme-specific custom template that does not use Tutor's configured Course Archive page, make that page the Tutor archive in Tutor settings or place `[tcsp_filters]` in the template/page content where the filter bar should render.
