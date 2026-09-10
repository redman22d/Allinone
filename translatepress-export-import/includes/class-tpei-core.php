<?php
/**
 * Core helpers: TranslatePress detection, table resolution, counts.
 *
 * @package TranslatePress_Export_Import
 */

defined( 'ABSPATH' ) || exit;

class TPEI_Core {

	const STATUS_NOT_TRANSLATED = 0;
	const STATUS_MACHINE        = 1;
	const STATUS_HUMAN          = 2;

	/**
	 * Is TranslatePress (free or premium) active?
	 *
	 * @return bool
	 */
	public static function is_trp_active() {
		if ( class_exists( 'TRP_Translate_Press', false ) ) {
			return true;
		}
		if ( function_exists( 'trp_translate' ) ) {
			return true;
		}
		$settings = get_option( 'trp_settings', false );
		return ( false !== $settings );
	}

	/**
	 * Raw TranslatePress settings array (or empty array).
	 *
	 * @return array
	 */
	public static function get_trp_settings() {
		$settings = get_option( 'trp_settings', array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Default language code, e.g. "en_US".
	 *
	 * @return string
	 */
	public static function get_default_language() {
		$settings = self::get_trp_settings();
		if ( ! empty( $settings['default-language'] ) && is_string( $settings['default-language'] ) ) {
			return trim( $settings['default-language'] );
		}
		return '';
	}

	/**
	 * Secondary (translation) language codes, excluding the default language.
	 *
	 * @return string[]
	 */
	public static function get_translation_languages() {
		$settings = self::get_trp_settings();
		$default  = self::get_default_language();
		$langs    = array();

		if ( ! empty( $settings['translation-languages'] ) && is_array( $settings['translation-languages'] ) ) {
			foreach ( $settings['translation-languages'] as $code ) {
				if ( ! is_string( $code ) ) {
					continue;
				}
				$code = trim( $code );
				if ( '' === $code || $code === $default ) {
					continue;
				}
				$langs[] = $code;
			}
		}

		// Fallback: discover languages from existing dictionary tables.
		if ( empty( $langs ) ) {
			foreach ( self::discover_dictionary_tables() as $found ) {
				if ( ! empty( $found['language'] ) && ! in_array( $found['language'], $langs, true ) ) {
					$langs[] = $found['language'];
				}
			}
		}

		return array_values( array_unique( $langs ) );
	}

	/**
	 * Human readable language label. Uses TranslatePress API when available.
	 *
	 * @param string $code Language code like "fr_FR".
	 * @return string
	 */
	public static function pretty_lang( $code ) {
		$code = trim( (string) $code );

		if ( class_exists( 'TRP_Languages', false ) ) {
			try {
				if ( method_exists( 'TRP_Languages', 'get_language_names' ) ) {
					$names = TRP_Languages::get_language_names( array( $code ) );
					if ( is_array( $names ) && ! empty( $names[ $code ] ) ) {
						return $names[ $code ] . ' (' . $code . ')';
					}
				}
			} catch ( Throwable $e ) { // phpcs:ignore
			}
		}

		if ( '' === $code ) {
			return '';
		}
		return str_replace( '_', '-', strtolower( $code ) ) . ' (' . $code . ')';
	}

	/**
	 * Translation status label.
	 *
	 * @param mixed $status Status value.
	 * @return string
	 */
	public static function status_label( $status ) {
		$status = (int) $status;
		switch ( $status ) {
			case self::STATUS_NOT_TRANSLATED:
				return __( 'Not translated', 'translatepress-export-import' );
			case self::STATUS_MACHINE:
				return __( 'Machine translated', 'translatepress-export-import' );
			case self::STATUS_HUMAN:
				return __( 'Human reviewed', 'translatepress-export-import' );
			default:
				/* translators: %d: unknown status code. */
				return sprintf( __( 'Unknown (%d)', 'translatepress-export-import' ), $status );
		}
	}

	/**
	 * Keep only safe characters for a table/column identifier.
	 *
	 * @param string $name Identifier.
	 * @return string
	 */
	public static function sanitize_identifier( $name ) {
		return preg_replace( '/[^A-Za-z0-9_]/', '', (string) $name );
	}

	/**
	 * Does a table exist?
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	public static function table_exists( $table ) {
		global $wpdb;
		$table = self::sanitize_identifier( $table );
		if ( '' === $table ) {
			return false;
		}
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return ( $found === $table );
	}

	/**
	 * Column names of a table (via DESCRIBE). Empty array if table is missing.
	 *
	 * @param string $table Full table name.
	 * @return string[]
	 */
	public static function get_table_columns( $table ) {
		global $wpdb;
		$table = self::sanitize_identifier( $table );
		if ( '' === $table ) {
			return array();
		}
		$rows = $wpdb->get_results( 'DESCRIBE `' . $table . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$cols = array();
		foreach ( $rows as $row ) {
			if ( isset( $row['Field'] ) ) {
				$cols[] = $row['Field'];
			}
		}
		return $cols;
	}

	/**
	 * Resolve the dictionary table for a target language.
	 * Prefers the official TranslatePress API, falls back to naming convention.
	 *
	 * @param string      $language Target language code, e.g. "fr_FR".
	 * @param string|null $default  Default language code or null to auto-detect.
	 * @return string Full table name (may not exist yet — check with table_exists()).
	 */
	public static function get_dictionary_table( $language, $default = null ) {
		// 1. Official API.
		if ( class_exists( 'TRP_Translate_Press', false ) ) {
			try {
				$trp = TRP_Translate_Press::get_trp_instance();
				if ( $trp && method_exists( $trp, 'get_component' ) ) {
					$query = $trp->get_component( 'query' );
					if ( $query && is_callable( array( $query, 'get_table_name' ) ) ) {
						$table = call_user_func( array( $query, 'get_table_name' ), $language );
						if ( is_string( $table ) && '' !== $table ) {
							return $table;
						}
					}
				}
			} catch ( Throwable $e ) { // phpcs:ignore
			}
		}

		// 2. Naming convention: {prefix}trp_dictionary_{default}_{language} (lowercase).
		global $wpdb;
		if ( null === $default ) {
			$default = self::get_default_language();
		}
		$suffix    = 'trp_dictionary_' . strtolower( trim( (string) $default ) ) . '_' . strtolower( trim( (string) $language ) );
		$candidate = self::sanitize_identifier( $wpdb->get_blog_prefix() . $suffix );
		if ( self::table_exists( $candidate ) ) {
			return $candidate;
		}
		$fallback = self::sanitize_identifier( $wpdb->prefix . $suffix );
		if ( $fallback !== $candidate && self::table_exists( $fallback ) ) {
			return $fallback;
		}
		return $candidate;
	}

	/**
	 * Resolve the gettext table for a target language.
	 *
	 * @param string      $language Target language code.
	 * @param string|null $default  Default language code or null to auto-detect.
	 * @return string Full table name (may not exist — check with table_exists()).
	 */
	public static function get_gettext_table( $language, $default = null ) {
		// 1. Official API (method visibility differs between versions — check callability).
		if ( class_exists( 'TRP_Translate_Press', false ) ) {
			try {
				$trp = TRP_Translate_Press::get_trp_instance();
				if ( $trp && method_exists( $trp, 'get_component' ) ) {
					$query = $trp->get_component( 'query' );
					if ( $query && is_callable( array( $query, 'get_gettext_table_name' ) ) ) {
						$table = call_user_func( array( $query, 'get_gettext_table_name' ), $language );
						if ( is_string( $table ) && '' !== $table ) {
							return $table;
						}
					}
				}
			} catch ( Throwable $e ) { // phpcs:ignore
			}
		}

		// 2. Known naming patterns across TranslatePress versions.
		global $wpdb;
		if ( null === $default ) {
			$default = self::get_default_language();
		}
		$d  = strtolower( trim( (string) $default ) );
		$l  = strtolower( trim( (string) $language ) );
		$guesses = array(
			$wpdb->get_blog_prefix() . 'trp_gettext_' . $d . '_' . $l,
			$wpdb->prefix . 'trp_gettext_' . $d . '_' . $l,
			$wpdb->get_blog_prefix() . 'trp_gettext_' . $l,
			$wpdb->prefix . 'trp_gettext_' . $l,
		);
		foreach ( $guesses as $guess ) {
			$guess = self::sanitize_identifier( $guess );
			if ( '' !== $guess && self::table_exists( $guess ) ) {
				return $guess;
			}
		}
		return self::sanitize_identifier( $guesses[0] );
	}

	/**
	 * Slug tables (TranslatePress 2.8.4+). Single tables shared by all languages.
	 *
	 * @return array{originals:string,translations:string}
	 */
	public static function get_slug_tables() {
		global $wpdb;
		return array(
			'originals'    => self::sanitize_identifier( $wpdb->get_blog_prefix() . 'trp_slug_originals' ),
			'translations' => self::sanitize_identifier( $wpdb->get_blog_prefix() . 'trp_slug_translations' ),
		);
	}

	/**
	 * Discover existing dictionary tables by scanning the database.
	 * Used as a fallback when TranslatePress settings are unavailable.
	 *
	 * @return array[] Each item: array( 'table' => string, 'default' => string, 'language' => string ).
	 */
	public static function discover_dictionary_tables() {
		global $wpdb;
		$found  = array();
		$like   = $wpdb->esc_like( $wpdb->get_blog_prefix() . 'trp_dictionary_' ) . '%';
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! is_array( $tables ) ) {
			return $found;
		}
		$default = strtolower( self::get_default_language() );
		foreach ( $tables as $table ) {
			$short = substr( (string) $table, strlen( $wpdb->get_blog_prefix() . 'trp_dictionary_' ) );
			$item  = array(
				'table'    => $table,
				'default'  => '',
				'language' => $short,
			);
			if ( '' !== $default && 0 === strpos( $short, $default . '_' ) ) {
				$item['default']  = $default;
				$item['language'] = substr( $short, strlen( $default ) + 1 );
			}
			$found[] = $item;
		}
		return $found;
	}

	/**
	 * Quick counts for a dictionary/gettext table.
	 *
	 * @param string $table Full table name.
	 * @return array{exists:bool,total:int,translated:int,untranslated:int,machine:int,human:int}
	 */
	public static function get_counts( $table ) {
		global $wpdb;
		$stats = array(
			'exists'       => false,
			'total'        => 0,
			'translated'   => 0,
			'untranslated' => 0,
			'machine'      => 0,
			'human'        => 0,
		);
		$table = self::sanitize_identifier( $table );
		if ( '' === $table || ! self::table_exists( $table ) ) {
			return $stats;
		}
		$stats['exists'] = true;
		$cols            = self::get_table_columns( $table );
		$has_translated  = in_array( 'translated', $cols, true );
		$has_status      = in_array( 'status', $cols, true );

		$stats['total'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $table . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $has_translated ) {
			$stats['translated']   = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $table . '` WHERE `translated` IS NOT NULL AND `translated` <> \'\'' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$stats['untranslated'] = max( 0, $stats['total'] - $stats['translated'] );
		} else {
			$stats['untranslated'] = $stats['total'];
		}
		if ( $has_status ) {
			$stats['machine'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $table . '` WHERE `status` = %d', self::STATUS_MACHINE ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$stats['human']   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $table . '` WHERE `status` = %d', self::STATUS_HUMAN ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return $stats;
	}

	/**
	 * Build a safe export filename.
	 *
	 * @param string $source  dictionary|gettext|slugs.
	 * @param string $default Default language code.
	 * @param string $lang    Target language code.
	 * @param string $ext     File extension without dot.
	 * @return string
	 */
	public static function build_filename( $source, $default, $lang, $ext ) {
		$name = sprintf(
			'translatepress-%s-%s-to-%s-%s.%s',
			preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $source ) ),
			preg_replace( '/[^a-z0-9]+/', '', strtolower( (string) $default ) ),
			preg_replace( '/[^a-z0-9]+/', '', strtolower( (string) $lang ) ),
			gmdate( 'Y-m-d-Hi' ),
			preg_replace( '/[^a-z0-9]+/', '', strtolower( (string) $ext ) )
		);
		return sanitize_file_name( $name );
	}

	/**
	 * Allowed export/import filters.
	 *
	 * @return array
	 */
	public static function get_filters() {
		return array(
			'all'          => __( 'All strings', 'translatepress-export-import' ),
			'untranslated' => __( 'Only untranslated', 'translatepress-export-import' ),
			'translated'   => __( 'Only translated', 'translatepress-export-import' ),
			'machine'      => __( 'Only machine translated', 'translatepress-export-import' ),
			'human'        => __( 'Only human reviewed', 'translatepress-export-import' ),
		);
	}

	/**
	 * WHERE clause + params for an export filter.
	 *
	 * @param string $filter  Filter key.
	 * @param array  $columns Available columns in the table.
	 * @return array{0:string,1:array} SQL fragment (without WHERE) and $wpdb->prepare() params.
	 */
	public static function filter_where( $filter, $columns ) {
		$has_translated = in_array( 'translated', $columns, true );
		$has_status     = in_array( 'status', $columns, true );

		switch ( $filter ) {
			case 'untranslated':
				if ( $has_translated && $has_status ) {
					return array( '(`translated` IS NULL OR `translated` = \'\') AND (`status` = %d)', array( self::STATUS_NOT_TRANSLATED ) );
				}
				if ( $has_translated ) {
					return array( '(`translated` IS NULL OR `translated` = \'\')', array() );
				}
				if ( $has_status ) {
					return array( '(`status` = %d)', array( self::STATUS_NOT_TRANSLATED ) );
				}
				return array( '', array() );

			case 'translated':
				if ( $has_translated ) {
					return array( '(`translated` IS NOT NULL AND `translated` <> \'\')', array() );
				}
				if ( $has_status ) {
					return array( '(`status` <> %d)', array( self::STATUS_NOT_TRANSLATED ) );
				}
				return array( '', array() );

			case 'machine':
				if ( $has_status ) {
					return array( '(`status` = %d)', array( self::STATUS_MACHINE ) );
				}
				return array( '', array() );

			case 'human':
				if ( $has_status ) {
					return array( '(`status` = %d)', array( self::STATUS_HUMAN ) );
				}
				return array( '', array() );

			case 'all':
			default:
				return array( '', array() );
		}
	}

	/**
	 * Raise limits for big exports/imports (best effort, shared-host safe).
	 */
	public static function raise_limits() {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions.set_time_limitDeprecated
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
		}
	}

	/**
	 * Clear TranslatePress-related transients/caches after an import.
	 */
	public static function flush_trp_caches() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			"DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE '_transient_trp%' OR `option_name` LIKE '_transient_timeout_trp%'"
		);
		if ( is_multisite() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				"DELETE FROM `{$wpdb->sitemeta}` WHERE `meta_key` LIKE '_site_transient_trp%' OR `meta_key` LIKE '_site_transient_timeout_trp%'"
			);
		}
		wp_cache_flush();
	}
}
