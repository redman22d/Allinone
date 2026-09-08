<?php
/**
 * Cross-language search bridge for TranslatePress.
 *
 * TranslatePress does NOT duplicate posts per language: a course still has
 * exactly one row in wp_posts (in the site's default language), and every
 * translation lives in TranslatePress' own dictionary tables, applied to the
 * page output at render time. That means the plain LIKE search TCSP runs
 * against wp_posts.post_title / post_content only ever matches the default
 * language — a visitor typing a French word will find nothing even though
 * the course clearly *displays* in French.
 *
 * This class closes that gap by additionally scanning TranslatePress'
 * dictionary tables for the search term, in every language pair at once, and
 * mapping each hit back to either:
 *   - a course ID (when the translated string belongs to a single post), or
 *   - a taxonomy term (when the translated string is a category/tag name).
 *
 * Those extra course IDs / term IDs are then OR'd into TCSP's existing
 * search clause, so one search box finds a course regardless of which
 * language its matching text is stored/translated in.
 *
 * @package Tutor_Course_Search_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCSP_Multilingual {

	const CACHE_GROUP = 'tcsp';
	const TABLE_CACHE_TTL = 300; // 5 minutes; table list rarely changes.

	/**
	 * Whether TranslatePress is active on this site.
	 */
	public static function trp_active() {
		return class_exists( 'TRP_Translate_Press' ) || function_exists( 'trp_translate' );
	}

	/**
	 * Find the TranslatePress dictionary tables that exist in this database.
	 * One table exists per (default-language -> target-language) pair, e.g.
	 * wp_trp_dictionary_en_us_fr_fr.
	 *
	 * @return string[] Fully-qualified (prefixed) table names.
	 */
	private static function dictionary_tables() {
		global $wpdb;

		$cached = wp_cache_get( 'trp_dictionary_tables', self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$like = $wpdb->esc_like( $wpdb->prefix . 'trp_dictionary_' ) . '%';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SHOW TABLES doesn't take normal placeholders for LIKE via wpdb->prepare consistently across versions; %s escaping below is sufficient since this is a pattern, not user input.
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		$tables = is_array( $tables ) ? array_map( 'strval', $tables ) : array();

		wp_cache_set( 'trp_dictionary_tables', $tables, self::CACHE_GROUP, self::TABLE_CACHE_TTL );

		return $tables;
	}

	/**
	 * Whether TranslatePress' original-strings/meta tables are present.
	 */
	private static function has_core_tables() {
		global $wpdb;
		$orig_table = $wpdb->prefix . 'trp_original_strings';
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orig_table ) ) === $orig_table;
	}

	/**
	 * Search every TranslatePress dictionary (i.e. every translated language)
	 * for a term, and resolve the hits into course IDs and taxonomy term IDs.
	 *
	 * @param string $search Raw (unescaped) search string as typed by the visitor.
	 * @return array{post_ids: int[], term_ids: int[]}
	 */
	public static function cross_language_matches( $search ) {
		global $wpdb;

		$result = array(
			'post_ids' => array(),
			'term_ids' => array(),
		);

		$search = trim( wp_strip_all_tags( (string) $search ) );
		if ( strlen( $search ) < 2 || ! self::trp_active() || ! self::has_core_tables() ) {
			return $result;
		}

		$dictionaries = self::dictionary_tables();
		if ( empty( $dictionaries ) ) {
			return $result;
		}

		$cache_key = 'trp_match_' . md5( $search );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$like        = '%' . $wpdb->esc_like( $search ) . '%';
		$orig_table  = $wpdb->prefix . 'trp_original_strings';
		$meta_table  = $wpdb->prefix . 'trp_original_meta';
		$loose_names = array();

		foreach ( $dictionaries as $dict_table ) {
			// Table names come from SHOW TABLES against our own prefix, not
			// from user input, so they're safe to interpolate directly.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT d.original_id AS original_id, m.meta_value AS post_id, o.original AS original_text
					 FROM `{$dict_table}` d
					 INNER JOIN `{$orig_table}` o ON o.id = d.original_id
					 LEFT JOIN `{$meta_table}` m ON m.original_id = d.original_id AND m.meta_key = %s
					 WHERE d.translated LIKE %s
					 LIMIT 500",
					'post_parent_id',
					$like
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			foreach ( (array) $rows as $row ) {
				if ( ! empty( $row->post_id ) ) {
					$result['post_ids'][] = (int) $row->post_id;
				} elseif ( ! empty( $row->original_text ) ) {
					// Not tied to a single post — likely a taxonomy term name
					// (or other loose string) translated on an archive/loop.
					// Its default-language text is our route back to a term.
					$loose_names[] = (string) $row->original_text;
				}
			}
		}

		if ( ! empty( $loose_names ) ) {
			$loose_names = array_values( array_unique( $loose_names ) );
			foreach ( $loose_names as $name ) {
				$terms = get_terms(
					array(
						'taxonomy'   => array( 'course-category', 'course-tag' ),
						'hide_empty' => false,
						'name'       => $name,
						'fields'     => 'ids',
					)
				);
				if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
					$result['term_ids'] = array_merge( $result['term_ids'], array_map( 'intval', $terms ) );
				}
			}
		}

		$result['post_ids'] = array_values( array_unique( array_filter( array_map( 'absint', $result['post_ids'] ) ) ) );
		$result['term_ids'] = array_values( array_unique( array_filter( array_map( 'absint', $result['term_ids'] ) ) ) );

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, 60 );

		return $result;
	}
}
