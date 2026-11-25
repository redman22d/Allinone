## Version 4.2.7

Adds sponsorship email notifications and explicit cancellation for manual sponsorships.

## v4.2.3
- Native Tutor LMS CartModel integration for sponsorship checkout.
- Sponsorship checkout products are published for native cart visibility but excluded from public course queries.
- Sponsorship plans moved to the main Sponsorship admin screen with course-plan price fields.
- Duplicate sponsorship admin menus removed from Tutor LMS menu.

### 4.0.3

- **Fix: checking a category/tag (or any other checkbox filter) had no effect on the live counts shown for the OTHER checkboxes in that same group.** Each option's "what if" count was computed by *replacing* the group's whole selection with just that one candidate option, discarding anything else already checked. So after checking Category B, Category A's count still showed "courses in A alone" — B being checked was silently thrown away every time a different option in the same group was tested, making it look like counts weren't updating at all. Every checkbox-group count (category, tag, language, tutoring type, wilaya, municipality, course type, tutoring format) now tests "my current selection in this group, *plus* this candidate" instead, so checking one box is immediately reflected in the live counts for the rest of the group.

### 3.9.0

- **Live "what if" counts now cover every filter, not just category/tag.** Each option in the Price, Level, Rating, Minimum students, Verified instructor, Language, Tutoring type, Wilaya, Course type, and Tutoring format filters now shows how many courses would match if it were selected together with the rest of the currently active filters, updating on every filter submission — the same behavior category/tag checkboxes already had. Renamed the setting from "Live category/tag counts" to "Live filter counts" to match (same toggle, same GO Settings → Result Counts location). As a safety measure, any single filter group with more than 75 options (the Municipality filter can list 1,500+ Algerian communes) automatically falls back to no per-option count rather than running that many extra queries on one page load.

### 3.8.6

- **Live search now correctly respects the active archive filters, instead of either ignoring them or breaking.** 3.8.5 stopped the current page's filters (category, price, level, rating, etc.) from leaking into the autocomplete/live-search request, which fixed the "No matching courses" false negatives — but as a side effect, suggestions stopped being scoped to the filters at all, always searching the whole catalog regardless of what was selected. Investigating further turned up the real underlying issue: when the filter state *did* reach the suggestion query, the query's own promoted-course ranking and TCSP_Query's archive-filter ranking each built a `LEFT JOIN` against postmeta using the exact same table aliases (`tcsp_tier`, `tcsp_price`, etc.), which MySQL rejects outright as a duplicate alias — so the suggestion query failed silently and returned zero results any time a filter was active, regardless of whether the typed term actually matched anything. Live search now deliberately sends the active filter state with the request (so, e.g., filtering to a category also scopes autocomplete suggestions to that category), and the ranking logic no longer collides: when the archive-filter ranking is already shaping the query, the suggestion box's own duplicate tier/paid-ranking joins are skipped in favor of it.

### 3.8.5

- **Fix: live search (the autocomplete suggestion dropdown) reported "No matching courses" as soon as any archive filter was active.** The script that copies the visitor's active filter/search state onto Tutor's own AJAX pagination requests was also meant to skip the separate autocomplete request, but its check looked for `action=tcsp_suggest` inside the request's `url` — and at the point that check runs, jQuery has already moved a GET request's data into a query string, but hasn't merged it onto `url` yet, so `action=...` was never actually there and the check could never match. Every autocomplete keystroke was therefore silently getting the current page's filters (category, price, rating, etc.) appended to it, so suggestions were wrongly restricted to whatever was already filtered on the page instead of the whole catalog, and typing something outside that filtered subset showed "No results" even though matching courses existed. The exemption now also checks the request's `data`, where the action name actually is at that point, so autocomplete requests are correctly left alone and always search the full catalog.

Version 3.6.4 — multi-value locations, Course Type/format filters, and course-format card stickers using Tutor's thumbnail filter.

## 3.3.2

Adds instructor-profile filtering for verified status, spoken languages (ALL/ANY), private tutoring type (ALL/ANY), wilaya, and municipality; applies these filters to sponsored carousel eligibility; adds compact sponsored-card pricing/discounts with tutor/category links; adds Verified Instructor course-card stickers; and fixes individual sponsorship removal/save. The instructor profile extra plugin should be active so its `_tlms_ipe_*` user-meta data and Algeria location dataset are available.

