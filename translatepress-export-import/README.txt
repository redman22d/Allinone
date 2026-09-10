=== TranslatePress – Export / Import Translations ===
Contributors: allinone
Tags: translatepress, translation, export, import, csv, multilingual
Requires at least: 5.2
Tested up to: 6.7
Requires PHP: 7.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export TranslatePress strings to CSV/JSON, translate them anywhere, then import the translations back.

== Description ==

TranslatePress stores translations in its own database tables and ships without an export/import feature. This add-on fills that gap:

* **Export** original strings + translations per language to **CSV** (Excel / Google Sheets) or **JSON**
* **Translator-friendly mode**: `id, original, translated` — perfect for the “export originals → translate elsewhere → import back” workflow
* **Filters**: all, untranslated only, translated only, machine translated, human reviewed + free-text search
* **Import** with match-by-ID (exact) or match-by-original-text (great for migrating between sites)
* **Safe defaults**: “only fill empty translations”, dry-run preview, detailed report with samples and errors
* **Dictionary + gettext** tables, slug export, per-language overview with live counts
* **WP-CLI**: `wp tpei status|export|import` for automation and huge sites
* No dependencies, UTF-8/BOM-safe CSV, works with large tables (batched + streamed)

Workflow:

1. Export → target language → “Only untranslated” → Translator-friendly → CSV → Download.
2. Fill in only the `translated` column (keep `id` and `original` untouched), save as CSV UTF-8.
3. Import → upload → same language → Match by ID → Only fill empty ones → Run (try Dry run first).

== Installation ==

1. Make sure TranslatePress (free or premium) is installed, active and configured with at least one translation language.
2. Upload this plugin (`translatepress-export-import.zip`) via Plugins → Add New → Upload, or copy the folder to `wp-content/plugins/`.
3. Activate it.
4. Go to Settings → TP Export / Import (also under the TranslatePress menu).

== Frequently Asked Questions ==

= Where is the settings page? =
Settings → TP Export / Import. If the TranslatePress menu is present, you will also find an “Export / Import” submenu there.

= Which tables are supported? =
Dictionary tables (`trp_dictionary_*`, page/post strings) and gettext tables (`trp_gettext_*`, theme/plugin strings) — full export + import. Slug tables (`trp_slug_translations`) — export only in this version.

= Can I move translations to another site? =
Yes. Export “Full”, then on the other site import with “Match by Original text” + “Create strings that don’t exist yet”. Both sites should use the same default language.

= Will import overwrite my existing translations? =
Not by default. The default mode “Only fill empty ones” never touches existing translations. Choose “Overwrite” explicitly if you want that.

= My CSV looks broken in Excel. =
Re-export with the semicolon delimiter (best for comma-decimal locales) and always save as “CSV UTF-8” in Excel.

== Changelog ==

= 1.0.0 =
* First release: per-language CSV/JSON export with filters, safe imports with dry-run, WP-CLI commands, language overview.
