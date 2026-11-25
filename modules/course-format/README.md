# Tutor LMS Course Format

Adds four custom fields to Tutor LMS courses:

| Field | Control | Values |
| --- | --- | --- |
| **Course Type** | radio | `prerecorded` (default), `direct`, `hybrid` |
| **Delivery Format** | dropdown | `group` = *Group study*, `one_to_one` = *1v1 study* — only shown for Direct / Hybrid |
| **Sessions Number** | number | whole number — only shown for Direct / Hybrid |
| **Session Length (hours)** | number | decimals allowed, `1.5` = 1h 30m — only shown for Direct / Hybrid |

Built and verified against **Tutor LMS 4.0.7** (free).

---

## Where the fields appear

1. **Tutor LMS Course Builder — Basics section.** The frontend dashboard builder
   and wp-admin → *Create Course* render the same React app, so one integration
   covers both.
2. **Classic wp-admin course editor** (`post.php`) — a "Course Format" meta box in
   the sidebar, writing to the same post meta.
3. **Course page** — rows added to the course info table (Learning Area layout)
   and to the enrolment box list (legacy layout).

### What the course page shows

**Prerecorded** courses get a single row:

| Icon | Label | Value |
| --- | --- | --- |
| video camera | Course Type | Prerecorded lessons |

**Direct** courses get three rows:

| Icon | Label | Value |
| --- | --- | --- |
| meeting | Course Type | Direct lessons |
| group / single person | Study Type | `Group study` or `1v1 study` |
| calendar | Number | `10 live sessions for 1.5 hours per session` |

**Hybrid** courses are the same, except the first row reads
`Direct lessons and prerecorded`.

The *Number* row drops whichever half is empty (`5 live sessions`, or
`1.5 hours per session` on its own) and disappears entirely if both are blank.

Relevant filters: `tcf_display_rows` (whole row set),
`tcf_display_type_labels` / `tcf_display_type_label` (the Course Type wording),
`tcf_learning_area_icon` and `tcf_legacy_icon_class` (the icons).

### About the Basics "sidebar"

The Tutor LMS 4.x builder exposes exactly **two** slots for the Basics section:
`after_description` and `after_settings`. There is no sidebar slot for Basics —
the right-hand column of that tab is the step navigator, not a field area.
This plugin uses **`after_description`**: all four fields render as one block in
the main column, immediately below the *Course Description* editor and above the
Options fields (Pricing Model, Categories, Tags, Author), which live in the
separate right-hand column.

Prefer them underneath the Options panel instead?

```php
add_filter( 'tcf_builder_slot', fn() => 'after_settings' );
```

---

## Installation

1. Copy the `tutor-course-format` folder into `wp-content/plugins/`.
2. Activate it (Tutor LMS must be active).
3. Edit any course.

---

## Storage

Post meta on the course:

| Key | Type | Notes |
| --- | --- | --- |
| `_tcf_course_type` | string | `prerecorded` \| `direct` \| `hybrid` |
| `_tcf_course_format` | string | `group` \| `one_to_one`, deleted when prerecorded |
| `_tcf_session_count` | int | deleted when prerecorded |
| `_tcf_session_length` | float | hours, deleted when prerecorded |

Switching a course back to **Prerecorded deletes** the three dependent meta
values, so stale data never reaches the front end or the REST response.

---

## Template helpers

```php
tcf_get_course_type( $course_id );          // 'hybrid'
tcf_get_course_format( $course_id );        // 'group' | 'one_to_one' | ''
tcf_get_session_count( $course_id );        // 12
tcf_get_session_length( $course_id );       // 1.5
tcf_course_has_live_sessions( $course_id ); // bool
tcf_get_course_format_data( $course_id );   // full array, including labels
tcf_format_hours( 1.5 );                    // '1.5'
```

## REST API

`GET /wp-json/wp/v2/courses/{id}` includes:

```json
"course_format": {
  "type": "hybrid",
  "type_label": "Hybrid (Prerecorded + Direct)",
  "has_live": true,
  "format": "group",
  "format_label": "Group study",
  "sessions": 12,
  "session_hours": 1.5
}
```

---

## Filters and actions

