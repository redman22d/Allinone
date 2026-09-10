<?php
/**
 * Admin page: export / import forms, language overview, help.
 *
 * @package TranslatePress_Export_Import
 */

defined( 'ABSPATH' ) || exit;

class TPEI_Admin {

	const MENU_SLUG = 'tpei-export-import';

	/**
	 * Constructor: hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_tpei_template', array( $this, 'download_template' ) );
		add_action( 'admin_notices', array( $this, 'maybe_missing_trp_notice' ) );
	}

	/**
	 * Register the admin page (Settings + TranslatePress submenu when available).
	 */
	public function register_menu() {
		$capability = 'manage_options';
		$title      = __( 'TP Export / Import', 'translatepress-export-import' );
		$page_title = __( 'TranslatePress Export / Import', 'translatepress-export-import' );

		add_options_page( $page_title, $title, $capability, self::MENU_SLUG, array( $this, 'render_page' ) );

		// Also attach under TranslatePress menu if it exists (slug: translate-press).
		global $submenu;
		if ( isset( $submenu['translate-press'] ) ) {
			add_submenu_page( 'translate-press', $page_title, __( 'Export / Import', 'translatepress-export-import' ), $capability, self::MENU_SLUG, array( $this, 'render_page' ) );
		}
	}

	/**
	 * Enqueue assets on our page only.
	 *
	 * @param string $hook Current admin hook suffix.
	 */
	public function enqueue( $hook ) {
		$is_ours = ( false !== strpos( (string) $hook, self::MENU_SLUG ) );
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $is_ours && self::MENU_SLUG !== $page ) {
			return;
		}
		wp_enqueue_style( 'tpei-admin', TPEI_PLUGIN_URL . 'assets/css/admin.css', array(), TPEI_VERSION );
		wp_enqueue_script( 'tpei-admin', TPEI_PLUGIN_URL . 'assets/js/admin.js', array(), TPEI_VERSION, true );
	}

	/**
	 * Warn when TranslatePress data cannot be found.
	 */
	public function maybe_missing_trp_notice() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::MENU_SLUG !== $page ) {
			return;
		}
		if ( TPEI_Core::is_trp_active() ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'TranslatePress does not seem to be installed or configured yet. This plugin reads/writes the TranslatePress dictionary tables — install and set up TranslatePress first, then come back here.', 'translatepress-export-import' );
		echo '</p></div>';
	}

	/**
	 * Download a blank CSV template for translators.
	 */
	public function download_template() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'translatepress-export-import' ), 403 );
		}
		check_admin_referer( 'tpei_template' );

		while ( ob_get_level() ) {
			ob_end_clean();
		}
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="translatepress-import-template.csv"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$fh = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
		fwrite( $fh, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fwrite
		fputcsv( $fh, array( 'id', 'original', 'translated', 'status' ) );
		fputcsv( $fh, array( '123', 'Hello world', '', '0' ) );
		fputcsv( $fh, array( '124', 'Read more', 'Lire la suite', '2' ) );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
		exit;
	}

	/**
	 * Render the admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'translatepress-export-import' ), 403 );
		}

		$tab        = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'export'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $tab, array( 'export', 'import', 'help' ), true ) ) {
			$tab = 'export';
		}
		$default    = TPEI_Core::get_default_language();
		$languages  = TPEI_Core::get_translation_languages();
		$last_result = null;
		if ( isset( $_GET['tpei_done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$last_result = TPEI_Import::pop_last_result();
		}
		?>
		<div class="wrap tpei-wrap">
			<h1><?php esc_html_e( 'TranslatePress Export / Import', 'translatepress-export-import' ); ?></h1>
			<p class="tpei-subtitle">
				<?php esc_html_e( 'Export original strings, translate them anywhere (Excel, CAT tools, AI services), then import the translations back.', 'translatepress-export-import' ); ?>
			</p>

			<div class="tpei-status-card">
				<strong><?php esc_html_e( 'Status:', 'translatepress-export-import' ); ?></strong>
				<?php if ( TPEI_Core::is_trp_active() ) : ?>
					<span class="tpei-ok">✔ <?php esc_html_e( 'TranslatePress detected', 'translatepress-export-import' ); ?></span>
					<span class="tpei-meta">
						<?php
						printf(
							/* translators: %s: default language. */
							esc_html__( 'Default language: %s', 'translatepress-export-import' ),
							'' !== $default ? esc_html( TPEI_Core::pretty_lang( $default ) ) : esc_html__( 'unknown', 'translatepress-export-import' )
						);
						?>
					</span>
				<?php else : ?>
					<span class="tpei-warn">⚠ <?php esc_html_e( 'TranslatePress not detected', 'translatepress-export-import' ); ?></span>
				<?php endif; ?>
			</div>

			<h2 class="nav-tab-wrapper tpei-tabs">
				<a href="#tpei-tab-export" data-tab="export" class="nav-tab <?php echo 'export' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Export', 'translatepress-export-import' ); ?></a>
				<a href="#tpei-tab-import" data-tab="import" class="nav-tab <?php echo 'import' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Import', 'translatepress-export-import' ); ?></a>
				<a href="#tpei-tab-help" data-tab="help" class="nav-tab <?php echo 'help' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Workflow & Help', 'translatepress-export-import' ); ?></a>
			</h2>

			<div id="tpei-tab-export" class="tpei-tab" <?php echo 'export' === $tab ? '' : 'hidden'; ?>>
				<?php $this->render_export_tab( $default, $languages ); ?>
			</div>
			<div id="tpei-tab-import" class="tpei-tab" <?php echo 'import' === $tab ? '' : 'hidden'; ?>>
				<?php $this->render_import_tab( $languages, $last_result ); ?>
			</div>
			<div id="tpei-tab-help" class="tpei-tab" <?php echo 'help' === $tab ? '' : 'hidden'; ?>>
				<?php $this->render_help_tab(); ?>
			</div>

			<?php $this->render_overview( $default, $languages ); ?>
		</div>
		<?php
	}

	/**
	 * Export tab.
	 *
	 * @param string   $default   Default language.
	 * @param string[] $languages Translation languages.
	 */
	protected function render_export_tab( $default, $languages ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tpei-form">
			<input type="hidden" name="action" value="tpei_export" />
			<?php wp_nonce_field( 'tpei_export' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="tpei-exp-language"><?php esc_html_e( 'Target language', 'translatepress-export-import' ); ?></label></th>
					<td>
						<select name="language" id="tpei-exp-language" required>
							<?php if ( empty( $languages ) ) : ?>
								<option value=""><?php esc_html_e( '— No languages found —', 'translatepress-export-import' ); ?></option>
							<?php else : ?>
								<?php foreach ( $languages as $code ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( TPEI_Core::pretty_lang( $code ) ); ?></option>
								<?php endforeach; ?>
							<?php endif; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Strings are stored per target language (from the default language to this one).', 'translatepress-export-import' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="tpei-exp-source"><?php esc_html_e( 'Content source', 'translatepress-export-import' ); ?></label></th>
					<td>
						<select name="source" id="tpei-exp-source">
							<option value="dictionary"><?php esc_html_e( 'Page & post strings (dictionary) — recommended', 'translatepress-export-import' ); ?></option>
							<option value="gettext"><?php esc_html_e( 'Theme & plugin strings (gettext)', 'translatepress-export-import' ); ?></option>
							<option value="slugs"><?php esc_html_e( 'URL slugs (export only)', 'translatepress-export-import' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="tpei-exp-filter"><?php esc_html_e( 'Which strings', 'translatepress-export-import' ); ?></label></th>
					<td>
						<select name="filter" id="tpei-exp-filter">
							<?php foreach ( TPEI_Core::get_filters() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Tip: export “Only untranslated” to send just the missing strings to a translator.', 'translatepress-export-import' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Columns', 'translatepress-export-import' ); ?></th>
					<td>
						<label><input type="radio" name="scope" value="translator" checked /> <?php esc_html_e( 'Translator-friendly (id, original, translated) — best for “translate elsewhere”', 'translatepress-export-import' ); ?></label><br />
						<label><input type="radio" name="scope" value="full" /> <?php esc_html_e( 'Full (every database column) — best for backups/migration', 'translatepress-export-import' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Format', 'translatepress-export-import' ); ?></th>
					<td>
						<label><input type="radio" name="format" value="csv" checked /> <?php esc_html_e( 'CSV (Excel / Google Sheets)', 'translatepress-export-import' ); ?></label><br />
						<label><input type="radio" name="format" value="json" /> <?php esc_html_e( 'JSON (developers / CAT tools)', 'translatepress-export-import' ); ?></label>
					</td>
				</tr>
				<tr class="tpei-delimiter-row">
					<th scope="row"><label for="tpei-exp-delimiter"><?php esc_html_e( 'CSV delimiter', 'translatepress-export-import' ); ?></label></th>
					<td>
						<select name="delimiter" id="tpei-exp-delimiter">
							<?php foreach ( TPEI_Export::allowed_delimiters() as $char => $label ) : ?>
								<option value="<?php echo esc_attr( $char ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Semicolon usually opens correctly in Excel with comma-decimal locales.', 'translatepress-export-import' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="tpei-exp-search"><?php esc_html_e( 'Search (optional)', 'translatepress-export-import' ); ?></label></th>
					<td>
						<input type="search" name="search" id="tpei-exp-search" class="regular-text" placeholder="<?php esc_attr_e( 'Only strings containing…', 'translatepress-export-import' ); ?>" />
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Download export', 'translatepress-export-import' ), 'primary', 'submit', false ); ?>
			<p class="description"><?php esc_html_e( 'The file downloads immediately. Large sites stream in batches, so exports of 100k+ strings are fine.', 'translatepress-export-import' ); ?></p>
		</form>
		<?php
	}

	/**
	 * Import tab.
	 *
	 * @param string[]          $languages   Translation languages.
	 * @param array|WP_Error|null $last_result Last import result, if any.
	 */
	protected function render_import_tab( $languages, $last_result ) {
		if ( null !== $last_result ) {
			$this->render_result( $last_result );
		}
		$template_url = wp_nonce_url( add_query_arg( array( 'action' => 'tpei_template' ), admin_url( 'admin-post.php' ) ), 'tpei_template' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="tpei-form">
			<input type="hidden" name="action" value="tpei_import" />
			<?php wp_nonce_field( 'tpei_import' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="tpei-imp-file"><?php esc_html_e( 'Translated file', 'translatepress-export-import' ); ?></label></th>
					<td>
						<input type="file" name="tpei_file" id="tpei-imp-file" accept=".csv,.tsv,.txt,.json" required />
						<p class="description">
							<?php esc_html_e( 'A CSV or JSON file from our Export tab, with the “translated” column filled in. Max size:', 'translatepress-export-import' ); ?>
							<strong><?php echo esc_html( size_format( TPEI_Import::max_size() ) ); ?></strong>
							— <a href="<?php echo esc_url( $template_url ); ?>"><?php esc_html_e( 'Download blank CSV template', 'translatepress-export-import' ); ?></a>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="tpei-imp-language"><?php esc_html_e( 'Target language', 'translatepress-export-import' ); ?></label></th>
					<td>
						<select name="language" id="tpei-imp-language">
							<option value="auto"><?php esc_html_e( 'Auto-detect (JSON exports only)', 'translatepress-export-import' ); ?></option>
							<?php foreach ( $languages as $code ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( TPEI_Core::pretty_lang( $code ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'For CSV files, always select the language explicitly.', 'translatepress-export-import' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="tpei-imp-source"><?php esc_html_e( 'Content source', 'translatepress-export-import' ); ?></label></th>
					<td>
						<select name="source" id="tpei-imp-source">
							<option value="auto"><?php esc_html_e( 'Auto-detect', 'translatepress-export-import' ); ?></option>
							<option value="dictionary"><?php esc_html_e( 'Page & post strings (dictionary)', 'translatepress-export-import' ); ?></option>
							<option value="gettext"><?php esc_html_e( 'Theme & plugin strings (gettext)', 'translatepress-export-import' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Match rows by', 'translatepress-export-import' ); ?></th>
					<td>
						<label><input type="radio" name="match_by" value="id" checked /> <?php esc_html_e( 'ID (recommended — exact, fastest)', 'translatepress-export-import' ); ?></label><br />
						<label><input type="radio" name="match_by" value="original" /> <?php esc_html_e( 'Original text (use when IDs changed, e.g. another site)', 'translatepress-export-import' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Existing translations', 'translatepress-export-import' ); ?></th>
					<td>
						<label><input type="radio" name="update_mode" value="empty_only" checked /> <?php esc_html_e( 'Only fill empty ones (safe — never overwrites)', 'translatepress-export-import' ); ?></label><br />
						<label><input type="radio" name="update_mode" value="overwrite" /> <?php esc_html_e( 'Overwrite with file values', 'translatepress-export-import' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="tpei-imp-status"><?php esc_html_e( 'Mark imported as', 'translatepress-export-import' ); ?></label></th>
					<td>
						<select name="new_status" id="tpei-imp-status">
							<option value="file"><?php esc_html_e( 'Use file status (or “human reviewed” when translated)', 'translatepress-export-import' ); ?></option>
							<option value="human"><?php esc_html_e( 'Human reviewed', 'translatepress-export-import' ); ?></option>
							<option value="machine"><?php esc_html_e( 'Machine translated', 'translatepress-export-import' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Options', 'translatepress-export-import' ); ?></th>
					<td>
						<label><input type="checkbox" name="create_missing" value="1" /> <?php esc_html_e( 'Create strings that don’t exist yet', 'translatepress-export-import' ); ?></label><br />
						<label><input type="checkbox" name="dry_run" value="1" /> <?php esc_html_e( 'Dry run — preview what would happen without saving', 'translatepress-export-import' ); ?></label>
						<p class="description"><?php esc_html_e( 'CSV delimiter is auto-detected (, ; tab |). Files must be UTF-8.', 'translatepress-export-import' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Run import', 'translatepress-export-import' ), 'primary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Render an import result report.
	 *
	 * @param array|WP_Error $result Result data.
	 */
	protected function render_result( $result ) {
		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Import failed:', 'translatepress-export-import' ) . '</strong> ' . esc_html( $result->get_error_message() ) . '</p></div>';
			return;
		}
		if ( ! is_array( $result ) ) {
			return;
		}
		$cls = ( $result['errors'] > 0 ) ? 'notice-warning' : 'notice-success';
		echo '<div class="notice ' . esc_attr( $cls ) . '"><p>';
		if ( ! empty( $result['dry_run'] ) ) {
			echo '<strong>' . esc_html__( 'Dry run finished — nothing was saved.', 'translatepress-export-import' ) . '</strong><br />';
		} else {
			echo '<strong>' . esc_html__( 'Import finished.', 'translatepress-export-import' ) . '</strong><br />';
		}
		printf(
			/* translators: 1: total, 2: updated, 3: inserted, 4: skipped, 5: errors. */
			esc_html__( 'Rows: %1$d • Updated: %2$d • Inserted: %3$d • Skipped: %4$d • Errors: %5$d', 'translatepress-export-import' ),
			(int) $result['total'],
			(int) $result['updated'],
			(int) $result['inserted'],
			(int) $result['skipped'],
			(int) $result['errors']
		);
		if ( ! empty( $result['table'] ) ) {
			echo '<br /><code>' . esc_html( $result['table'] ) . '</code>';
		}
		echo '</p></div>';

		if ( ! empty( $result['sample'] ) && is_array( $result['sample'] ) ) {
			echo '<h3>' . esc_html__( 'Sample of processed rows', 'translatepress-export-import' ) . '</h3>';
			echo '<table class="widefat striped tpei-sample"><thead><tr><th>' . esc_html__( 'Result', 'translatepress-export-import' ) . '</th><th>ID</th><th>' . esc_html__( 'Original', 'translatepress-export-import' ) . '</th><th>' . esc_html__( 'Translated', 'translatepress-export-import' ) . '</th></tr></thead><tbody>';
			foreach ( $result['sample'] as $row ) {
				echo '<tr><td>' . esc_html( TPEI_Import::sample_label( $row['action'] ) ) . '</td><td>' . esc_html( (string) $row['id'] ) . '</td><td>' . esc_html( (string) $row['original'] ) . '</td><td>' . esc_html( (string) $row['translated'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		if ( ! empty( $result['error_log'] ) && is_array( $result['error_log'] ) ) {
			echo '<h3>' . esc_html__( 'First errors', 'translatepress-export-import' ) . '</h3>';
			echo '<ol class="tpei-errors">';
			foreach ( $result['error_log'] as $err ) {
				printf( '<li>' . esc_html__( 'Line %1$d: %2$s', 'translatepress-export-import' ) . '</li>', (int) $err['line'], esc_html( (string) $err['message'] ) );
			}
			echo '</ol>';
		}
	}

	/**
	 * Help tab.
	 */
	protected function render_help_tab() {
		?>
		<div class="tpei-help">
			<h3><?php esc_html_e( 'Recommended workflow: translate elsewhere, import back', 'translatepress-export-import' ); ?></h3>
			<ol>
				<li><?php esc_html_e( 'Export tab → pick the target language → “Only untranslated” → “Translator-friendly” → CSV → Download.', 'translatepress-export-import' ); ?></li>
				<li><?php esc_html_e( 'Open the CSV in Excel / Google Sheets (UTF-8). Do NOT edit the “id” or “original” columns — only fill in “translated”.', 'translatepress-export-import' ); ?></li>
				<li><?php esc_html_e( 'Save as CSV (UTF-8). In Excel use “CSV UTF-8” to keep accents and RTL text intact.', 'translatepress-export-import' ); ?></li>
				<li><?php esc_html_e( 'Import tab → upload the file → select the same language → “Match by ID” → “Only fill empty ones” → Run import (try “Dry run” first).', 'translatepress-export-import' ); ?></li>
				<li><?php esc_html_e( 'Check the report, then visit a translated page to verify. That’s it — caches are cleared automatically.', 'translatepress-export-import' ); ?></li>
			</ol>
			<h3><?php esc_html_e( 'Tips', 'translatepress-export-import' ); ?></h3>
			<ul class="ul-disc">
				<li><?php esc_html_e( 'Keep the “id” column: it makes imports exact even if the original text changed slightly.', 'translatepress-export-import' ); ?></li>
				<li><?php esc_html_e( 'Moving translations to ANOTHER site? Export “Full”, import with “Match by Original text” + “Create strings that don’t exist yet”. IDs usually differ between sites.', 'translatepress-export-import' ); ?></li>
				<li><?php esc_html_e( '“Only fill empty ones” never overwrites existing translations — the safe default.', 'translatepress-export-import' ); ?></li>
				<li><?php esc_html_e( 'Gettext strings (theme/plugin texts) live in separate tables — export/import them separately with the “gettext” source.', 'translatepress-export-import' ); ?></li>
				<li><?php esc_html_e( 'Always keep a database backup before big imports. A “Full” export also works as a quick backup of one language.', 'translatepress-export-import' ); ?></li>
			</ul>
			<h3><?php esc_html_e( 'WP-CLI', 'translatepress-export-import' ); ?></h3>
			<p><?php esc_html_e( 'For automation and huge sites:', 'translatepress-export-import' ); ?></p>
			<pre>wp tpei status
wp tpei export --language=fr_FR --format=csv --file=/tmp/tp-fr.csv --filter=untranslated
wp tpei import --language=fr_FR --file=/tmp/tp-fr-translated.csv --dry-run
wp tpei import --language=fr_FR --file=/tmp/tp-fr-translated.csv</pre>
		</div>
		<?php
	}

	/**
	 * Language overview table with live counts.
	 *
	 * @param string   $default   Default language.
	 * @param string[] $languages Translation languages.
	 */
	protected function render_overview( $default, $languages ) {
		echo '<hr /><h2>' . esc_html__( 'Languages overview', 'translatepress-export-import' ) . '</h2>';
		if ( empty( $languages ) ) {
			echo '<p>' . esc_html__( 'No translation languages found. Configure them in TranslatePress → Settings first.', 'translatepress-export-import' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped tpei-overview"><thead><tr>';
		echo '<th>' . esc_html__( 'Language', 'translatepress-export-import' ) . '</th>';
		echo '<th>' . esc_html__( 'Dictionary table', 'translatepress-export-import' ) . '</th>';
		echo '<th>' . esc_html__( 'Total', 'translatepress-export-import' ) . '</th>';
		echo '<th>' . esc_html__( 'Translated', 'translatepress-export-import' ) . '</th>';
		echo '<th>' . esc_html__( 'Untranslated', 'translatepress-export-import' ) . '</th>';
		echo '<th>' . esc_html__( 'Human / Machine', 'translatepress-export-import' ) . '</th>';
		echo '<th>' . esc_html__( 'Gettext', 'translatepress-export-import' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $languages as $code ) {
			$dict_table    = TPEI_Core::get_dictionary_table( $code, $default );
			$gettext_table = TPEI_Core::get_gettext_table( $code, $default );
			$counts        = TPEI_Core::get_counts( $dict_table );
			$g_counts      = TPEI_Core::get_counts( $gettext_table );

			echo '<tr>';
			echo '<td><strong>' . esc_html( TPEI_Core::pretty_lang( $code ) ) . '</strong></td>';
			if ( $counts['exists'] ) {
				echo '<td><code>' . esc_html( $dict_table ) . '</code></td>';
				echo '<td>' . esc_html( number_format_i18n( $counts['total'] ) ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( $counts['translated'] ) ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( $counts['untranslated'] ) ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( $counts['human'] ) . ' / ' . number_format_i18n( $counts['machine'] ) ) . '</td>';
			} else {
				echo '<td><code>' . esc_html( $dict_table ) . '</code><br /><span class="tpei-warn">' . esc_html__( 'table not created yet', 'translatepress-export-import' ) . '</span></td>';
				echo '<td colspan="4">—</td>';
			}
			if ( $g_counts['exists'] ) {
				echo '<td>' . esc_html( number_format_i18n( $g_counts['translated'] ) . ' / ' . number_format_i18n( $g_counts['total'] ) ) . '</td>';
			} else {
				echo '<td>—</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}
