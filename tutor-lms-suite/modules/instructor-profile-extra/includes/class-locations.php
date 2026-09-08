<?php
/**
 * Algerian wilaya and commune dataset.
 *
 * @package TutorLmsInstructorProfileExtra
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Tlms_Ipe_Locations
 */
class Tlms_Ipe_Locations {

	/**
	 * Cached location map.
	 *
	 * @var array|null
	 */
	private static $map = null;

	/**
	 * Get wilayas keyed by code.
	 *
	 * @return array
	 */
	public static function get_map() {
		if ( null !== self::$map ) {
			return self::$map;
		}

		$path = TLMS_IPE_PATH . 'assets/data/algeria-cities.json';
		if ( ! file_exists( $path ) ) {
			self::$map = array();
			return self::$map;
		}

		$raw = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $raw ) ) {
			self::$map = array();
			return self::$map;
		}

		$map = array();
		foreach ( $raw as $row ) {
			if ( empty( $row['wilaya_code'] ) ) {
				continue;
			}

			$code = (string) $row['wilaya_code'];
			if ( ! isset( $map[ $code ] ) ) {
				$map[ $code ] = array(
					'code'     => $code,
					'name'     => isset( $row['wilaya_name_ascii'] ) ? $row['wilaya_name_ascii'] : '',
					'name_ar'  => isset( $row['wilaya_name'] ) ? $row['wilaya_name'] : '',
					'communes' => array(),
				);
			}

			$commune_id = isset( $row['id'] ) ? (string) $row['id'] : '';
			if ( '' === $commune_id ) {
				continue;
			}

			$map[ $code ]['communes'][ $commune_id ] = array(
				'id'      => $commune_id,
				'name'    => isset( $row['commune_name_ascii'] ) ? $row['commune_name_ascii'] : '',
				'name_ar' => isset( $row['commune_name'] ) ? $row['commune_name'] : '',
			);
		}

		ksort( $map, SORT_STRING );
		foreach ( $map as &$wilaya ) {
			uasort(
				$wilaya['communes'],
				static function ( $a, $b ) {
					return strcasecmp( $a['name'], $b['name'] );
				}
			);
		}
		unset( $wilaya );

		self::$map = $map;
		return self::$map;
	}

	/**
	 * Select options for wilayas.
	 *
	 * @return array
	 */
	public static function wilaya_options() {
		$options = array();
		foreach ( self::get_map() as $code => $wilaya ) {
			$options[] = array(
				'label' => self::format_label( $wilaya['name'], $wilaya['name_ar'], $code ),
				'value' => $code,
			);
		}
		return $options;
	}

	/**
	 * Commune options grouped by wilaya code.
	 *
	 * @return array
	 */
	public static function commune_options_map() {
		$map = array();
		foreach ( self::get_map() as $code => $wilaya ) {
			$map[ $code ] = array();
			foreach ( $wilaya['communes'] as $commune ) {
				$map[ $code ][] = array(
					'label' => self::format_label( $commune['name'], $commune['name_ar'] ),
					'value' => $commune['id'],
				);
			}
		}
		return $map;
	}

	/**
	 * Commune options for one wilaya.
	 *
	 * @param string $wilaya_code Wilaya code.
	 * @return array
	 */
	public static function commune_options( $wilaya_code ) {
		$map = self::commune_options_map();
		return isset( $map[ $wilaya_code ] ) ? $map[ $wilaya_code ] : array();
	}

	/**
	 * Human-readable wilaya name.
	 *
	 * @param string $code Wilaya code.
	 * @return string
	 */
	public static function get_wilaya_label( $code ) {
		$map = self::get_map();
		if ( ! isset( $map[ $code ] ) ) {
			return (string) $code;
		}
		return self::format_label( $map[ $code ]['name'], $map[ $code ]['name_ar'], $code );
	}

	/**
	 * Human-readable commune name.
	 *
	 * @param string $wilaya_code Wilaya code.
	 * @param string $commune_id  Commune id.
	 * @return string
	 */
	public static function get_commune_label( $wilaya_code, $commune_id ) {
		$map = self::get_map();
		if ( ! isset( $map[ $wilaya_code ]['communes'][ $commune_id ] ) ) {
			return (string) $commune_id;
		}
		$commune = $map[ $wilaya_code ]['communes'][ $commune_id ];
		return self::format_label( $commune['name'], $commune['name_ar'] );
	}

	/**
	 * Human-readable commune name, looked up by id alone (no wilaya needed).
	 *
	 * @param string $commune_id Commune id.
	 * @return string
	 */
	public static function get_commune_label_by_id( $commune_id ) {
		foreach ( self::get_map() as $wilaya ) {
			if ( isset( $wilaya['communes'][ $commune_id ] ) ) {
				$commune = $wilaya['communes'][ $commune_id ];
				return self::format_label( $commune['name'], $commune['name_ar'] );
			}
		}
		return (string) $commune_id;
	}

	/**
	 * Format bilingual label.
	 *
	 * @param string      $latin Latin / French name.
	 * @param string      $ar    Arabic name.
	 * @param string|null $code  Optional code prefix.
	 * @return string
	 */
	public static function format_label( $latin, $ar, $code = null ) {
		$label = trim( (string) $latin );
		if ( $ar && $ar !== $latin ) {
			$label .= ' — ' . $ar;
		}
		if ( null !== $code && '' !== $code ) {
			$label = $code . ' - ' . $label;
		}
		return $label;
	}
}
