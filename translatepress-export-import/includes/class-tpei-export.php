<?php
/**
 * Export TranslatePress tables to CSV / JSON.
 *
 * @package TranslatePress_Export_Import
 */

defined( 'ABSPATH' ) || exit;

class TPEI_Export {

	const BATCH = 2000;

	/**
	 * Register HTTP handler.
	 */
	public static function init() {
		add_action( 'admin_post_tpei_export', array( __CLASS__, 'handle_http_export' ) );
	}

	/**
	 * admin-post.php handler: validate + stream the file download.
	 */
	public static function handle_http_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to export translations.', 'translatepress-export-import' ), 403 );
		}
		check_admin_referer( 'tpei_export' );

		$args = self::sanitize_args( wp_unslash( $_POST ) );

		$check = self::validate( $args );
		if ( is_wp_error( $check ) ) {
			wp_die( esc_html( $check->get_error_message() ), esc_html__( 'Export error', 'translatepress-export-import' ), array( 'response' => 400 ) );
		}

		self::stream( $args );
		exit;
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
			'language'  => isset( $raw['language'] ) ? sanitize_text_field( $raw['language'] ) : '',
			'source'    => isset( $raw['source'] ) ? sanitize_key( $raw['source'] ) : 'dictionary',
			'filter'    => isset( $raw['filter'] ) ? sanitize_key( $raw['filter'] ) : 'all',
			'format'    => isset( $raw['format'] ) ? sanitize_key( $raw['format'] ) : 'csv',
			'delimiter' => isset( $raw['delimiter'] ) ? (string) $raw['delimiter'] : ',',
			'scope'     => isset( $raw['scope'] ) ? sanitize_key( $raw['scope'] ) : 'full',
			'search'    => isset( $raw['search'] ) ? sanitize_text_field( $raw['search'] ) : '',
		);
		if ( ! in_array( $args['source'], array( 'dictionary', 'gettext', 'slugs' ), true ) ) {
			$args['source'] = 'dictionary';
		}
		if ( ! array_key_exists( $args['filter'], TPEI_Core::get_filters() ) ) {
			$args['filter'] = 'all';
		}
		if ( ! in_array( $args['format'], array( 'csv', 'json' ), true ) ) {
			$args['format'] = 'csv';
		}
		$delimiters = self::allowed_delimiters();
		if ( ! isset( $delimiters[ $args['delimiter'] ] ) ) {
			$args['delimiter'] = ',';
		}
		if ( ! in_array( $args['scope'], array( 'full', 'translator' ), true ) ) {
			$args['scope'] = 'full';
		}
		return $args;
	}

	/**
	 * Allowed CSV delimiters (key => label).
	 *
	 * @return array
	 */
	public static function allowed_delimiters() {
		return array(
			','  => __( 'Comma (,)', 'translatepress-export-import' ),
			';'  => __( 'Semicolon (;) — best for Excel in many locales', 'translatepress-export-import' ),
			"\t" => __( 'Tab', 'translatepress-export-import' ),
			'|'  => __( 'Pipe (|)', 'translatepress-export-import' ),
		);
	}

	/**
	 * Resolve table + columns for the requested source.
	 *
	 * @param array $args Sanitized args.
	 * @return array{table:string,columns:string[],type:string}|WP_Error
	 */
	public static function resolve( $args ) {
		$default = TPEI_Core::get_default_language();

		if ( 'slugs' === $args['source'] ) {
			$slugs = TPEI_Core::get_slug_tables();
			$table = $slugs['translations'];
			if ( ! TPEI_Core::table_exists( $table ) ) {
				return new WP_Error( 'tpei_no_table', __( 'Slug translations table was not found. Your TranslatePress version may store slugs elsewhere (postmeta/termmeta).', 'translatepress-export-import' ) );
			}
			return array(
				'table'   => $table,
				'columns' => TPEI_Core::get_table_columns( $table ),
				'type'    => 'slugs',
			);
		}

		if ( '' === $args['language'] ) {
			return new WP_Error( 'tpei_no_lang', __( 'Please select a target language.', 'translatepress-export-import' ) );
		}

		if ( 'gettext' === $args['source'] ) {
			$table = TPEI_Core::get_gettext_table( $args['language'], $default );
		} else {
			$table = TPEI_Core::get_dictionary_table( $args['language'], $default );
		}

		if ( ! TPEI_Core::table_exists( $table ) ) {
			return new WP_Error(
				'tpei_no_table',
				sprintf(
					/* translators: %s: table name. */
					__( 'TranslatePress table “%s” was not found. Visit a translated page first so TranslatePress creates it, or check your languages.', 'translatepress-export-import' ),
					$table
				)
			);
		}

		$columns = TPEI_Core::get_table_columns( $table );
		if ( ! in_array( 'original', $columns, true ) || ! in_array( 'translated', $columns, true ) ) {
			return new WP_Error( 'tpei_bad_schema', __( 'Unexpected table structure: “original” / “translated” columns are missing.', 'translatepress-export-import' ) );
		}

		return array(
			'table'   => $table,
			'columns' => $columns,
			'type'    => $args['source'],
		);
	}

	/**
	 * Validate args before export.
	 *
	 * @param array $args Sanitized args.
	 * @return true|WP_Error
	 */
	public static function validate( $args ) {
		$resolved = self::resolve( $args );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		return true;
	}

	/**
	 * Decide which columns to export.
	 *
	 * - "full": every column in the table.
	 * - "translator": translator-friendly subset (id, original, translated + domain for gettext),
	 *   ideal for the "export originals → translate elsewhere → import back" workflow.
	 *
	 * @param array  $table_columns All columns in the table.
	 * @param string $type          dictionary|gettext|slugs.
	 * @param string $scope         full|translator.
	 * @return string[]
	 */
	public static function export_columns( $table_columns, $type, $scope ) {
		if ( 'translator' === $scope && 'slugs' !== $type ) {
			$wanted = array( 'id', 'original', 'translated' );
			if ( 'gettext' === $type ) {
				$wanted[] = 'domain';
			}
			// Keep file status when available so re-import can preserve it.
			if ( in_array( 'status', $table_columns, true ) ) {
				$wanted[] = 'status';
			}
			$cols = array_values( array_intersect( $wanted, $table_columns ) );
			if ( ! empty( $cols ) ) {
				return $cols;
			}
		}
		return array_values( $table_columns );
	}

	/**
	 * Stream the export as a file download (HTTP).
	 *
	 * @param array $args Sanitized args.
	 */
	public static function stream( $args ) {
		$resolved = self::resolve( $args );
		if ( is_wp_error( $resolved ) ) {
			wp_die( esc_html( $resolved->get_error_message() ) );
		}

		TPEI_Core::raise_limits();

		$default  = TPEI_Core::get_default_language();
		$filename = TPEI_Core::build_filename( $args['source'], $default, $args['language'], $args['format'] );
		$columns  = self::export_columns( $resolved['columns'], $resolved['type'], $args['scope'] );

		// Clean any previous output so the download is not corrupted.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		if ( ! headers_sent() ) {
			if ( 'json' === $args['format'] ) {
				header( 'Content-Type: application/json; charset=utf-8' );
			} else {
				header( 'Content-Type: text/csv; charset=utf-8' );
			}
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			header( 'Pragma: no-cache' );
			header( 'Expires: 0' );
		}
		$fh = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
		if ( 'json' === $args['format'] ) {
			self::write_json( $fh, $args, $resolved, $columns );
		} else {
			self::write_csv( $fh, $args, $resolved, $columns );
		}
	}

	/**
	 * Export to a file path (used by WP-CLI).
	 *
	 * @param array  $args Sanitized args.
	 * @param string $path Destination file path.
	 * @return array{file:string,rows:int}|WP_Error
	 */
	public static function export_to_file( $args, $path ) {
		$resolved = self::resolve( $args );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		TPEI_Core::raise_limits();
		$columns = self::export_columns( $resolved['columns'], $resolved['type'], $args['scope'] );
		$fh      = fopen( $path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
		if ( ! $fh ) {
			return new WP_Error( 'tpei_io', __( 'Could not open the destination file for writing.', 'translatepress-export-import' ) );
		}
		if ( 'json' === $args['format'] ) {
			$count = self::write_json( $fh, $args, $resolved, $columns );
		} else {
			$count = self::write_csv( $fh, $args, $resolved, $columns );
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
		return array( 'file' => $path, 'rows' => $count );
	}

	/**
	 * Build the SELECT query parts for the export.
	 *
	 * @param array $args     Sanitized args.
	 * @param array $resolved Resolved table info.
	 * @param array $columns  Columns to select.
	 * @return array{select:string,where:string,params:array}
	 */
	protected static function query_parts( $args, $resolved, $columns ) {
		$table = TPEI_Core::sanitize_identifier( $resolved['table'] );
		$sel   = array();
		foreach ( $columns as $col ) {
			$col = TPEI_Core::sanitize_identifier( $col );
			if ( '' !== $col ) {
				$sel[] = '`' . $col . '`';
			}
		}
		$select = implode( ', ', $sel );

		list( $filter_sql, $params ) = TPEI_Core::filter_where( $args['filter'], $resolved['columns'] );

		$wheres = array();
		if ( '' !== $filter_sql ) {
			$wheres[] = $filter_sql;
		}
		if ( '' !== $args['search'] && in_array( 'original', $resolved['columns'], true ) ) {
			global $wpdb;
			$wheres[] = '(`original` LIKE %s)';
			$params[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		}
		$where = $wheres ? 'WHERE ' . implode( ' AND ', $wheres ) : '';

		/**
		 * Filter the export query parts.
		 *
		 * @param array{select:string,where:string,params:array} $parts Query parts.
		 * @param array $args Sanitized export args.
		 * @param array $resolved Resolved table info.
		 */
		$parts = apply_filters( 'tpei_export_query_parts', array( 'select' => $select, 'where' => $where, 'params' => $params ), $args, $resolved );

		return $parts;
	}

	/**
	 * Fetch rows in batches; calls $callback(array $rows) per batch.
	 *
	 * @param array    $args     Sanitized args.
	 * @param array    $resolved Resolved table info.
	 * @param array    $columns  Columns to select.
	 * @param callable $callback Receives each batch of rows.
	 * @return int Total rows processed.
	 */
	public static function each_batch( $args, $resolved, $columns, $callback ) {
		global $wpdb;
		$table = TPEI_Core::sanitize_identifier( $resolved['table'] );
		$parts = self::query_parts( $args, $resolved, $columns );

		$order = in_array( 'id', $resolved['columns'], true ) ? 'ORDER BY `id` ASC' : '';
		$total = 0;
		$offset = 0;

		while ( true ) {
			$sql = "SELECT {$parts['select']} FROM `{$table}` {$parts['where']} {$order} LIMIT %d, %d";
			$params = array_merge( $parts['params'], array( $offset, self::BATCH ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( empty( $rows ) || ! is_array( $rows ) ) {
				break;
			}
			call_user_func( $callback, $rows );
			$total  += count( $rows );
			$offset += self::BATCH;
			if ( count( $rows ) < self::BATCH ) {
				break;
			}
		}
		return $total;
	}

	/**
	 * Write CSV to an open handle.
	 *
	 * @param resource $fh       Open writable handle.
	 * @param array    $args     Sanitized args.
	 * @param array    $resolved Resolved table info.
	 * @param array    $columns  Columns to export.
	 * @return int Row count.
	 */
	protected static function write_csv( $fh, $args, $resolved, $columns ) {
		$delimiter = $args['delimiter'];
		// UTF-8 BOM so Excel opens the file with correct encoding.
		fwrite( $fh, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fwrite
		fputcsv( $fh, $columns, $delimiter );

		return self::each_batch(
			$args,
			$resolved,
			$columns,
			function ( $rows ) use ( $fh, $delimiter, $columns, $args, $resolved ) {
				foreach ( $rows as $row ) {
					/**
					 * Filter a single export row (associative, keyed by column name).
					 *
					 * @param array $row Row data.
					 * @param array $args Sanitized export args.
					 * @param array $resolved Resolved table info.
					 */
					$row = apply_filters( 'tpei_export_row', $row, $args, $resolved );
					$line = array();
					foreach ( $columns as $col ) {
						$line[] = isset( $row[ $col ] ) ? (string) $row[ $col ] : '';
					}
					fputcsv( $fh, $line, $delimiter );
				}
			}
		);
	}

	/**
	 * Write JSON to an open handle (streamed, memory-safe).
	 *
	 * @param resource $fh       Open writable handle.
	 * @param array    $args     Sanitized args.
	 * @param array    $resolved Resolved table info.
	 * @param array    $columns  Columns to export.
	 * @return int Row count.
	 */
	protected static function write_json( $fh, $args, $resolved, $columns ) {
		$meta = array(
			'generator'        => 'translatepress-export-import/' . TPEI_VERSION,
			'site'             => home_url(),
			'exported_at'      => gmdate( 'c' ),
			'source'           => $resolved['type'],
			'table'            => $resolved['table'],
			'default_language' => TPEI_Core::get_default_language(),
			'language'         => $args['language'],
			'filter'           => $args['filter'],
			'scope'            => $args['scope'],
			'columns'          => $columns,
		);
		fwrite( $fh, '{"meta":' . wp_json_encode( $meta ) . ',"rows":[' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fwrite

		$first = true;
		$count = self::each_batch(
			$args,
			$resolved,
			$columns,
			function ( $rows ) use ( $fh, $columns, $args, $resolved, &$first ) {
				foreach ( $rows as $row ) {
					$row = apply_filters( 'tpei_export_row', $row, $args, $resolved );
					$out = array();
					foreach ( $columns as $col ) {
						$out[ $col ] = isset( $row[ $col ] ) ? (string) $row[ $col ] : '';
					}
					fwrite( $fh, ( $first ? '' : ',' ) . wp_json_encode( $out ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fwrite
					$first = false;
				}
			}
		);
		fwrite( $fh, ']}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fwrite
		return $count;
	}
}
