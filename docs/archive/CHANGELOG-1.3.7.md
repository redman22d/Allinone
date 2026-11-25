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
