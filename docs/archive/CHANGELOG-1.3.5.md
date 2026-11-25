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