## Version 3.2.0

### Version 3.0.0 – sponsorship fairness, plan explanations, and wishlist UX
- Sponsored carousel uses weighted random rotation: higher-plan courses have higher selection probability and higher first-position probability, but every eligible course retains a non-zero chance to be featured.
- Selection is without replacement for each carousel render, and random draws prevent a course from being permanently pinned to one position.
- Sponsorship Manager now shows editable plan names and customer-facing feature explanations.
- Sponsored-carousel wishlist toggles immediately through a secure AJAX request, with a filled/active bookmark state and no page refresh or visible “Saved” text.


Adds editable sponsorship plan names/descriptions, visible wishlist state, robust carousel drag/wheel/buttons, and responsive high-resolution thumbnails.

# Tutor Course Search Pro

Marketplace-style search, filtering, and sorting for the **Tutor LMS** course archive — plus course-level sponsorship tiers, visible sponsored stickers, and an enhanced search-suggestion autocomplete.

## Features

- **Advanced archive filters** — Price (Free/Paid), Difficulty level, Category (multi-select, AND/OR, parent includes descendants), Tag (multi-select, AND/OR), Minimum rating, and Minimum number of students. Category filters show parent/child nesting and WordPress term counts; tags show counts too. Each filter can be toggled on/off from settings.
- **Reset filters** — a "Reset filters" button appears whenever any search/filter is active, clearing everything and returning to page 1.
- **Rich sorting** — Relevance, Newest/Oldest, Title A–Z / Z–A, Price low→high / high→low, Top rated, and Most students.
- **Course sponsorship tiers** — Assign Platinum, Gold, or Featured sponsorship to individual courses. Configure each tier's label, color, and ranking weight from a visual admin panel. Paid courses can receive a secondary boost.
- **Sponsored course stickers** — Sponsored courses display a tier-colored `Sponsored · Tier` sticker on Tutor course cards through Tutor's card hooks.
- **Full search index** — Search matches course name, description/content, category names, child categories, tag names, and tutor display name in both the archive and autocomplete.
- **Enhanced search suggestions** — Replaces the default Tutor autocomplete with a dropdown that shows thumbnails, price, instructor, and the sponsorship tier. Keyboard navigable.
- **Multilingual cross-language search** — With TranslatePress active, a single search box matches a course's title/description/taxonomy text in *any* translated language at once, not just the language the visitor is currently browsing in.

## How the ranking works

The relevance sort uses a **hybrid ranking model** rather than absolute sponsored positions. First, the query is narrowed by all active search/filter constraints. Only courses that actually match the visitor's search and filters are eligible.

For text searches, the organic relevance score favors exact/title matches, then category/tag matches, content/excerpt, tutor name, and cross-language matches. Sponsorship is then added as a **normalized, capped boost**. The tier weights keep their relative strength (for example Platinum > Gold > Featured), but they are scaled to the configurable **Maximum sponsorship influence** value (80 points by default). Paid courses can receive a smaller secondary boost (10 points by default).

Conceptually:

```
final relevance = organic relevance
                 + normalized sponsorship boost (capped)
                 + optional paid-course boost
```

There are **no fixed sponsored rows or reserved ad slots**. That means: no empty sponsored positions when nobody matches, no overflow when many sponsors match, and no irrelevant sponsored course being inserted into a search just because it paid. Explicit sorts such as Price, Rating, Students, Newest, and Title are not overridden by sponsorship.

On browse/filter pages with no text search, a lightweight quality score (rating + enrollment signal) is used as the organic base, so sponsorship still improves discovery without simply putting every sponsored course ahead of every organic course.

Sponsored date ranges are checked at query time, so expired or not-yet-started campaigns receive no ranking boost. A course tier is stored in `_tcsp_promotion_tier`; the legacy `_tcsp_promoted_course` flag is still recognized as the Featured tier. Paid state is read from Tutor's `_tutor_course_price_type` meta.

## Settings

**Tutor LMS → Course Search Pro** (admin):

