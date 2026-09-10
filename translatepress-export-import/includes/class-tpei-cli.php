<?php
/**
 * WP-CLI commands: wp tpei status|export|import
 *
 * @package TranslatePress_Export_Import
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * Export/import TranslatePress translations.
	 */
	class TPEI_CLI {

		/**
		 * Show languages and translation counts.
		 *
		 * ## EXAMPLES
		 *
		 *     wp tpei status
		 */
		public function status() {
			$default   = TPEI_Core::get_default_language();
			$languages = TPEI_Core::get_translation_languages();

			if ( empty( $languages ) ) {
				WP_CLI::warning( __( 'No translation languages found.', 'translatepress-export-import' ) );
				return;
			}

			$rows = array();
			foreach ( $languages as $code ) {
				$dict = TPEI_Core::get_dictionary_table( $code, $default );
				$c    = TPEI_Core::get_counts( $dict );
				$rows[] = array(
					'language'     => $code,
					'table'        => $dict,
					'exists'       => $c['exists'] ? 'yes' : 'no',
					'total'        => $c['total'],
					'translated'   => $c['translated'],
					'untranslated' => $c['untranslated'],
					'human'        => $c['human'],
					'machine'      => $c['machine'],
				);
			}
			WP_CLI::line( sprintf( 'Default language: %s', $default ) );
			WP_CLI\Utils\format_items( 'table', $rows, array( 'language', 'table', 'exists', 'total', 'translated', 'untranslated', 'human', 'machine' ) );
		}

		/**
		 * Export translations to a file.
		 *
		 * ## OPTIONS
		 *
		 * --language=<code>
		 * : Target language code, e.g. fr_FR.
		 *
		 * --file=<path>
		 * : Destination file path.
		 *
		 * [--source=<source>]
		 * : dictionary, gettext or slugs. Default: dictionary.
		 *
		 * [--format=<format>]
		 * : csv or json. Default: csv.
		 *
		 * [--filter=<filter>]
		 * : all, untranslated, translated, machine, human. Default: all.
		 *
		 * [--delimiter=<char>]
		 * : CSV delimiter. Default: , (comma).
		 *
		 * [--scope=<scope>]
		 * : full or translator. Default: translator.
		 *
		 * [--search=<term>]
		 * : Only strings containing this term.
		 *
		 * ## EXAMPLES
		 *
		 *     wp tpei export --language=fr_FR --file=/tmp/tp-fr.csv --filter=untranslated
		 */
		public function export( $args, $assoc_args ) {
			$assoc_args = is_array( $assoc_args ) ? $assoc_args : array();
			if ( empty( $assoc_args['language'] ) || empty( $assoc_args['file'] ) ) {
				WP_CLI::error( 'Missing required --language and --file parameters.' );
			}
			$export_args = TPEI_Export::sanitize_args(
				array(
					'language'  => $assoc_args['language'],
					'source'    => isset( $assoc_args['source'] ) ? $assoc_args['source'] : 'dictionary',
					'filter'    => isset( $assoc_args['filter'] ) ? $assoc_args['filter'] : 'all',
					'format'    => isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'csv',
					'delimiter' => isset( $assoc_args['delimiter'] ) ? $assoc_args['delimiter'] : ',',
					'scope'     => isset( $assoc_args['scope'] ) ? $assoc_args['scope'] : 'translator',
					'search'    => isset( $assoc_args['search'] ) ? $assoc_args['search'] : '',
				)
			);
			$result = TPEI_Export::export_to_file( $export_args, $assoc_args['file'] );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
			WP_CLI::success( sprintf( 'Exported %d rows to %s', $result['rows'], $result['file'] ) );
		}

		/**
		 * Import translations from a CSV/JSON file.
		 *
		 * ## OPTIONS
		 *
		 * --file=<path>
		 * : Source CSV/JSON file.
		 *
		 * [--language=<code>]
		 * : Target language code. Default: auto (JSON meta only — CSV needs this).
		 *
		 * [--source=<source>]
		 * : auto, dictionary or gettext. Default: auto.
		 *
		 * [--match-by=<mode>]
		 * : id or original. Default: id.
		 *
		 * [--update-mode=<mode>]
		 * : empty_only or overwrite. Default: empty_only.
		 *
		 * [--new-status=<status>]
		 * : file, human or machine. Default: file.
		 *
		 * [--create-missing]
		 * : Insert strings that do not exist yet.
		 *
		 * [--dry-run]
		 * : Preview without saving.
		 *
		 * ## EXAMPLES
		 *
		 *     wp tpei import --language=fr_FR --file=/tmp/tp-fr-translated.csv --dry-run
		 *     wp tpei import --language=fr_FR --file=/tmp/tp-fr-translated.csv --create-missing
		 */
		public function import( $args, $assoc_args ) {
			$assoc_args = is_array( $assoc_args ) ? $assoc_args : array();
			if ( empty( $assoc_args['file'] ) ) {
				WP_CLI::error( 'Missing required --file parameter.' );
			}
			$import_args = TPEI_Import::sanitize_args(
				array(
					'language'       => isset( $assoc_args['language'] ) ? $assoc_args['language'] : 'auto',
					'source'         => isset( $assoc_args['source'] ) ? $assoc_args['source'] : 'auto',
					'match_by'       => isset( $assoc_args['match-by'] ) ? $assoc_args['match-by'] : 'id',
					'update_mode'    => isset( $assoc_args['update-mode'] ) ? $assoc_args['update-mode'] : 'empty_only',
					'new_status'     => isset( $assoc_args['new-status'] ) ? $assoc_args['new-status'] : 'file',
					'create_missing' => ! empty( $assoc_args['create-missing'] ),
					'dry_run'        => ! empty( $assoc_args['dry-run'] ),
				)
			);
			$result = TPEI_Import::import_from_file( $assoc_args['file'], $import_args );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
			if ( ! empty( $result['dry_run'] ) ) {
				WP_CLI::line( 'DRY RUN — nothing was saved.' );
			}
			WP_CLI::success(
				sprintf(
					'Rows: %d | Updated: %d | Inserted: %d | Skipped: %d | Errors: %d (%s)',
					$result['total'],
					$result['updated'],
					$result['inserted'],
					$result['skipped'],
					$result['errors'],
					$result['table']
				)
			);
			if ( ! empty( $result['error_log'] ) ) {
				foreach ( array_slice( $result['error_log'], 0, 10 ) as $err ) {
					WP_CLI::warning( sprintf( 'Line %d: %s', $err['line'], $err['message'] ) );
				}
			}
		}
	}

	WP_CLI::add_command( 'tpei', 'TPEI_CLI' );
}
