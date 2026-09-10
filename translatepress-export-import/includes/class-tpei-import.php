<?php
/**
 * Import translations back into TranslatePress tables from CSV / JSON.
 *
 * @package TranslatePress_Export_Import
 */

defined( 'ABSPATH' ) || exit;

class TPEI_Import {

	/**
	 * Register HTTP handler.
	 */
	public static function init() {
		add_action( 'admin_post_tpei_import', array( __CLASS__, 'handle_http_import' ) );
	}

	/**
	 * admin-post.php handler: receive the upload, run the import, redirect with results.
	 */
	public static function handle_http_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to import translations.', 'translatepress-export-import' ), 403 );
		}
		check_admin_referer( 'tpei_import' );

		$args = self::sanitize_args( wp_unslash( $_POST ) );

		if ( empty( $_FILES['tpei_file'] ) || ! is_array( $_FILES['tpei_file'] ) ) {
			self::redirect_back( new WP_Error( 'tpei_no_file', __( 'Please choose a CSV or JSON file to import.', 'translatepress-export-import' ) ) );
		}

		$stored = self::store_upload( $_FILES['tpei_file'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( is_wp_error( $stored ) ) {
			self::redirect_back( $stored );
		}

		$result = self::import_from_file( $stored, $args );

		@unlink( $stored ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink

		self::redirect_back( $result );
	}

	/**
	 * Redirect back to the admin page, persisting the result for display.
	 *
	 * @param array|WP_Error $result Import stats or error.
	 */
	protected static function redirect_back( $result ) {
		$key = 'tpei_result_' . get_current_user_id();
		set_transient( $key, $result, 5 * MINUTE_IN_SECONDS );
		$url = add_query_arg(
			array(
				'page'      => 'tpei-export-import',
				'tab'       => 'import',
				'tpei_done' => '1',
			),
			admin_url( 'options-general.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Fetch + clear the last import result for the current user.
	 *
	 * @return array|WP_Error|null
	 */
	public static function pop_last_result() {
		$key = 'tpei_result_' . get_current_user_id();
		$result = get_transient( $key );
		if ( false !== $result ) {
			delete_transient( $key );
			return $result;
		}
		return null;
	}

	/**
	 * Sanitize raw request args.
	 *
	 * @param array $raw Raw input.
	 * @return array
	 */
	public static function sanitize_args( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		$args = array(
			'language'       => isset( $raw['language'] ) ? sanitize_text_field( $raw['language'] ) : 'auto',
			'source'         => isset( $raw['source'] ) ? sanitize_key( $raw['source'] ) : 'auto',
			'match_by'       => isset( $raw['match_by'] ) ? sanitize_key( $raw['match_by'] ) : 'id',
			'update_mode'    => isset( $raw['update_mode'] ) ? sanitize_key( $raw['update_mode'] ) : 'empty_only',
			'new_status'     => isset( $raw['new_status'] ) ? sanitize_key( $raw['new_status'] ) : 'file',
			'delimiter'      => isset( $raw['delimiter'] ) ? (string) $raw['delimiter'] : 'auto',
			'create_missing' => ! empty( $raw['create_missing'] ),
			'dry_run'        => ! empty( $raw['dry_run'] ),
		);
		if ( ! in_array( $args['source'], array( 'auto', 'dictionary', 'gettext' ), true ) ) {
			$args['source'] = 'auto';
		}
		if ( ! in_array( $args['match_by'], array( 'id', 'original' ), true ) ) {
			$args['match_by'] = 'id';
		}
		if ( ! in_array( $args['update_mode'], array( 'empty_only', 'overwrite' ), true ) ) {
			$args['update_mode'] = 'empty_only';
		}
		if ( ! in_array( $args['new_status'], array( 'file', 'human', 'machine' ), true ) ) {
			$args['new_status'] = 'file';
		}
		if ( 'auto' !== $args['delimiter'] ) {
			$allowed = array( ',', ';', "\t", '|' );
			if ( ! in_array( $args['delimiter'], $allowed, true ) ) {
				$args['delimiter'] = 'auto';
			}
		}
		return $args;
	}

	/**
	 * Max upload size in bytes (filterable, default 25 MB).
	 *
	 * @return int
	 */
	public static function max_size() {
		/**
		 * Filter the maximum import file size.
		 *
		 * @param int $bytes Default 25 MB.
		 */
		return (int) apply_filters( 'tpei_max_import_size', 25 * 1024 * 1024 );
	}

	/**
	 * Move the uploaded file to a temp location after basic validation.
	 *
	 * @param array $file Single $_FILES entry.
	 * @return string|WP_Error Temp path or error.
	 */
	protected static function store_upload( $file ) {
		if ( ! isset( $file['error'], $file['tmp_name'], $file['name'], $file['size'] ) ) {
			return new WP_Error( 'tpei_upload', __( 'Invalid file upload.', 'translatepress-export-import' ) );
		}
		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'tpei_upload', __( 'File upload failed. Please try again with a smaller file.', 'translatepress-export-import' ) );
		}
		if ( (int) $file['size'] > self::max_size() ) {
			return new WP_Error(
				'tpei_too_big',
				sprintf(
					/* translators: %s: max size. */
					__( 'File is too large. Maximum import size is %s.', 'translatepress-export-import' ),
					size_format( self::max_size() )
				)
			);
		}
		$ext = strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'csv', 'txt', 'tsv', 'json' ), true ) ) {
			return new WP_Error( 'tpei_bad_ext', __( 'Unsupported file type. Please upload a CSV or JSON file exported by this plugin (or using the same columns).', 'translatepress-export-import' ) );
		}
		if ( ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return new WP_Error( 'tpei_upload', __( 'Invalid file upload.', 'translatepress-export-import' ) );
		}
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = wp_tempnam( 'tpei-import-' . $ext );
		if ( ! $tmp ) {
			return new WP_Error( 'tpei_io', __( 'Could not create a temporary file.', 'translatepress-export-import' ) );
		}
		// Keep the extension so format detection is reliable.
		$tmp_with_ext = $tmp . '.' . $ext;
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		if ( ! move_uploaded_file( (string) $file['tmp_name'], $tmp_with_ext ) ) {
			return new WP_Error( 'tpei_io', __( 'Could not store the uploaded file.', 'translatepress-export-import' ) );
		}
		return $tmp_with_ext;
	}

	/**
	 * Header synonyms → canonical column names.
	 *
	 * @return array Canonical => synonyms[].
	 */
	public static function column_synonyms() {
		return array(
			'id'         => array( 'id', 'row_id', 'string_id' ),
			'original'   => array( 'original', 'source', 'source_text', 'original_text', 'default_text', 'string', 'source_string' ),
			'translated' => array( 'translated', 'translation', 'target', 'target_text', 'translated_text', 'translation_text' ),
			'status'     => array( 'status', 'translation_status' ),
			'domain'     => array( 'domain', 'text_domain', 'context', 'gettext_domain' ),
		);
	}

	/**
	 * Map a raw header row to canonical column indexes.
	 *
	 * @param string[] $header Raw header cells.
	 * @return array Canonical name => index (only found columns).
	 */
	public static function map_header( $header ) {
		$map      = array();
		$synonyms = self::column_synonyms();
		foreach ( (array) $header as $i => $cell ) {
			$norm = strtolower( trim( (string) $cell ) );
			$norm = trim( $norm, "\xEF\xBB\xBF \t\n\r\0\x0B\"'" );
			foreach ( $synonyms as $canonical => $words ) {
				if ( in_array( $norm, $words, true ) && ! isset( $map[ $canonical ] ) ) {
					$map[ $canonical ] = $i;
				}
			}
		}
		return $map;
	}

	/**
	 * Auto-detect CSV delimiter from a sample.
	 *
	 * @param string $sample Raw file sample.
	 * @return string One of , ; \t |
	 */
	public static function detect_delimiter( $sample ) {
		$counts = array(
			','  => substr_count( $sample, ',' ),
			';'  => substr_count( $sample, ';' ),
			"\t" => substr_count( $sample, "\t" ),
			'|'  => substr_count( $sample, '|' ),
		);
		arsort( $counts );
		$best = key( $counts );
		return ( $counts[ $best ] > 0 ) ? $best : ',';
	}

	/**
	 * Import translations from a CSV/JSON file.
	 *
	 * @param string $file Full path to CSV/JSON file.
	 * @param array  $args Sanitized args (see sanitize_args()).
	 * @return array|WP_Error Stats array or error.
	 */
	public static function import_from_file( $file, $args ) {
		TPEI_Core::raise_limits();
		@ini_set( 'auto_detect_line_endings', '1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged

		if ( ! is_readable( $file ) ) {
			return new WP_Error( 'tpei_io', __( 'Import file is not readable.', 'translatepress-export-import' ) );
		}

		$ext = strtolower( pathinfo( (string) $file, PATHINFO_EXTENSION ) );
		if ( 'json' === $ext ) {
			return self::import_json_file( $file, $args );
		}
		return self::import_csv_file( $file, $args );
	}

	/**
	 * Resolve target table from args (+ file meta for auto-detection).
	 *
	 * @param array $args Sanitized args.
	 * @param array $meta Optional file meta (JSON "meta" block).
	 * @return array{table:string,columns:string[],type:string,language:string}|WP_Error
	 */
	protected static function resolve_target( $args, $meta = array() ) {
		$meta     = is_array( $meta ) ? $meta : array();
		$language = $args['language'];
		$source   = $args['source'];

		if ( 'auto' === $language && ! empty( $meta['language'] ) ) {
			$language = sanitize_text_field( $meta['language'] );
		}
		if ( 'auto' === $source && ! empty( $meta['source'] ) && in_array( $meta['source'], array( 'dictionary', 'gettext' ), true ) ) {
			$source = $meta['source'];
		}
		if ( 'auto' === $source ) {
			$source = 'dictionary';
		}

		if ( 'auto' === $language || '' === $language ) {
			// Try to guess from JSON meta table name, else require explicit selection.
			return new WP_Error( 'tpei_need_lang', __( 'Could not detect the target language. Please select it explicitly in the import form.', 'translatepress-export-import' ) );
		}

		$default = TPEI_Core::get_default_language();
		if ( 'gettext' === $source ) {
			$table = TPEI_Core::get_gettext_table( $language, $default );
		} else {
			$table = TPEI_Core::get_dictionary_table( $language, $default );
		}

		if ( ! TPEI_Core::table_exists( $table ) ) {
			return new WP_Error(
				'tpei_no_table',
				sprintf(
					/* translators: %s: table name. */
					__( 'Target TranslatePress table “%s” was not found. Check the selected language.', 'translatepress-export-import' ),
					$table
				)
			);
		}

		return array(
			'table'    => $table,
			'columns'  => TPEI_Core::get_table_columns( $table ),
			'type'     => $source,
			'language' => $language,
		);
	}

	/**
	 * Import a JSON file.
	 *
	 * @param string $file Full path.
	 * @param array  $args Sanitized args.
	 * @return array|WP_Error
	 */
	protected static function import_json_file( $file, $args ) {
		$raw  = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = json_decode( (string) $raw, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'tpei_bad_json', __( 'Invalid JSON file: could not decode it.', 'translatepress-export-import' ) );
		}
		$rows = isset( $data['rows'] ) && is_array( $data['rows'] ) ? $data['rows'] : $data;
		$meta = isset( $data['meta'] ) && is_array( $data['meta'] ) ? $data['meta'] : array();

		// Normalize: list of associative rows.
		$rows = array_values( array_filter( $rows, 'is_array' ) );
		if ( empty( $rows ) ) {
			return new WP_Error( 'tpei_empty', __( 'No rows found in the JSON file.', 'translatepress-export-import' ) );
		}

		$target = self::resolve_target( $args, $meta );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		// Derive header from first row keys.
		$header = array_keys( $rows[0] );
		$map    = self::map_header( $header );
		$check  = self::check_required_columns( $map );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$iter = function () use ( $rows, $header ) {
			foreach ( $rows as $assoc ) {
				$line = array();
				foreach ( $header as $key ) {
					$line[] = isset( $assoc[ $key ] ) ? (string) $assoc[ $key ] : '';
				}
				yield $line;
			}
		};

		return self::process_rows( $iter(), $map, $args, $target );
	}

	/**
	 * Import a CSV file (streamed, memory-safe).
	 *
	 * @param string $file Full path.
	 * @param array  $args Sanitized args.
	 * @return array|WP_Error
	 */
	protected static function import_csv_file( $file, $args ) {
		$delimiter = $args['delimiter'];
		if ( 'auto' === $delimiter ) {
			$sample = file_get_contents( $file, false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$ext    = strtolower( pathinfo( (string) $file, PATHINFO_EXTENSION ) );
			$delimiter = ( 'tsv' === $ext ) ? "\t" : self::detect_delimiter( (string) $sample );
		}

		$fh = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
		if ( ! $fh ) {
			return new WP_Error( 'tpei_io', __( 'Could not read the CSV file.', 'translatepress-export-import' ) );
		}

		$header = fgetcsv( $fh, 0, $delimiter, '"', '\\' );
		if ( ! is_array( $header ) || empty( array_filter( $header, 'strlen' ) ) ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
			return new WP_Error( 'tpei_bad_csv', __( 'Could not read the CSV header row. Check the delimiter and file encoding (UTF-8 expected).', 'translatepress-export-import' ) );
		}
		// Strip UTF-8 BOM from first cell.
		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );

		$map   = self::map_header( $header );
		$check = self::check_required_columns( $map );
		if ( is_wp_error( $check ) ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
			return $check;
		}

		$target = self::resolve_target( $args, array() );
		if ( is_wp_error( $target ) ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
			return $target;
		}

		$iter = function () use ( $fh, $delimiter ) {
			while ( ! feof( $fh ) ) {
				$line = fgetcsv( $fh, 0, $delimiter, '"', '\\' );
				if ( false === $line ) {
					break;
				}
				if ( null === $line ) {
					continue;
				}
				yield $line;
			}
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
		};

		$result = self::process_rows( $iter(), $map, $args, $target );
		if ( is_resource( $fh ) ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
		}
		return $result;
	}

	/**
	 * Ensure the file has the columns we need.
	 *
	 * @param array $map Header map.
	 * @return true|WP_Error
	 */
	protected static function check_required_columns( $map ) {
		if ( ! isset( $map['translated'] ) ) {
			return new WP_Error( 'tpei_no_col', __( 'Import file must contain a “translated” column (the translation). Use one of our export files or the CSV template.', 'translatepress-export-import' ) );
		}
		if ( ! isset( $map['original'] ) && ! isset( $map['id'] ) ) {
			return new WP_Error( 'tpei_no_col', __( 'Import file must contain an “original” column and/or an “id” column so rows can be matched.', 'translatepress-export-import' ) );
		}
		return true;
	}

	/**
	 * Preload existing rows for fast matching (avoids one query per row).
	 *
	 * @param array $target Resolved target info.
	 * @return array{by_id:array,by_original:array}|null Null when the table is too big (caller falls back to per-row queries).
	 */
	protected static function preload_map( $target ) {
		global $wpdb;
		$table = TPEI_Core::sanitize_identifier( $target['table'] );
		$cols  = $target['columns'];

		/**
		 * Filter the preload threshold (rows). Bigger tables use slower per-row lookups.
		 *
		 * @param int $threshold Default 60000.
		 */
		$threshold = (int) apply_filters( 'tpei_preload_threshold', 60000 );
		$total     = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $table . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $total > $threshold ) {
			return null;
		}

		$select = array( '`id`', '`original`', '`translated`' );
		if ( in_array( 'status', $cols, true ) ) {
			$select[] = '`status`';
		}
		if ( in_array( 'domain', $cols, true ) ) {
			$select[] = '`domain`';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( 'SELECT ' . implode( ', ', $select ) . ' FROM `' . $table . '`', ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array( 'by_id' => array(), 'by_original' => array() );
		}

		$by_id = array();
		$by_original = array();
		foreach ( $rows as $row ) {
			$id = (int) $row['id'];
			$by_id[ $id ] = $row;
			$key = md5( (string) $row['original'] );
			if ( ! isset( $by_original[ $key ] ) ) {
				$by_original[ $key ] = $row;
			}
		}
		return array( 'by_id' => $by_id, 'by_original' => $by_original );
	}

	/**
	 * Look up a single existing row (fallback for huge tables).
	 *
	 * @param array      $target   Resolved target info.
	 * @param int|string $id       Row id or 0.
	 * @param string     $original Original string.
	 * @return array|null Row or null.
	 */
	protected static function find_existing( $target, $id, $original ) {
		global $wpdb;
		$table = TPEI_Core::sanitize_identifier( $target['table'] );
		if ( $id > 0 && in_array( 'id', $target['columns'], true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . $table . '` WHERE `id` = %d LIMIT 1', $id ), ARRAY_A );
			if ( $row ) {
				return $row;
			}
		}
		if ( '' !== $original ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . $table . '` WHERE `original` = %s LIMIT 1', $original ), ARRAY_A );
			if ( $row ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Core import loop shared by CSV and JSON.
	 *
	 * @param iterable $lines  Each item: numeric array of cell values.
	 * @param array    $map    Header map (canonical => index).
	 * @param array    $args   Sanitized args.
	 * @param array    $target Resolved target info.
	 * @return array Stats.
	 */
	protected static function process_rows( $lines, $map, $args, $target ) {
		global $wpdb;
		$table      = TPEI_Core::sanitize_identifier( $target['table'] );
		$has_status = in_array( 'status', $target['columns'], true );
		$has_domain = in_array( 'domain', $target['columns'], true );
		$dry_run    = ! empty( $args['dry_run'] );

		$stats = array(
			'table'             => $table,
			'type'              => $target['type'],
			'language'          => $target['language'],
			'total'             => 0,
			'updated'           => 0,
			'inserted'          => 0,
			'skipped'           => 0,
			'skipped_protected' => 0,
			'skipped_unchanged' => 0,
			'skipped_empty'     => 0,
			'skipped_missing'   => 0,
			'errors'            => 0,
			'error_log'         => array(),
			'sample'            => array(),
			'dry_run'           => $dry_run,
			'match_by'          => $args['match_by'],
			'update_mode'       => $args['update_mode'],
			'new_status'        => $args['new_status'],
			'create_missing'    => ! empty( $args['create_missing'] ),
		);

		$preloaded = self::preload_map( $target );
		$use_preload = is_array( $preloaded );

		foreach ( $lines as $line_no => $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$id         = isset( $map['id'] ) && isset( $line[ $map['id'] ] ) ? (int) $line[ $map['id'] ] : 0;
			$original   = isset( $map['original'] ) && isset( $line[ $map['original'] ] ) ? (string) $line[ $map['original'] ] : '';
			$translated = isset( $map['translated'] ) && isset( $line[ $map['translated'] ] ) ? (string) $line[ $map['translated'] ] : '';
			$file_status = isset( $map['status'] ) && isset( $line[ $map['status'] ] ) ? trim( (string) $line[ $map['status'] ] ) : '';
			$domain     = isset( $map['domain'] ) && isset( $line[ $map['domain'] ] ) ? (string) $line[ $map['domain'] ] : '';

			// Skip completely blank lines.
			if ( '' === $original && '' === $translated && $id <= 0 ) {
				continue;
			}

			/**
			 * Filter a single import row before processing.
			 * Return false to skip the row, or an array with id/original/translated/status/domain keys to override.
			 *
			 * @param array|false $row  Row data (associative) or false to skip.
			 * @param array $raw Raw numeric line values.
			 * @param int $line_no Zero-based line number.
			 */
			$filtered = apply_filters(
				'tpei_import_row',
				array(
					'id'         => $id,
					'original'   => $original,
					'translated' => $translated,
					'status'     => $file_status,
					'domain'     => $domain,
				),
				$line,
				$line_no
			);
			if ( false === $filtered ) {
				$stats['skipped']++;
				continue;
			}
			if ( is_array( $filtered ) ) {
				$id          = isset( $filtered['id'] ) ? (int) $filtered['id'] : $id;
				$original    = isset( $filtered['original'] ) ? (string) $filtered['original'] : $original;
				$translated  = isset( $filtered['translated'] ) ? (string) $filtered['translated'] : $translated;
				$file_status = isset( $filtered['status'] ) ? trim( (string) $filtered['status'] ) : $file_status;
				$domain      = isset( $filtered['domain'] ) ? (string) $filtered['domain'] : $domain;
			}

			$stats['total']++;

			if ( '' === $original && $id <= 0 ) {
				$stats['errors']++;
				self::log_error( $stats, $line_no, __( 'Row has neither id nor original text; skipped.', 'translatepress-export-import' ) );
				continue;
			}

			// Find existing row.
			$existing = null;
			if ( $use_preload ) {
				if ( 'id' === $args['match_by'] && $id > 0 && isset( $preloaded['by_id'][ $id ] ) ) {
					$existing = $preloaded['by_id'][ $id ];
				} elseif ( '' !== $original ) {
					$key = md5( $original );
					if ( isset( $preloaded['by_original'][ $key ] ) ) {
						$existing = $preloaded['by_original'][ $key ];
					}
				}
			} else {
				$existing = self::find_existing( $target, ( 'id' === $args['match_by'] ? $id : 0 ), $original );
				// Fallback to the other key when the primary key misses.
				if ( ! $existing && 'id' === $args['match_by'] && '' !== $original ) {
					$existing = self::find_existing( $target, 0, $original );
				}
			}

			// Resolve the status to store.
			$new_status = null;
			if ( $has_status ) {
				if ( 'human' === $args['new_status'] ) {
					$new_status = TPEI_Core::STATUS_HUMAN;
				} elseif ( 'machine' === $args['new_status'] ) {
					$new_status = TPEI_Core::STATUS_MACHINE;
				} else {
					// "file": use the file value when valid, else auto.
					if ( '' !== $file_status && is_numeric( $file_status ) ) {
						$candidate = (int) $file_status;
						if ( $candidate >= 0 && $candidate <= 2 ) {
							$new_status = $candidate;
						}
					}
					if ( null === $new_status ) {
						$new_status = ( '' !== $translated ) ? TPEI_Core::STATUS_HUMAN : TPEI_Core::STATUS_NOT_TRANSLATED;
					}
					// Empty translation can never be "translated".
					if ( '' === $translated ) {
						$new_status = TPEI_Core::STATUS_NOT_TRANSLATED;
					}
				}
			}

			if ( $existing ) {
				$existing_translated = isset( $existing['translated'] ) ? (string) $existing['translated'] : '';
				$existing_status     = isset( $existing['status'] ) ? (int) $existing['status'] : 0;

				if ( '' === $translated && '' === $existing_translated ) {
					$stats['skipped']++;
					$stats['skipped_empty']++;
					self::add_sample( $stats, 'skipped_empty', $id, $original, $translated );
					continue;
				}
				if ( 'empty_only' === $args['update_mode'] && '' !== $existing_translated ) {
					$stats['skipped']++;
					$stats['skipped_protected']++;
					self::add_sample( $stats, 'skipped_protected', $id, $original, $translated );
					continue;
				}
				$data   = array( 'translated' => $translated );
				$format = array( '%s' );
				if ( $has_status && null !== $new_status ) {
					$data['status'] = $new_status;
					$format[]       = '%d';
				}
				if ( $has_domain && '' !== $domain && ( ! isset( $existing['domain'] ) || $existing['domain'] !== $domain ) ) {
					$data['domain'] = $domain;
					$format[]       = '%s';
				}
				// Nothing actually changed?
				if ( $existing_translated === $translated && ( ! $has_status || $existing_status === (int) $new_status ) ) {
					$stats['skipped']++;
					$stats['skipped_unchanged']++;
					self::add_sample( $stats, 'skipped_unchanged', $id, $original, $translated );
					continue;
				}

				if ( ! $dry_run ) {
					$ok = $wpdb->update( $table, $data, array( 'id' => (int) $existing['id'] ), $format, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					if ( false === $ok ) {
						$stats['errors']++;
						self::log_error( $stats, $line_no, $wpdb->last_error ? $wpdb->last_error : __( 'Database update failed.', 'translatepress-export-import' ) );
						continue;
					}
					// Keep the preload map fresh for duplicate rows inside the same file.
					if ( $use_preload ) {
						$eid = (int) $existing['id'];
						$preloaded['by_id'][ $eid ]['translated'] = $translated;
						if ( $has_status ) {
							$preloaded['by_id'][ $eid ]['status'] = (int) $new_status;
						}
						$okey = md5( (string) $existing['original'] );
						if ( isset( $preloaded['by_original'][ $okey ] ) ) {
							$preloaded['by_original'][ $okey ]['translated'] = $translated;
							if ( $has_status ) {
								$preloaded['by_original'][ $okey ]['status'] = (int) $new_status;
							}
						}
					}
				}
				$stats['updated']++;
				self::add_sample( $stats, 'updated', $id, $original, $translated );
			} else {
				// No existing row.
				if ( empty( $args['create_missing'] ) ) {
					$stats['skipped']++;
					$stats['skipped_missing']++;
					self::add_sample( $stats, 'skipped_missing', $id, $original, $translated );
					continue;
				}
				if ( '' === $original ) {
					$stats['errors']++;
					self::log_error( $stats, $line_no, __( 'Cannot create a row without original text.', 'translatepress-export-import' ) );
					continue;
				}
				if ( ! $dry_run ) {
					$data   = array(
						'original'   => $original,
						'translated' => $translated,
					);
					$format = array( '%s', '%s' );
					if ( $has_status ) {
						$data['status'] = null !== $new_status ? (int) $new_status : TPEI_Core::STATUS_NOT_TRANSLATED;
						$format[]       = '%d';
					}
					if ( $has_domain ) {
						$data['domain'] = $domain;
						$format[]       = '%s';
					}
					// Note: block_type and any other columns are intentionally left to DB defaults.
					$ok = $wpdb->insert( $table, $data, $format ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					if ( false === $ok ) {
						$stats['errors']++;
						self::log_error( $stats, $line_no, $wpdb->last_error ? $wpdb->last_error : __( 'Database insert failed.', 'translatepress-export-import' ) );
						continue;
					}
					if ( $use_preload ) {
						$new_id = (int) $wpdb->insert_id;
						$new_row = array(
							'id'         => $new_id,
							'original'   => $original,
							'translated' => $translated,
						);
						if ( $has_status ) {
							$new_row['status'] = (int) $data['status'];
						}
						$preloaded['by_id'][ $new_id ] = $new_row;
						$preloaded['by_original'][ md5( $original ) ] = $new_row;
					}
				}
				$stats['inserted']++;
				self::add_sample( $stats, 'inserted', $id, $original, $translated );
			}
		}

		if ( ! $dry_run && ( $stats['updated'] > 0 || $stats['inserted'] > 0 ) ) {
			TPEI_Core::flush_trp_caches();
		}

		/**
		 * Fires after an import finishes (not on dry-run errors before processing).
		 *
		 * @param array $stats Import statistics.
		 * @param array $args  Sanitized import args.
		 * @param array $target Resolved target info.
		 */
		do_action( 'tpei_after_import', $stats, $args, $target );

		return $stats;
	}

	/**
	 * Record an error (keeps the log bounded).
	 *
	 * @param array  $stats   Stats array (by reference).
	 * @param int    $line_no Zero-based line number.
	 * @param string $message Error message.
	 */
	protected static function log_error( &$stats, $line_no, $message ) {
		if ( count( $stats['error_log'] ) < 50 ) {
			$stats['error_log'][] = array(
				'line'    => (int) $line_no + 2, // + header row + 1-based.
				'message' => (string) $message,
			);
		}
	}

	/**
	 * Keep a small sample of processed rows for the report.
	 *
	 * @param array  $stats      Stats array (by reference).
	 * @param string $action     Action taken.
	 * @param int    $id         Row id.
	 * @param string $original   Original string.
	 * @param string $translated Translated string.
	 */
	protected static function add_sample( &$stats, $action, $id, $original, $translated ) {
		if ( count( $stats['sample'] ) >= 25 ) {
			return;
		}
		$stats['sample'][] = array(
			'action'     => $action,
			'id'         => (int) $id,
			'original'   => self::truncate( $original, 120 ),
			'translated' => self::truncate( $translated, 120 ),
		);
	}

	/**
	 * UTF-8 safe truncation (works with or without mbstring).
	 *
	 * @param string $text  Text.
	 * @param int    $chars Max characters.
	 * @return string
	 */
	protected static function truncate( $text, $chars ) {
		$text = (string) $text;
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, (int) $chars, 'UTF-8' );
		}
		// Fallback: byte-safe cut that avoids splitting a UTF-8 sequence.
		if ( strlen( $text ) <= $chars * 4 ) {
			$cut = substr( $text, 0, $chars * 4 );
			// Strip a trailing partial multibyte char.
			while ( '' !== $cut && ! preg_match( '//u', $cut ) ) {
				$cut = substr( $cut, 0, -1 );
			}
			return $cut;
		}
		return substr( $text, 0, (int) $chars );
	}

	/**
	 * Human label for a sample action.
	 *
	 * @param string $action Action key.
	 * @return string
	 */
	public static function sample_label( $action ) {
		$labels = array(
			'updated'           => __( 'Updated', 'translatepress-export-import' ),
			'inserted'          => __( 'Inserted', 'translatepress-export-import' ),
			'skipped_protected' => __( 'Skipped (already translated)', 'translatepress-export-import' ),
			'skipped_unchanged' => __( 'Skipped (unchanged)', 'translatepress-export-import' ),
			'skipped_empty'     => __( 'Skipped (empty)', 'translatepress-export-import' ),
			'skipped_missing'   => __( 'Skipped (not found)', 'translatepress-export-import' ),
		);
		return isset( $labels[ $action ] ) ? $labels[ $action ] : $action;
	}
}