- Toggle sponsorship ranking and configure the Platinum, Gold, and Featured tier labels, colors, and weights.
- Toggle paid-course boosting and set its weight.
- Assign a sponsorship tier to individual published courses with searchable course/tutor rows.
- Enable/disable each filter and set the default sort.
- Toggle the enhanced suggestions and set how many appear.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- Tutor LMS (free or Pro) active

## Installation

1. Copy the `tutor-course-search-pro` folder into `/wp-content/plugins/`.
2. Activate **Tutor Course Search Pro** in *Plugins*.
3. Go to **Tutor LMS → Course Search Pro** and configure tiers, course assignments, filters, and sorting.

## Multilingual setup (TranslatePress / WPML / Loco Translate)

- **TranslatePress**: handled automatically. TranslatePress doesn't duplicate courses per language — it keeps one course row and translates the rendered output. Because of that, the plugin additionally scans TranslatePress' `trp_dictionary_*` tables for the search term (across every translated language at once) and resolves hits back to the matching course or category/tag, so search works regardless of which language was typed or is currently displayed. Turn this off under *Course Search Pro → Multilingual Search* if you ever need to isolate it while debugging. For a term to be searchable in a given language, TranslatePress must have already translated it (either manually or via automatic translation) — an untranslated string can't be matched in that language yet.
- **WPML**: if you separately use WPML to duplicate courses per language (each language is its own post), no changes are needed — WPML already scopes `WP_Query` to the current language through its own hooks, and that runs independently of this plugin's search/filter logic.
- **Loco Translate**: only used to translate this plugin's own interface strings (filter labels, "Sponsored" badge text, etc.) via the `tutor-course-search-pro` text domain. It has no effect on course content search.
- Running WPML and TranslatePress on the same site at the same time is unusual and the two can conflict with each other (duplicate URL rewriting, language switchers, etc.) since they solve translation in fundamentally different ways. If both are active, confirm which one actually owns your course content before relying on either plugin's language-switching behavior.

## Notes / theme compatibility

