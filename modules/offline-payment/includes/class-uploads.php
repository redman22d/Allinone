<?php
/**
 * Protected storage for payment receipts.
 *
 * @package TutorInstructorOfflinePayment
 */

namespace TutorInstructorOfflinePayment;

defined( 'ABSPATH' ) || exit;

/**
 * Receipts are financial documents that often show account numbers, so they are
 * deliberately kept out of the media library: files land in a dedicated uploads
 * subdirectory with a randomised name, direct web access is denied where the
 * server honours .htaccess, and every download is served through a capability
 * check.
 */
class Uploads {

	/**
	 * Directory name inside wp-content/uploads.
	 *
	 * @var string
	 */
	const DIR_NAME = 'tutor-offline-payments';

	/**
	 * admin-post.php action used to serve a receipt.
	 *
	 * @var string
	 */
	const SERVE_ACTION = 'tioc_view_proof';

	/**
	 * Query argument that triggers the front-end receipt endpoint.
	 *
	 * Receipts used to be served from admin-post.php, but Tutor LMS and most
	 * security plugins block non-administrators from /wp-admin/, so students
	 * and instructors were denied before this plugin could run its own check.
	 * The front-end endpoint runs the very same nonce and capability checks.
	 *
	 * @var string
	 */
	const SERVE_QUERY_ARG = 'tioc_receipt';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'maybe_serve_frontend' ), 5 );
		add_action( 'admin_init', array( $this, 'migrate_legacy_receipts_batch' ), 30 );
		add_action( 'admin_post_' . self::SERVE_ACTION, array( $this, 'serve' ) );
		add_action( 'admin_post_nopriv_' . self::SERVE_ACTION, array( $this, 'serve_denied' ) );
	}

	/**
	 * Handle a front-end receipt request, if this is one.
	 *
	 * @return void
	 */
	public function maybe_serve_frontend() {
		if ( empty( $_GET[ self::SERVE_QUERY_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in serve().
			return;
		}

		$this->serve();
	}


	/**
	 * Absolute path of the storage directory.
	 *
	 * @return string Trailing-slashed path.
	 */
	public static function base_dir() {
		$root = defined( 'TLS_PRIVATE_STORAGE_DIR' ) && is_string( TLS_PRIVATE_STORAGE_DIR )
			? rtrim( TLS_PRIVATE_STORAGE_DIR, '/\\' )
			: rtrim( dirname( rtrim( ABSPATH, '/\\' ) ), '/\\' ) . '/tls-private-data';
		$root = wp_normalize_path( $root );
		$root_real = realpath( $root );
		$root_check = $root_real ? wp_normalize_path( $root_real ) : trailingslashit( $root );
		$web_root = realpath( ABSPATH );
		$document_root = ! empty( $_SERVER['DOCUMENT_ROOT'] ) ? realpath( (string) $_SERVER['DOCUMENT_ROOT'] ) : false;
		// Fail closed: a directory outside ABSPATH may still be inside the actual
		// web document root when WordPress is installed in a subdirectory.
		if ( '' === $root || ( $web_root && 0 === stripos( trailingslashit( $root_check ), trailingslashit( wp_normalize_path( $web_root ) ) ) ) ) {
			return '';
		}
		if ( $document_root && 0 === stripos( trailingslashit( $root_check ), trailingslashit( wp_normalize_path( $document_root ) ) ) ) {
			return '';
		}
		return trailingslashit( $root ) . self::DIR_NAME . '/';
	}

	/** Legacy public uploads path used only for a controlled one-time migration. */
	private static function legacy_base_dir() {
		$uploads = wp_get_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . self::DIR_NAME . '/';
	}

	/**
	 * Create the storage directory and block direct access.
	 *
	 * Runs on activation and again lazily before the first upload, because a
	 * migration or a cleanup plugin can remove it at any time.
	 *
	 * @return bool
	 */
	public static function protect_directory() {
		$dir = self::base_dir();

		if ( ! $dir || ! wp_mkdir_p( $dir ) || ! is_writable( $dir ) ) {
			return false;
		}

		$htaccess = $dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "# Receipts are served through admin-post.php after a capability check.\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";

			file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$index = $dir . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		return true;
	}

	/**
	 * Validate and store an uploaded receipt.
	 *
	 * @param array $file One entry from $_FILES.
	 *
	 * @return array|\WP_Error {
	 *     @type string $file      Relative path below the storage directory.
	 *     @type string $name      Original filename, sanitised.
	 *     @type string $mime      Detected MIME type.
	 *     @type int    $size      Size in bytes.
	 * }
	 */
	public static function store( $file ) {
		if ( ! is_array( $file ) || ! isset( $file['tmp_name'] ) || '' === $file['tmp_name'] ) {
			return new \WP_Error( 'tioc_no_file', __( 'No file was received.', 'tutor-learning-suite' ) );
		}

		if ( isset( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new \WP_Error( 'tioc_upload_error', self::upload_error_message( (int) $file['error'] ) );
		}

		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new \WP_Error( 'tioc_not_uploaded', __( 'The file could not be verified as an upload.', 'tutor-learning-suite' ) );
		}

		$max = Settings::max_upload_bytes();
		if ( (int) $file['size'] > $max ) {
			return new \WP_Error(
				'tioc_too_large',
				sprintf(
					/* translators: %s: maximum file size, already formatted */
					__( 'That file is too large. The maximum is %s.', 'tutor-learning-suite' ),
					size_format( $max )
				)
			);
		}

		$name      = sanitize_file_name( isset( $file['name'] ) ? $file['name'] : 'receipt' );
		$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$allowed   = Settings::allowed_extensions();

		if ( ! $extension || ! in_array( $extension, $allowed, true ) ) {
			return new \WP_Error(
				'tioc_bad_type',
				sprintf(
					/* translators: %s: comma separated list of extensions */
					__( 'That file type is not accepted. Allowed types: %s.', 'tutor-learning-suite' ),
					implode( ', ', $allowed )
				)
			);
		}

		// Cross-check the real content type against the extension.
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $name );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
			return new \WP_Error( 'tioc_bad_content', __( 'The file contents do not match its extension.', 'tutor-learning-suite' ) );
		}

		if ( strtolower( $check['ext'] ) !== $extension ) {
			return new \WP_Error( 'tioc_bad_content', __( 'The file contents do not match its extension.', 'tutor-learning-suite' ) );
		}

		if ( ! self::protect_directory() ) {
			return new \WP_Error( 'tioc_no_dir', __( 'The receipt folder could not be created. Contact the site administrator.', 'tutor-learning-suite' ) );
		}

		$subdir = gmdate( 'Y/m' );
		$target = self::base_dir() . $subdir . '/';

		if ( ! wp_mkdir_p( $target ) ) {
			return new \WP_Error( 'tioc_no_dir', __( 'The receipt folder could not be created. Contact the site administrator.', 'tutor-learning-suite' ) );
		}

		// Randomised filename: the path is never guessable even if the server
		// ignores .htaccess.
		$filename = 'proof-' . gmdate( 'Ymd' ) . '-' . wp_generate_password( 20, false, false ) . '.' . $extension;

		if ( ! @move_uploaded_file( $file['tmp_name'], $target . $filename ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new \WP_Error( 'tioc_move_failed', __( 'The file could not be saved. Please try again.', 'tutor-learning-suite' ) );
		}

		@chmod( $target . $filename, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.chmod_chmod

		return array(
			'file' => $subdir . '/' . $filename,
			'name' => $name,
			'mime' => $check['type'],
			'size' => (int) $file['size'],
		);
	}

	/**
	 * Delete a stored receipt.
	 *
	 * @param string $relative_path Path as returned by store().
	 *
	 * @return bool
	 */
	public static function delete( $relative_path ) {
		$path = self::resolve( $relative_path );

		if ( ! $path || ! file_exists( $path ) ) {
			return false;
		}

		return (bool) wp_delete_file( $path ) || ! file_exists( $path );
	}

	/**
	 * Turn a stored relative path into a safe absolute path.
	 *
	 * Rejects anything that escapes the storage directory.
	 *
	 * @param string $relative_path Stored path.
	 *
	 * @return string|false
	 */
	public static function resolve( $relative_path ) {
		$relative_path = (string) $relative_path;

		if ( '' === $relative_path || false !== strpos( $relative_path, '..' ) || false !== strpos( $relative_path, "\0" ) ) {
			return false;
		}

		$relative_path = ltrim( str_replace( '\\', '/', $relative_path ), '/' );
		$base          = self::base_dir();
		if ( ! $base ) {
			return false;
		}
		$candidate = $base . $relative_path;
		$real_base = realpath( $base );
		$real_file = realpath( $candidate );

		// If a previous copy was interrupted after the private copy landed, remove
		// the remaining public copy only after confirming both files match.
		$legacy = self::legacy_base_dir();
		$legacy_file = realpath( $legacy . $relative_path );
		if ( $real_file && $legacy_file && is_file( $legacy_file ) && filesize( $real_file ) === filesize( $legacy_file ) ) {
			wp_delete_file( $legacy_file );
		}

		// Move old public-upload receipts into private storage on first access.
		if ( ! $real_file ) {
			$legacy = self::legacy_base_dir();
			$legacy_file = realpath( $legacy . $relative_path );
			$legacy_base = realpath( $legacy );
			if ( $legacy_file && $legacy_base && 0 === strpos( str_replace( '\\', '/', $legacy_file ), trailingslashit( str_replace( '\\', '/', $legacy_base ) ) ) && self::protect_directory() ) {
				$target = $base . $relative_path;
				$target_dir = dirname( $target );
				if ( wp_mkdir_p( $target_dir ) && ( file_exists( $target ) || @copy( $legacy_file, $target ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					@chmod( $target, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					if ( file_exists( $target ) && filesize( $target ) === filesize( $legacy_file ) ) {
						wp_delete_file( $legacy_file );
						$real_base = realpath( $base );
						$real_file = realpath( $target );
					}
				}
			}
		}

		if ( ! $real_base || ! $real_file ) {
			return false;
		}

		$real_base = trailingslashit( str_replace( '\\', '/', $real_base ) );
		$real_file = str_replace( '\\', '/', $real_file );

		if ( 0 !== strpos( $real_file, $real_base ) ) {
			return false;
		}

		return $real_file;
	}

	/** Batch-migrate old receipts out of public uploads; run in small admin batches. */
	public function migrate_legacy_receipts_batch() {
		if ( get_option( 'tioc_private_receipts_migrated' ) ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'tutor_ordermeta';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}
		$cursor = absint( get_option( 'tioc_private_receipts_cursor', 0 ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT order_id, meta_value FROM {$table} WHERE meta_key=%s AND order_id>%d ORDER BY order_id ASC LIMIT 50",
			'_tioc_proof', $cursor
		) );
		if ( empty( $rows ) ) {
			if ( 0 === (int) get_option( 'tioc_private_receipts_migration_failures', 0 ) ) {
				update_option( 'tioc_private_receipts_migrated', 1, false );
				delete_option( 'tioc_private_receipts_migration_needs_review' );
				delete_option( 'tioc_private_receipts_cursor' );
			} else {
				update_option( 'tioc_private_receipts_migration_needs_review', 1, false );
			}
			return;
		}
		$failures = (int) get_option( 'tioc_private_receipts_migration_failures', 0 );
		foreach ( $rows as $row ) {
			$proof = maybe_unserialize( $row->meta_value );
			if ( is_array( $proof ) && ! empty( $proof['file'] ) && ! self::resolve( $proof['file'] ) ) {
				$failures++;
			}
		}
		update_option( 'tioc_private_receipts_migration_failures', $failures, false );
		$last_id = max( array_map( 'absint', wp_list_pluck( $rows, 'order_id' ) ) );
		update_option( 'tioc_private_receipts_cursor', $last_id, false );
		if ( count( $rows ) < 50 && 0 === $failures ) {
			update_option( 'tioc_private_receipts_migrated', 1, false );
			delete_option( 'tioc_private_receipts_migration_needs_review' );
			delete_option( 'tioc_private_receipts_cursor' );
		} elseif ( count( $rows ) < 50 ) {
			update_option( 'tioc_private_receipts_migration_needs_review', 1, false );
		}
	}

	/**
	 * Download URL for an order's receipt.
	 *
	 * @param int  $order_id Order ID.
	 * @param bool $download Whether the link should force a download rather
	 *                       than open inline in the browser.
	 *
	 * @return string
	 */
	public static function proof_url( $order_id, $download = false ) {
		$order_id = absint( $order_id );
		$expires  = time() + ( max( 1, min( 1440, absint( Settings::get( 'receipt_link_minutes', 30 ) ) ) ) * MINUTE_IN_SECONDS );
		$args     = array(
			self::SERVE_QUERY_ARG => 1,
			'order_id'            => $order_id,
			'tioc_expires'        => $expires,
		);

		if ( $download ) {
			$args['tioc_download'] = 1;
		}

		$args['tioc_sig'] = hash_hmac( 'sha256', self::signature_payload( $order_id, $expires, $download ), wp_salt( 'auth' ) );

		// home_url(), not admin_url(): non-administrators are routinely blocked
		// from /wp-admin/ by Tutor LMS and security plugins.
		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * Build the signed payload for a receipt URL.
	 *
	 * @param int  $order_id Order ID.
	 * @param int  $expires  Unix timestamp.
	 * @param bool $download Whether the link forces a download.
	 *
	 * @return string
	 */
	private static function signature_payload( $order_id, $expires, $download ) {
		return absint( $order_id ) . '|' . absint( $expires ) . '|' . ( $download ? '1' : '0' );
	}

	/**
	 * Stream a receipt to an authorised viewer.
	 *
	 * @return void
	 */
	public function serve() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.

		if ( ! $order_id ) {
			wp_die( esc_html__( 'Missing order.', 'tutor-learning-suite' ), '', array( 'response' => 400 ) );
		}

		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}

		$expires  = isset( $_GET['tioc_expires'] ) ? absint( $_GET['tioc_expires'] ) : 0;
		$download = ! empty( $_GET['tioc_download'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- covered by the signature below.
		$signature = isset( $_GET['tioc_sig'] ) ? sanitize_text_field( wp_unslash( $_GET['tioc_sig'] ) ) : '';
		$expected  = hash_hmac( 'sha256', self::signature_payload( $order_id, $expires, $download ), wp_salt( 'auth' ) );

		if ( ! $expires || $expires < time() || ! hash_equals( $expected, $signature ) ) {
			wp_die(
				esc_html__( 'That receipt link has expired or is invalid. Please reload the payments page and try again.', 'tutor-learning-suite' ),
				'',
				array( 'response' => 403 )
			);
		}

		if ( ! Orders::can_view( $order_id, get_current_user_id() ) ) {
			$debug = '';

			// Deliberately narrow, even under WP_DEBUG: the full order row can
			// contain another student's billing details, so only the fields
			// needed to diagnose a permission mismatch are ever shown, and
			// only to someone who is logged in but was denied — never to a
			// logged-out visitor, who is redirected before reaching this code.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && current_user_can( 'manage_options' ) ) {
				$debug = '<pre style="text-align:left;max-width:700px;margin:20px auto;overflow:auto;font-size:12px;background:#f5f5f5;padding:12px;border:1px solid #ccc;">'
					. esc_html(
						'order_id: ' . $order_id . "\n"
						. 'current_user_id: ' . get_current_user_id() . "\n"
						. 'is_offline: ' . ( Orders::is_offline( $order_id ) ? 'yes' : 'no' ) . "\n"
						. 'instructor_id (from meta): ' . Orders::get_instructor_id( $order_id )
					)
					. '</pre>';
			}

			wp_die(
				esc_html__( 'You are not allowed to view this receipt.', 'tutor-learning-suite' ) . $debug, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $debug is pre-escaped above.
				'',
				array( 'response' => 403 )
			);
		}



		$proof = Orders::get_proof( $order_id );
		if ( empty( $proof['file'] ) ) {
			wp_die( esc_html__( 'No receipt is attached to this order.', 'tutor-learning-suite' ), '', array( 'response' => 404 ) );
		}

		$path = self::resolve( $proof['file'] );
		if ( ! $path || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'The receipt file is missing from the server.', 'tutor-learning-suite' ), '', array( 'response' => 404 ) );
		}

		$mime = ! empty( $proof['mime'] ) ? $proof['mime'] : 'application/octet-stream';
		$name = ! empty( $proof['name'] ) ? $proof['name'] : basename( $path );
		$mode = $download ? 'attachment' : 'inline';

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: ' . $mode . '; filename="' . rawurlencode( $name ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Security-Policy: default-src \'none\'; img-src \'self\' data:; object-src \'none\'; sandbox' );

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Logged-out visitors never have a legitimate reason to be here.
	 *
	 * @return void
	 */
	public function serve_denied() {
		auth_redirect();
	}

	/**
	 * Translate a PHP upload error code.
	 *
	 * @param int $code Error constant.
	 *
	 * @return string
	 */
	private static function upload_error_message( $code ) {
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return sprintf(
					/* translators: %s: server upload limit */
					__( 'That file exceeds the server upload limit of %s.', 'tutor-learning-suite' ),
					size_format( wp_max_upload_size() )
				);
			case UPLOAD_ERR_PARTIAL:
				return __( 'The upload was interrupted. Please try again.', 'tutor-learning-suite' );
			case UPLOAD_ERR_NO_FILE:
				return __( 'No file was selected.', 'tutor-learning-suite' );
			case UPLOAD_ERR_NO_TMP_DIR:
			case UPLOAD_ERR_CANT_WRITE:
				return __( 'The server could not write the file. Contact the site administrator.', 'tutor-learning-suite' );
			default:
				return __( 'The file could not be uploaded.', 'tutor-learning-suite' );
		}
	}
}
