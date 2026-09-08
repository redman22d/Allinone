<?php
/**
 * Audit trail for offline payment lifecycle events.
 *
 * @package TutorInstructorOfflinePayment
 */

namespace TutorInstructorOfflinePayment;

defined( 'ABSPATH' ) || exit;

class Audit {
	const TABLE = 'tioc_audit';

	public function register() {}

	public static function install() {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			event varchar(64) NOT NULL,
			details longtext NULL,
			ip varchar(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY created_at (created_at)
		) {$charset};" );
	}

	public static function log( $order_id, $event, $actor_id = 0, $details = array() ) {
		global $wpdb;
		$order_id = absint( $order_id );
		if ( ! $order_id ) {
			return;
		}
		$actor_id = $actor_id ? absint( $actor_id ) : get_current_user_id();
		$ip = self::hashed_ip();
		$wpdb->insert(
			$wpdb->prefix . self::TABLE,
			array(
				'order_id' => $order_id,
				'actor_id' => $actor_id,
				'event' => sanitize_key( $event ),
				'details' => wp_json_encode( is_array( $details ) ? $details : array( 'value' => $details ) ),
				'ip' => substr( $ip, 0, 64 ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Return a one-way, site-salted fingerprint of the direct client address.
	 * Raw IP addresses are never persisted in the audit trail.
	 *
	 * @return string
	 */
	private static function hashed_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( ! $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		return hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
	}

	public static function get( $order_id, $limit = 50 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}" . self::TABLE . ' WHERE order_id = %d ORDER BY id DESC LIMIT %d',
			absint( $order_id ),
			max( 1, min( 100, absint( $limit ) ) )
		) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	}
}