- The filter bar renders on `tutor_course/archive/before_loop` (with `tutor_course/loop/before` as a fallback). If your theme uses a custom archive, drop the `[tcsp_filters]` shortcode where you want the bar.
- Sponsored stickers are added through Tutor card hooks (`before_thumbnail`, `after_thumbnail`, `before_title`, and `after_title`) so they work with the standard Tutor templates and most Tutor-compatible themes.
- If replacing suggestions is enabled, the plugin dequeues Tutor's default search scripts (`tutor-course-search`, `tutor-frontend-search`) on course pages.
- Query params used: `s`, `tcsp_price`, `tcsp_level`, `tcsp_category[]`, `tcsp_category_logic`, `tcsp_tag[]`, `tcsp_tag_logic`, `tcsp_min_rating`, `tcsp_min_students`, `tcsp_sort` (Tutor's own `tutor_course_filter` is also respected).
- The filter bar is resilient to AJAX-based pagination: if a theme/Tutor version swaps out the course loop container over AJAX and takes the bar with it, `tcsp-filters.js` keeps a copy and re-inserts it automatically.

## Changelog

### 2.5.0

- **New hybrid sponsorship ranking.** Relevance is now the organic base score and sponsorship is a normalized, capped boost rather than an absolute front-row ranking.
- **No fixed sponsored slots.** Matching sponsors compete naturally for better positions; unused capacity disappears and extra sponsors do not displace the entire result set.
- **Search eligibility is enforced first.** A sponsored course must pass the active text search and all selected filters before its sponsorship boost is considered.
- **Explicit sorts stay explicit.** Price, rating, student count, date, and title sorts are no longer influenced by sponsorship.
- **Browse pages receive a quality base score.** When there is no keyword search, rating and enrollment signals provide the organic baseline so sponsorship is a discovery boost rather than an automatic sponsor-only queue.
- Added the **Maximum sponsorship influence** setting (default 80 points) and reduced the default paid-course boost to 10 points.
- Updated the ranking documentation and plugin version to 2.5.0.

### 2.4.0

- **New: result count.** "N course(s) found" (or "N results for '...'" when there's a search term) now appears above the filter bar, reflecting whatever search/filters are currently active. Toggle under *Course Search Pro → Result Counts → Show result count* (on by default).
- **New: live category/tag counts.** Each category/tag checkbox now shows how many courses would match if it were selected together with your other active filters — not that term's fixed, site-wide course count. The count updates every time filters change (recalculated on each filter submission). Toggle under *Course Search Pro → Result Counts → Live category/tag counts* (on by default; turn off on very large catalogs if the filter bar feels slow, since it runs one lightweight query per visible category/tag).

### 2.3.3

- **Fix: sort order (price, rating, students, newest, title) still didn't stick on non-default-language pages after 2.3.2.** Raising this plugin's SQL-clause priority in 2.3.2 fixed category/tag filtering, but sort order kept getting undone. Reason: something in the request (TranslatePress's `trp-form-language` handling) was reordering the already-fetched array of courses in a step that runs completely separately from — and after — the SQL query (WordPress's `the_posts` filter), so no amount of SQL-priority tuning could reach it. This plugin now also hooks `the_posts` at the maximum possible priority and re-applies the visitor's chosen sort directly to the final array of results, as the last thing that touches it before the page renders. "Relevance" (the default, promoted-courses-first sort) is untouched by this — only the explicit sort choices (Price, Rating, Students, Newest/Oldest, Title A-Z/Z-A) are re-enforced this way, since those are the ones a downstream reorder can silently scramble.

### 2.3.2

- **Fix: category/tag filtering and price/rating/student sorting were unreliable on non-default-language (TranslatePress) URLs.** Confirmed root cause: TranslatePress adds a `trp-form-language` marker to every GET form on a translated page (needed so the results page stays in that language), and registers its own query hooks that react to that marker to re-run/adjust the query for the current language. Those hooks were running *after* this plugin's — at the ordinary priorities this plugin used before (`pre_get_posts` at 100, `posts_clauses` at 20) — and could overwrite the taxonomy filtering and custom ordering this plugin had just built, especially with no actual search term to act on. Both hooks now run at priority `9999`, as late as WordPress allows, so this plugin's filtering and sorting are always the final word for the query regardless of what any other plugin (TranslatePress or otherwise) does earlier in the request. No change to the filtering/sorting logic itself was needed — only when it runs.

### 2.3.1

- **Fix: filtering (category/tag/rating/etc.) returned almost no results on non-default-language pages (TranslatePress).** A plain HTML GET form submits every named field, blank or not — so clicking "Apply" with nothing changed still produced a URL like `?s=&tcsp_price=&tcsp_min_rating=0&tcsp_sort=relevance`. On a non-default-language TranslatePress URL (e.g. `/ar/courses/`), the mere presence of that empty `s=` parameter was enough to trigger TranslatePress' own translated-search query handling, which then constrained the course list to an unrelated handful of results — while the identical filter worked fine on the default-language URL, where that code path never runs. The filter bar now disables any field left at its default/no-op value right before submitting, so an "Apply" with nothing selected produces the same clean, parameter-free URL as browsing with no filters at all, and only real filter selections are ever sent. This is a client-side-only change — no server-side filtering logic was altered (an empty/zero value was already treated as "no filter" server-side).

### 2.3.0

- **Multilingual (TranslatePress) cross-language search.** TranslatePress keeps a single copy of each course and translates it on the fly, so the plugin's plain database search previously only ever matched the course's default-language text. Search (both the archive search bar and the autocomplete suggestions) now also scans TranslatePress' own translation tables and matches a course if the term appears in **any** of its translations — title, description, or category/tag name — regardless of which language the visitor types in or is currently browsing. One search box now finds a course whether someone searches in English, French, Arabic, etc.
- Added a **Search across all languages** toggle under *Tutor LMS → Course Search Pro → Multilingual Search* (on by default; has no effect if TranslatePress isn't active).
- New `includes/class-tcsp-multilingual.php` bridge class; no changes needed for WPML (already language-scoped automatically via its own `pre_get_posts`/`posts_clauses` hooks) or Loco Translate (only translates this plugin's own UI strings via the existing `tutor-course-search-pro` text domain — nothing to configure here).

### 2.0.2

- Fixed Tutor LMS AJAX pagination: filters, search, price/level/taxonomy constraints, and explicit sorting now remain active on page 2+.
- TCSP now also shapes Tutor's frontend AJAX course `WP_Query` instances when the request contains TCSP state, instead of only the archive main query.
- Added a JavaScript AJAX safeguard that copies the current TCSP filter/search parameters onto Tutor AJAX pagination requests.
- Added a pagination-link safeguard for themes that build normal page links without preserving custom query parameters.

### 2.0.0

- Replaced the single promoted-course checkbox with Platinum, Gold, and Featured sponsorship tiers, configurable labels/colors/weights, and a searchable tier-assignment admin interface.
- Added course-level sponsored stickers on Tutor card hook locations, with tier colors and accessible labels.
- Expanded archive and autocomplete indexing to tutor display name as well as course title, description/content, categories, tags, and child categories.
- Category filter options now render hierarchical parent/child categories with term counts; tag options show term counts.

### 1.2.0

- Fixed explicit sorting and category/tag search matching.
- Category and Tag multi-select now support user-selectable AND/OR logic. Parent category selections automatically include all descendants.
- Added a minimum number of students filter and a companion Most students sort option.

### 1.1.0

- Fixed fatal errors caused by invalid nested taxonomy query shapes.
- Added multi-select category and tag filters, reset filters, and safer price sorting.

## Version 2.1.0
- Parent course-category selections include the selected category and all descendants.
- Category multi-select supports ALL (AND) or ANY (OR).
- Tag multi-select supports ALL (AND) or ANY (OR).
- The selected logic is preserved through pagination/filter URLs.


## 2.6.0 Sponsored carousel

Sponsored courses can be shown in a dedicated, relevance-matched carousel above the normal course list. The carousel only uses active sponsorships that match the current search/filter state, never creates empty reserved slots, and rotates tied results deterministically by day. Featured carousel courses are excluded from the organic course list, while explicit organic sorts (price, rating, students, title, date) remain unaffected. The carousel is shown on the first results page.


### Version 2.8.0 – sponsored carousel improvements
- Sponsored tiers now communicate clear upgrade value through priority weight and exposure multiplier (Basic → Pro → Premium).
- Equal-weight sponsored courses use random tie-breaking so one sponsor does not permanently occupy the same spot.
- Carousel controls use jQuery animation for broader browser/theme compatibility.
- Carousel cards show up to two course categories and Tutor LMS course duration.
- Added a Tutor LMS-compatible wishlist button to every sponsored carousel card.
- Featured carousel courses remain excluded from the normal result list.


## 3.2.0
Sponsored courses remain eligible in the normal course results when the sponsored carousel is enabled. The carousel does not remove them from organic search results, and sponsorship has zero ranking boost in the normal list, so they consume normal pagination slots according to their organic score only.


## v4.0.3 – Tutor directory

Adds `[tcsp_tutor_search]` with a default Tutors page, configurable tutor filters/card fields, a sponsored tutor carousel using the existing promotion tiers, Tutor Sponsorships admin, tutor ratings/students/course stats, and reuse of Instructor Profile Extra user-meta fields.


4.1.1: Tutor-only card refinements, parent teaching-category links, compact 3-line bio with profile link, sponsor-plan colors, fixed tutor filter drawer spacing/backgrounds, and restored course CSS baseline.


## Sponsorship portal
Version 4.2.1 adds separate course and tutor sponsorship plans, a front-end instructor Sponsorship page, per-account sponsorship status history, and admin sponsorship order review.

## v4.2.4 Sponsorship checkout
- Uses one persistent admin-owned hidden Tutor LMS checkout course per plan/type (6 total by default).
- Course and tutor sponsorship plans remain separate.
- Tutor purchases are tracked per-user and reconciled against native Tutor orders.
- Compatible with the Instructor Offline Payment plugin by routing sponsorship checkout products to their admin owner/payee.
- Public course queries exclude sponsorship checkout products.


## UStudy shortcodes

- `[tsp_home_search]` — legacy UStudy homepage search widget. Renders the TCSP search and enabled filters, then sends the visitor to the Tutor-configured Course Archive page when submitted.
- `[tcsp_filters]` — full filter bar for custom course-archive templates/pages.
