# Allinone

Collection of WordPress plugins/add-ons.

## TranslatePress – Export / Import Translations

**Folder:** `translatepress-export-import/` · **Installable zip:** `translatepress-export-import.zip`

TranslatePress stores translations in its own database tables and ships without an
export/import feature. This add-on fills that gap:

- **Export** original strings + translations per language to **CSV** (Excel / Google Sheets) or **JSON**
- **Translator-friendly mode** (`id, original, translated`) — the "export originals → translate elsewhere → import back" workflow
- **Filters:** all / untranslated only / translated only / machine / human + free-text search
- **Import** with match-by-ID (exact) or match-by-original-text (migrate between sites)
- **Safe defaults:** "only fill empty translations", dry-run preview, detailed report
- **Dictionary + gettext** tables, slug export, per-language overview with live counts
- **WP-CLI:** `wp tpei status|export|import`
- No dependencies, UTF-8/BOM-safe CSV, batched + streamed for large tables

### Install

1. TranslatePress (free or premium) must be installed, active and configured with at least one translation language.
2. Upload `translatepress-export-import.zip` via Plugins → Add New → Upload (or copy the folder to `wp-content/plugins/`).
3. Activate, then go to Settings → TP Export / Import.

### Workflow

1. Export → target language → "Only untranslated" → Translator-friendly → CSV → Download.
2. Fill in only the `translated` column (keep `id`/`original`), save as CSV UTF-8.
3. Import → upload → same language → Match by ID → Only fill empty ones → Run (try Dry run first).

See `translatepress-export-import/README.txt` for the full readme/FAQ.
