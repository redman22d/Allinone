# Tutor LMS Suite

One plugin combining five previously-separate Tutor LMS add-ons:

| Module | Was standalone version | Folder |
|---|---|---|
| Course Format | Tutor LMS Course Format 1.2.0 | `modules/course-format` |
| Course Search Pro | Tutor Course Search Pro 3.8.3 | `modules/course-search-pro` |
| Instructor Offline Payment | Tutor LMS Instructor Offline Payment 1.6.1 | `modules/instructor-offline-payment` |
| Instructor Profile Extra | Tutor LMS Instructor Profile Extra 1.6.5 | `modules/instructor-profile-extra` |
| Private Sessions | Tutor LMS Private Sessions 1.5.32 | `modules/private-sessions` |

**Install:** deactivate and delete the five old plugins, then install and
activate this one instead. Data for each module (options, user meta, custom
DB tables) uses the same names/schema as before, so nothing is lost when you
swap them out — just don't run the old and new plugins side by side, or
you'll get duplicate hook registrations.

## Why merge

- One plugin to update instead of five.
- One activation/deactivation/uninstall flow instead of five.
- One dependency check on Tutor LMS instead of several slightly different ones.

## What changed in the merge (and what didn't)

**Unchanged:** every module's business logic, database schema, hooks,
shortcodes, AJAX actions, cron jobs, settings pages, and text domains are
byte-for-byte the same as their standalone versions. Each module still uses
its own prefix (`TCF_`, `TCSP_`, `TutorInstructorOfflinePayment\`,
`Tlms_Ipe_`, `TLMS_PS_` / `TLMS_Private_Sessions`), so there's no risk of
collisions between them, and each module boots at exactly the same point in
the WordPress load order it used to (some at plain plugin-load time, some on
`plugins_loaded` default priority, some on `plugins_loaded@20`) so behaviour
does not change.

**Consolidated:**
- Five `Plugin Name` headers → one, in `tutor-lms-suite.php`.
- Five activation hooks → one `tls_suite_activate()` that calls each
  module's own activation step, then flushes rewrite rules once.
- Two deactivation hooks (Offline Payment, Private Sessions had them;
  Course Format/Search Pro/Profile Extra didn't) → one
  `tls_suite_deactivate()`.
- Three near-identical "Tutor LMS must be active" admin notices (Course
  Format, Instructor Profile Extra, Private Sessions each had their own
  inline HTML) → one shared `tls_suite_tutor_missing_notice()` function.
  Instructor Offline Payment's more detailed version/config check
  (`Requirements` class) is untouched, since it checks more than bare
  activation.
- One `uninstall.php` (carried over from Instructor Offline Payment, the
  only module that had one — see the file for what it does and doesn't
  clean up).

**Removed (dead weight, not used at runtime):**
- Course Format's `tests/` directory (~96 KB) — a standalone dev test
  runner (`run-tests.php` + fixtures), never loaded by the plugin itself.
- An empty, unused `private-sessions.js.tmp` leftover file.
- Five separate `readme.txt`/`README.md` files → this one file.

## v1.0.1 follow-up changes

- **New: "Suite Overview" admin page** (Tutor LMS → Suite Overview). One
  screen listing all five modules, whether each currently has what it
  needs from Tutor LMS, and a link to that module's own settings page(s).
  This is a directory in front of the existing pages, not a replacement —
  each module's settings form, nonces, and save logic are untouched.
- **Extended `uninstall.php`** to also clean up Course Format, Course
  Search Pro, Instructor Profile Extra, and Private Sessions (previously
  only Instructor Offline Payment cleaned up after itself). Same
  conservative rule throughout: delete plugin-owned settings/config, never
  delete anything that represents something that really happened (an
  order, a sponsorship an instructor paid for, a booked session, course
  content). See `uninstall.php` for exactly what is and isn't removed per
  module.
- **Course Search Pro's own "Tutor LMS required" notice** now reuses the
  same shared `tls_suite_tutor_missing_notice()` function as the other
  three, for a consistent look (it already had its own correct guard
  logic — this only deduplicated the HTML).

### Corrections to earlier review notes

A closer read of the full codebase turned up two things worth setting
straight from an earlier pass:

- **Course Search Pro already has a Tutor-LMS-active guard.** It's
  structured differently from the other modules (settings/sponsorship
  register unconditionally; only the search/filter/suggest features and
  card hooks are gated), so it was missed on a first skim of just the
  bootstrap file. No bug here.
- **Cross-module integration already exists.** Course Search Pro already
  reads Instructor Profile Extra data directly (spoken languages, wilaya/
  commune location, tutoring type, verification status) to power its
  filter UI, via `class_exists( 'Tlms_Ipe_Plugin' )` checks — so a
  "language filter fed by instructor profiles" was already built, not a
  gap. Private Sessions already reads Profile Extra's teachable
  categories, and uses Tutor LMS's own native Cart/Order/Course models
  directly rather than a separate payment system — so it doesn't actually
  duplicate Instructor Offline Payment's checkout logic; they're solving
  different problems (native checkout vs. an offline alternative to it).
  A "shared checkout base" for the two was not implemented, since on
  inspection there wasn't real duplication to remove.
- **The suspected paren mismatch in `class-tcsp-query.php`** was a false
  positive from a naive brace/paren counter that didn't account for PHP
  comments — a re-check with a comment- and string-aware parser shows the
  file balances correctly. No fix was needed.

## Module notes

- **Course Format** adds course type/format/session fields to the course
  editor and course page.
- **Course Search Pro** adds full-text search, filters, and a sponsored
  carousel to the course archive.
- **Instructor Offline Payment** replaces native checkout with per-instructor
  offline payment collection and approval.
- **Instructor Profile Extra** adds social links, location, education, and
  other fields to instructor profiles.
- **Private Sessions** adds 1-to-1 tutoring request/booking/checkout.

## Requirements

- WordPress 6.0+, PHP 7.4+
- Tutor LMS active (Instructor Offline Payment additionally requires Tutor
  LMS 3.0.0+ with native monetisation configured).