| Hook | Type | Purpose |
| --- | --- | --- |
| `tcf_course_types` | filter | Add / rename course types |
| `tcf_course_formats` | filter | Add / rename delivery formats |
| `tcf_live_types` | filter | Which types show the live fields (default `direct`, `hybrid`) |
| `tcf_builder_slot` | filter | Basics slot to inject into (default `after_description`) |
| `tcf_builder_fields` | filter | Full field definitions sent to the builder |
| `tcf_require_live_fields` | filter | Make the three live fields required when visible (default `false`) |
| `tcf_display_on_course_page` | filter | Turn the course page rows off |
| `tcf_display_rows` | filter | Change the rendered rows |
| `tcf_learning_area_icon` / `tcf_legacy_icon_class` | filter | Row icons |
| `tcf_format_hours` | filter | How the length is rendered |
| `tcf_max_session_count` (999) / `tcf_max_session_length` (24) | filter | Input ceilings |
| `tcf_should_save` | filter | Veto a save |
| `tcf_course_format_saved` | action | Fires after meta is written |

Example — add a fourth course type:

```php
add_filter( 'tcf_course_types', function ( $types ) {
    $types['cohort'] = __( 'Cohort based', 'my-theme' );
    return $types;
} );

add_filter( 'tcf_live_types', fn( $t ) => array_merge( $t, array( 'cohort' ) ) );
```

---

## How the integration works

Verified against the shipped Tutor LMS 4.0.7 bundle:

* **Registering fields.** `window.Tutor.CourseBuilder.Basic.registerField( slot, field )`
  is the public slot API. Tutor assigns `window.Tutor` inside a React `useEffect`,
  so it does **not** exist when an enqueued script first runs —
  `assets/js/tcf-course-builder.js` polls for it.
* **Loading values.** The builder builds form defaults from the course details
  response, reading one key per registered field name. The plugin adds those keys
  through the `tutor_course_details_response` filter.
* **Saving.** Registered field names are POSTed flat with the normal course
  payload. `ajax_update_course()` / `ajax_create_course()` call
  `wp_update_post()` / `wp_insert_post()`, so persistence goes through the
  standard `save_post_courses` hook.
* **Conditional display.** Every field renders inside
  `[data-cy="form-field-wrapper"]` with `name="<field name>"` on the control, so
  the dependent fields are located and hidden while the type is Prerecorded. If
  the value cannot be read, nothing is hidden — the fallback is always to show.
  Hidden values are ignored server-side regardless.
* **Front end.** Learning Area rows via `tutor_learning_area_course_info_metadata`,
  legacy rows via `tutor/course/single/sidebar/metadata`.

---

## Tests

Two suites that run the real code, not re-implementations of it.

```bash
bash tests/run-all.sh       # lint + both suites
php tests/run-tests.php     # PHP: 123 assertions
node tests/js-tests.js      # JS:  43 assertions (needs jsdom)
```

* `tests/run-tests.php` loads the actual plugin files against a small WordPress +
  Tutor shim and exercises the save handler, sanitizers, the details-response
  filter, the JS config payload, both front-end filters and the meta box render.
* `tests/generate-fixtures.php` writes the builder config and meta box markup
  produced by the real PHP classes.
* `tests/js-tests.js` runs the real browser scripts in jsdom against those
  fixtures, covering registration, the `window.Tutor` wait, and conditional
  visibility for both radio and select course-type controls.

---

## Troubleshooting

* **Fields don't show in the builder** — confirm Tutor LMS is active and you are
  on the course *builder* (frontend dashboard → Create Course, or wp-admin →
  Create Course), not a page builder preview.
* **Values don't reload** — make sure no other plugin overrides
  `tutor_course_details_response` and drops unknown keys.
* **Rows missing on the course page** — the theme may override
  `templates/learning-area/subpages/course-info.php` or
  `templates/single/course/course-entry-box.php`. Both filters are listed above.


Admin controls (Settings > Course Format):
- Hide the Tutor course-builder Add Category control for instructors while keeping existing categories selectable. The restriction is also enforced server-side.
- Set one global Assignment & Quiz maximum submission file size in MB. The per-assessment instructor size field is locked to this value, and existing saved limits are capped when the setting changes.


Version 1.6.0
- Adds separate instructor image upload limits for course featured images, instructor profile images, and other/normal images.
- Oversized instructor image uploads are rejected before they are stored.
