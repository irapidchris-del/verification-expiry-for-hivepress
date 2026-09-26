<?php
/**
 * Storage component: where documents live, how they are served, and every way a URL could leak.
 *
 * THE MODEL. Core's uploader writes every file into the public media library and links straight to
 * wp_get_attachment_url(); its "protected" option only randomises the file name
 * (components/class-attachment.php:162-170, core 1.7.31). A deny rule inside uploads was measured
 * by the gallery plugin as skipped by a proxy for any request matching a real file, and nginx
 * ignores .htaccess entirely. So: the capability-checked route in serve() IS the protection; the
 * directory outside the published folder is the second line; the deny rule is the third.
 *
 * Deletion is the plugin's job in external mode. WordPress deletes the main file with
 * wp_delete_file_from_directory( $file, $uploads['basedir'] ) (wp-includes/post.php,
 * wp_delete_attachment_files(), last statement) and that helper returns false for any real path
 * outside uploads (wp-includes/functions.php, wp_delete_file_from_directory()), so without
 * delete_file() below an identity document would stay on disk after its row was deleted.
 *
 * @package HivePress\Verification_Expiry
 */

namespace HivePress\Components;

use HivePress\Helpers as hp;
use HivePress\Models;
use Verification_Expiry\Logic\Hpve_Path;
use Verification_Expiry\Logic\Hpve_Document_Types as Doc_Types;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Private document storage.
 *
 * @class Hpve_Storage
 */
final class Hpve_Storage extends Component {

	/**
	 * Attachment meta marking a private document. The cheap test every filter makes.
	 */
	const META_PRIVATE = 'hp_hpve_private';

	/**
	 * Attachment meta holding the size in bytes at upload.
	 */
	const META_SIZE = 'hp_hpve_doc_size';

	/**
	 * Reviewer view lines are coalesced for this long.
	 */
	const VIEW_LOG_TTL = HOUR_IN_SECONDS;

	/**
	 * The MIME types the gate will serve inline. Anything else goes as a download.
	 */
	const INLINE_MIMES = [ 'image/jpeg', 'image/png', 'image/webp', 'application/pdf' ];

	/**
	 * Resolved directory, forward slashes, no trailing slash.
	 *
	 * @var string|null
	 */
	protected $dir = null;

	/**
	 * "external" or "uploads".
	 *
	 * @var string
	 */
	protected $mode = '';

	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {

		// Remember the published folder from a real request, for the CLI and cron runs that have none.
		add_action( 'init', [ $this, 'remember_document_root' ], 5 );

		// Every path a URL could leak through, all keyed on the private marker.
		add_filter( 'wp_get_attachment_url', [ $this, 'filter_attachment_url' ], 10, 2 );
		add_filter( 'wp_get_attachment_image_src', [ $this, 'filter_image_src' ], 10, 2 );
		add_filter( 'image_downsize', [ $this, 'filter_downsize' ], 10, 2 );
		add_filter( 'attachment_link', [ $this, 'filter_attachment_url' ], 10, 2 );
		add_filter( 'rest_prepare_attachment', [ $this, 'filter_rest_attachment' ], 10, 2 );
		add_filter( 'ajax_query_attachments_args', [ $this, 'exclude_from_library_ajax' ] );
		add_action( 'pre_get_posts', [ $this, 'exclude_from_library_query' ] );
		add_action( 'template_redirect', [ $this, 'block_attachment_page' ] );

		// Unlink the file when the row goes, in both storage modes.
		add_action( 'delete_attachment', [ $this, 'delete_file' ], 10, 2 );

		// A Windows external path is stored with forward slashes, which WordPress treats as relative.
		add_filter( 'get_attached_file', [ $this, 'filter_attached_file' ], 10, 2 );

		parent::__construct( $args );
	}

	/*
	--------------------------------------------------------------------------
	Where files live.
	--------------------------------------------------------------------------
	*/

	/**
	 * Records DOCUMENT_ROOT whenever a real request has one.
	 *
	 * @return void
	 */
	public function remember_document_root() {
		if ( empty( $_SERVER['DOCUMENT_ROOT'] ) ) {
			return;
		}

		$root = sanitize_text_field( wp_unslash( (string) $_SERVER['DOCUMENT_ROOT'] ) );
		$real = realpath( $root );
		$root = untrailingslashit( wp_normalize_path( $real ? $real : $root ) );

		if ( '' !== $root && (string) get_option( 'hp_' . HPVE_OPTION_PREFIX . 'document_root', '' ) !== $root ) {
			update_option( 'hp_' . HPVE_OPTION_PREFIX . 'document_root', $root, false );
		}
	}

	/**
	 * The folder the web server publishes, or ABSPATH when nothing better is known.
	 *
	 * @return string
	 */
	protected function get_published_dir() {
		$abspath = untrailingslashit( wp_normalize_path( ABSPATH ) );
		$root    = (string) get_option( 'hp_' . HPVE_OPTION_PREFIX . 'document_root', '' );

		if ( '' === $root ) {
			return $abspath;
		}

		// A root that does not contain this install is describing some other site.
		if ( $root !== $abspath && ! Hpve_Path::is_inside( $abspath, $root ) ) {
			return $abspath;
		}

		return $root;
	}

	/**
	 * The private directory, resolving and creating it on first use.
	 *
	 * Order: the HPVE_PRIVATE_DIR constant or the hpve_verification_private_dir filter; the folder
	 * beside the published one when its parent is writable; the uploads folder with a random name as
	 * the honest last resort. The choice is stored so it never moves under existing files.
	 *
	 * @return string Forward slashes, no trailing slash; empty when nothing could be created.
	 */
	public function get_dir() {
		if ( null !== $this->dir ) {
			return $this->dir;
		}

		$this->dir = '';

		/**
		 * Filters the directory private documents are stored in.
		 *
		 * @hook hpve_verification_private_dir
		 * @param {string} $dir Absolute path, or an empty string to choose automatically.
		 * @return {string} Absolute path.
		 */
		$custom = (string) apply_filters( 'hpve_verification_private_dir', defined( 'HPVE_PRIVATE_DIR' ) ? (string) HPVE_PRIVATE_DIR : '' );

		$candidates = [];

		if ( '' !== $custom ) {
			$candidates[] = [ untrailingslashit( wp_normalize_path( $custom ) ), 'external' ];
		}

		$stored_dir  = (string) get_option( 'hp_' . HPVE_OPTION_PREFIX . 'storage_dir', '' );
		$stored_mode = (string) get_option( 'hp_' . HPVE_OPTION_PREFIX . 'storage_mode', '' );

		if ( '' !== $stored_dir && in_array( $stored_mode, [ 'external', 'uploads' ], true ) ) {
			$candidates[] = [ $stored_dir, $stored_mode ];
		} else {
			$published = $this->get_published_dir();
			$parent    = dirname( $published );

			if ( $parent && $parent !== $published && wp_is_writable( $parent ) ) {
				$candidates[] = [ $parent . '/hpve-private-' . substr( md5( ABSPATH ), 0, 8 ), 'external' ];
			}

			$uploads = wp_get_upload_dir();

			$candidates[] = [ wp_normalize_path( $uploads['basedir'] ) . '/hpve-private-' . strtolower( wp_generate_password( 12, false, false ) ), 'uploads' ];
		}

		$published = $this->get_published_dir();

		foreach ( $candidates as $candidate ) {
			list( $dir, $mode ) = $candidate;

			// An "external" folder that still sits inside the published one is not protection.
			if ( 'external' === $mode && ( $dir === $published || Hpve_Path::is_inside( $dir, $published ) ) && '' === $custom ) {
				continue;
			}

			if ( ! wp_mkdir_p( $dir ) || ! wp_is_writable( $dir ) ) {
				continue;
			}

			$this->protect_directory( $dir );

			$this->dir  = $dir;
			$this->mode = $mode;

			if ( $stored_dir !== $dir || $stored_mode !== $mode ) {
				update_option( 'hp_' . HPVE_OPTION_PREFIX . 'storage_dir', $dir, false );
				update_option( 'hp_' . HPVE_OPTION_PREFIX . 'storage_mode', $mode, false );
			}

			break;
		}

		return $this->dir;
	}

	/**
	 * "external", "uploads", or "" when no directory could be created.
	 *
	 * @return string
	 */
	public function get_mode() {
		$this->get_dir();

		return $this->mode;
	}

	/**
	 * Forgets the resolved directory, for the runtime check that switches modes.
	 *
	 * @return void
	 */
	public function reset() {
		$this->dir  = null;
		$this->mode = '';
	}

	/**
	 * Writes the deny rule and the index file into a directory, re-checked on every upload.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	public function protect_directory( $dir ) {
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			$rules = "# Verification Expiry for HivePress: deny direct access.\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n"
				. "Options -Indexes\n";

			file_put_contents( $dir . '/.htaccess', $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- one-off deny-rule guard beside direct file handling.
		}

		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', '<?php // Silence.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- one-off index guard.
		}
	}

	/**
	 * The per-request subdirectory, created with its own index file.
	 *
	 * @param int $request_id Request ID.
	 * @return string Forward slashes; empty when storage is unavailable.
	 */
	public function get_request_dir( $request_id ) {
		$dir = $this->get_dir();

		if ( '' === $dir ) {
			return '';
		}

		$this->protect_directory( $dir );

		$sub = $dir . '/' . absint( $request_id );

		if ( ! wp_mkdir_p( $sub ) ) {
			return '';
		}

		if ( ! file_exists( $sub . '/index.php' ) ) {
			file_put_contents( $sub . '/index.php', '<?php // Silence.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- one-off index guard.
		}

		return $sub;
	}

	/**
	 * A plain sentence about the storage mode, for the settings tab and the readme.
	 *
	 * @return string
	 */
	public function describe_mode() {
		$dir  = $this->get_dir();
		$mode = $this->get_mode();

		if ( '' === $dir ) {
			return esc_html__( 'No folder for documents could be created. Check that the uploads folder is writable, or set the HPVE_PRIVATE_DIR constant in wp-config.php to a writable folder outside the web root.', 'verification-expiry-for-hivepress' );
		}

		if ( 'external' === $mode ) {
			/* translators: %s: the folder path. */
			return sprintf( esc_html__( 'Documents are stored outside the folder your web server publishes (%s). Only this plugin can read them, through a download link that checks who you are first.', 'verification-expiry-for-hivepress' ), $dir );
		}

		return esc_html__( 'Documents are stored inside the uploads folder, in a folder with a random name and a deny rule. On many hosts (any nginx or proxy host) that rule is not enforced, so the download link\'s sign-in check is the only protection. Ask your host for a folder outside the web root and set the HPVE_PRIVATE_DIR constant in wp-config.php to use it.', 'verification-expiry-for-hivepress' );
	}

	/*
	--------------------------------------------------------------------------
	Upload.
	--------------------------------------------------------------------------
	*/

	/**
	 * Whether an attachment is one of ours.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public function is_private( $attachment_id ) {
		return '1' === (string) get_post_meta( absint( $attachment_id ), self::META_PRIVATE, true );
	}

	/**
	 * The gate URL for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public function get_gate_url( $attachment_id ) {
		return hivepress()->router->get_url( 'hpve_document_view_page', [ 'attachment_id' => absint( $attachment_id ) ] );
	}

	/**
	 * Takes the uploaded file into the private folder and creates the attachment row.
	 *
	 * The caller (the controller) has already checked login, ownership, status, payment, the field,
	 * the size and the format. This method only moves the file: the upload_dir filter and the
	 * thumbnail filters are added around the single media_handle_sideload() call and removed in a
	 * finally block, so a normal upload elsewhere in the same request lands in the usual place.
	 *
	 * @param object $request Request.
	 * @param array  $row Document type row.
	 * @param string $file_key Key in $_FILES.
	 * @return int|\WP_Error Attachment ID.
	 */
	public function upload( $request, array $row, $file_key = 'file' ) {
		$request_id = (int) $request->get_id();
		$path       = $this->get_request_dir( $request_id );

		if ( '' === $path ) {
			return new \WP_Error( 'hpve_no_storage', esc_html__( 'Documents cannot be stored right now. Please contact us.', 'verification-expiry-for-hivepress' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		/*
		 * Forward slashes throughout. Every meta write goes through wp_unslash() (update_metadata),
		 * which strips the backslashes of a native Windows path: "C:\\Users\\..." was stored as
		 * "C:Users...". A forward-slash "C:/..." survives the write but
		 * get_attached_file() then treats it as relative (wp-includes/post.php keeps a path
		 * absolute only when it starts with "/" or "X:\\"), so filter_attached_file() below hands
		 * the stored path back untouched. Linux paths start with "/" and need neither. basedir and
		 * baseurl are left alone: _wp_relative_upload_path() strips basedir from the stored path
		 * (post.php), and replacing it would store a path WordPress later prepends the real
		 * uploads folder to.
		 */
		$native = $path;
		$gate   = home_url( '/verification-document/' );

		$dir_filter = function ( $uploads ) use ( $native, $gate ) {
			$uploads['path']   = $native;
			$uploads['url']    = $gate;
			$uploads['subdir'] = '';
			$uploads['error']  = false;

			return $uploads;
		};

		add_filter( 'upload_dir', $dir_filter, 1000 );
		add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 1000 );
		add_filter( 'fallback_intermediate_image_sizes', '__return_empty_array', 1000 );
		add_filter( 'big_image_size_threshold', '__return_false', 1000 );

		/*
		 * media_handle_sideload() rather than media_handle_upload(): both run the same type sniff
		 * (wp_check_filetype_and_ext) and the same directory logic, but the sideload path checks the
		 * temporary file with is_readable() and copies it, where the upload path insists on
		 * is_uploaded_file(), which only PHP's own upload handling can satisfy. The controller has
		 * already checked the field, the size and the format; the file array is built here from the
		 * checked values, with the randomised name decided before WordPress sees it.
		 */
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the REST cookie nonce was checked before the controller ran.
		$original = isset( $_FILES[ $file_key ]['name'] ) ? sanitize_file_name( wp_unslash( (string) $_FILES[ $file_key ]['name'] ) ) : '';
		// tmp_name is a path PHP itself wrote, never client input, and on Windows it carries
		// backslashes that wp_unslash() would strip. It is taken as is and only ever handed to
		// is_readable().
		$tmp_name = isset( $_FILES[ $file_key ]['tmp_name'] ) && is_string( $_FILES[ $file_key ]['tmp_name'] ) ? $_FILES[ $file_key ]['tmp_name'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a server-side temporary path, see the note above.
		$size     = isset( $_FILES[ $file_key ]['size'] ) ? absint( $_FILES[ $file_key ]['size'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$ext = pathinfo( $original, PATHINFO_EXTENSION );

		$file_array = [
			'name'     => $this->unique_filename( $path, $original, '' !== $ext ? '.' . strtolower( $ext ) : '' ),
			'tmp_name' => $tmp_name,
		];

		try {
			$attachment_id = media_handle_sideload( $file_array, $request_id, '' !== $original ? $original : null );
		} finally {
			remove_filter( 'upload_dir', $dir_filter, 1000 );
			remove_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 1000 );
			remove_filter( 'fallback_intermediate_image_sizes', '__return_empty_array', 1000 );
			remove_filter( 'big_image_size_threshold', '__return_false', 1000 );
		}

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$attachment_id = (int) $attachment_id;

		// The marker first, so every URL filter below answers correctly from here on.
		update_post_meta( $attachment_id, self::META_PRIVATE, '1' );

		update_post_meta( $attachment_id, self::META_SIZE, $size );

		// The guid is where WordPress otherwise keeps the file URL and hands it out in several places.
		// wp_update_post() cannot change it (wp_insert_post() re-reads the stored guid on every
		// update, wp-includes/post.php:4653), so it is written the way core itself writes it (:524).
		global $wpdb;

		$wpdb->update( $wpdb->posts, [ 'guid' => $this->get_gate_url( $attachment_id ) ], [ 'ID' => $attachment_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the only way to replace a guid; cache cleared below.

		clean_post_cache( $attachment_id );

		if ( '' !== $original ) {
			wp_update_post(
				[
					'ID'         => $attachment_id,
					'post_title' => $original,
				]
			);
		}

		return $attachment_id;
	}

	/**
	 * Returns a stored Windows path untouched instead of letting WordPress prepend the uploads folder.
	 *
	 * @param string|false $file Path as WordPress resolved it.
	 * @param int          $attachment_id Attachment ID.
	 * @return string|false
	 */
	public function filter_attached_file( $file, $attachment_id ) {
		if ( ! $this->is_private( $attachment_id ) ) {
			return $file;
		}

		$raw = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );

		if ( preg_match( '#^[A-Za-z]:/#', $raw ) ) {
			return $raw;
		}

		return $file;
	}

	/**
	 * Names the stored file: a random prefix, the sanitised original name, core's random suffix.
	 *
	 * WordPress still runs wp_unique_filename() on top and appends a number on a collision, which the
	 * random prefix makes vanishingly unlikely.
	 *
	 * @param string $dir Directory.
	 * @param string $name File name.
	 * @param string $ext Extension with the dot.
	 * @return string
	 */
	public function unique_filename( $dir, $name, $ext ) {
		$base = sanitize_file_name( pathinfo( (string) $name, PATHINFO_FILENAME ) );

		if ( '' === $base ) {
			$base = 'document';
		}

		$filename = strtolower( wp_generate_password( 12, false, false ) ) . '-' . $base . $ext;

		return (string) apply_filters( 'hivepress/v1/models/attachment/filename', $filename, $ext, $dir );
	}

	/*
	--------------------------------------------------------------------------
	The gate.
	--------------------------------------------------------------------------
	*/

	/**
	 * Streams a document to its owner or a reviewer, and to nobody else.
	 *
	 * Runs inside the router's action and exits. A logged-out visitor gets 401 without a redirect,
	 * because a redirect would confirm that the ID exists.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function serve( $attachment_id ) {
		nocache_headers();

		if ( ! is_user_logged_in() ) {
			status_header( 401 );

			exit;
		}

		$attachment_id = absint( $attachment_id );
		$post          = $attachment_id ? get_post( $attachment_id ) : null;

		if ( ! $post || 'attachment' !== $post->post_type || ! $this->is_private( $attachment_id ) ) {
			status_header( 404 );

			exit;
		}

		$request = hivepress()->hpve_request->get_request( (int) $post->post_parent );

		if ( ! $request ) {
			status_header( 404 );

			exit;
		}

		$user_id  = get_current_user_id();
		$reviewer = current_user_can( HPVE_REVIEW_CAP );
		$owner    = hivepress()->hpve_request->is_owner( $request, $user_id );

		if ( ! $reviewer && ( ! $owner || 'trash' === $request->get_status() ) ) {
			status_header( 403 );

			exit;
		}

		$real = $this->get_real_path( $attachment_id );

		if ( '' === $real ) {
			status_header( 404 );

			exit;
		}

		if ( $reviewer && ! $owner ) {
			$this->log_view( $attachment_id, $request, $user_id );
		}

		$mime = (string) get_post_mime_type( $attachment_id );

		if ( ! in_array( $mime, self::INLINE_MIMES, true ) ) {
			$mime        = 'application/octet-stream';
			$disposition = 'attachment';
		} else {
			$disposition = 'inline';
		}

		$title = sanitize_file_name( (string) $post->post_title );

		if ( '' === $title ) {
			$title = basename( $real );
		}

		$size          = (int) filesize( $real );
		$last_modified = (int) filemtime( $real );

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		$if_modified_since = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? strtotime( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) ) : false;

		if ( $if_modified_since && $if_modified_since >= $last_modified ) {
			status_header( 304 );

			exit;
		}

		status_header( 200 );
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . $size );
		header( 'Content-Disposition: ' . $disposition . '; filename="' . str_replace( '"', '', $title ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		// No "sandbox" directive: Chrome's PDF viewer does not render inside a sandboxed document.
		header( "Content-Security-Policy: default-src 'none'" );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Cache-Control: private, no-store' );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $last_modified ) . ' GMT' );
		header_remove( 'Expires' );

		$handle = fopen( $real, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a private file to an authorised reader.

		if ( false === $handle ) {
			status_header( 500 );

			exit;
		}

		while ( ! feof( $handle ) ) {
			echo fread( $handle, 1024 * 1024 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.file_system_operations_fread -- binary file body.

			flush();
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		exit;
	}

	/**
	 * The real path of a private document, or an empty string when it is not under the private folder.
	 *
	 * One root only: the plugin never wrote anywhere else, so anything outside it is refused.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public function get_real_path( $attachment_id ) {
		$file = get_attached_file( absint( $attachment_id ) );

		if ( ! $file ) {
			return '';
		}

		$real = realpath( $file );
		$root = $this->get_dir() ? realpath( $this->get_dir() ) : false;

		if ( ! $real || ! $root || ! is_file( $real ) ) {
			return '';
		}

		if ( ! Hpve_Path::is_inside( $real, $root ) ) {
			return '';
		}

		return $real;
	}

	/**
	 * Logs a reviewer opening a document, once per reviewer per attachment per hour.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param object $request Request.
	 * @param int    $user_id Reviewer ID.
	 * @return void
	 */
	protected function log_view( $attachment_id, $request, $user_id ) {
		$key = 'hpve_viewed_' . (int) $user_id . '_' . (int) $attachment_id;

		if ( get_transient( $key ) ) {
			return;
		}

		set_transient( $key, 1, self::VIEW_LOG_TTL );

		$user = get_userdata( (int) $user_id );

		hivepress()->hpve_request->log(
			$request->get_id(),
			'document_viewed',
			sprintf(
				/* translators: 1: the reviewer's name, 2: the document's name. */
				esc_html__( '%1$s viewed the document "%2$s".', 'verification-expiry-for-hivepress' ),
				$user ? $user->display_name : '',
				(string) get_the_title( (int) $attachment_id )
			),
			[
				'actor' => [
					'name'    => $user ? $user->display_name : '',
					'user_id' => (int) $user_id,
					'email'   => $user ? $user->user_email : '',
				],
			]
		);
	}

	/*
	--------------------------------------------------------------------------
	Every other path a URL could leak through.
	--------------------------------------------------------------------------
	*/

	/**
	 * Every URL for a private attachment is the gate URL.
	 *
	 * @param string $url URL.
	 * @param int    $attachment_id Attachment ID.
	 * @return string
	 */
	public function filter_attachment_url( $url, $attachment_id ) {
		if ( $this->is_private( $attachment_id ) ) {
			return $this->get_gate_url( $attachment_id );
		}

		return $url;
	}

	/**
	 * Image sources resolve to the gate for every size.
	 *
	 * @param mixed $image Image data.
	 * @param int   $attachment_id Attachment ID.
	 * @return mixed
	 */
	public function filter_image_src( $image, $attachment_id ) {
		if ( $this->is_private( $attachment_id ) ) {
			return [ $this->get_gate_url( $attachment_id ), 0, 0, false ];
		}

		return $image;
	}

	/**
	 * Stops wp_get_attachment_image() building a srcset from real paths.
	 *
	 * @param mixed $out Short-circuit value.
	 * @param int   $attachment_id Attachment ID.
	 * @return mixed
	 */
	public function filter_downsize( $out, $attachment_id ) {
		if ( $this->is_private( $attachment_id ) ) {
			return [ $this->get_gate_url( $attachment_id ), 0, 0, false ];
		}

		return $out;
	}

	/**
	 * The REST media endpoint never shows a real file path or size.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @param \WP_Post          $post Attachment.
	 * @return \WP_REST_Response
	 */
	public function filter_rest_attachment( $response, $post ) {
		if ( ! $this->is_private( $post->ID ) ) {
			return $response;
		}

		$data = $response->get_data();

		$data['source_url']    = $this->get_gate_url( $post->ID );
		$data['guid']          = [ 'rendered' => $this->get_gate_url( $post->ID ) ];
		$data['media_details'] = [];

		$response->set_data( $data );

		return $response;
	}

	/**
	 * Hides private attachments from the media modal for anyone who is not a reviewer.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public function exclude_from_library_ajax( $args ) {
		if ( current_user_can( HPVE_REVIEW_CAP ) ) {
			return $args;
		}

		$args['meta_query'] = $this->library_exclusion( isset( $args['meta_query'] ) ? $args['meta_query'] : [] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one NOT EXISTS clause on the media screen only.

		return $args;
	}

	/**
	 * Hides private attachments from the Media list screen for anyone who is not a reviewer.
	 *
	 * @param \WP_Query $query Query.
	 * @return void
	 */
	public function exclude_from_library_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || 'attachment' !== $query->get( 'post_type' ) ) {
			return;
		}

		if ( current_user_can( HPVE_REVIEW_CAP ) ) {
			return;
		}

		$query->set( 'meta_query', $this->library_exclusion( (array) $query->get( 'meta_query' ) ) );
	}

	/**
	 * The NOT EXISTS clause the two exclusions share.
	 *
	 * @param array $meta_query Existing meta query.
	 * @return array
	 */
	protected function library_exclusion( $meta_query ) {
		$meta_query = array_filter( (array) $meta_query );

		$meta_query[] = [
			'key'     => self::META_PRIVATE,
			'compare' => 'NOT EXISTS',
		];

		return $meta_query;
	}

	/**
	 * A private attachment has no public page.
	 *
	 * @return void
	 */
	public function block_attachment_page() {
		if ( is_attachment() && $this->is_private( get_queried_object_id() ) ) {
			global $wp_query;

			$wp_query->set_404();

			status_header( 404 );
			nocache_headers();
		}
	}

	/*
	--------------------------------------------------------------------------
	Deletion and housekeeping.
	--------------------------------------------------------------------------
	*/

	/**
	 * Unlinks the file of a private attachment when its row is deleted.
	 *
	 * Fires before WordPress deletes the row (wp-includes/post.php:6867, WP 7.1), so the path is
	 * still readable. The emptied per-request folder goes too.
	 *
	 * @param int      $attachment_id Attachment ID.
	 * @param \WP_Post $post Attachment post.
	 * @return void
	 */
	public function delete_file( $attachment_id, $post = null ) {
		if ( ! $this->is_private( $attachment_id ) ) {
			return;
		}

		$real = $this->get_real_path( $attachment_id );

		if ( '' === $real ) {
			return;
		}

		wp_delete_file( $real );

		$this->remove_empty_dir( dirname( $real ) );
	}

	/**
	 * Removes a per-request folder once only its index file is left.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	protected function remove_empty_dir( $dir ) {
		$root = $this->get_dir() ? realpath( $this->get_dir() ) : false;
		$real = realpath( $dir );

		if ( ! $root || ! $real || ! Hpve_Path::is_inside( $real, $root ) ) {
			return;
		}

		$entries = array_diff( (array) scandir( $real ), [ '.', '..', 'index.php' ] );

		if ( $entries ) {
			return;
		}

		if ( file_exists( $real . '/index.php' ) ) {
			wp_delete_file( $real . '/index.php' );
		}

		rmdir( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing the plugin's own emptied folder.
	}

	/**
	 * Deletes files in the private folder that no attachment references and that are older than the cutoff.
	 *
	 * Covers files left behind when the deletion listener was bypassed, for instance while the
	 * plugin was inactive.
	 *
	 * @param int $cutoff Unix time; only files modified before it are removed.
	 * @return int Files removed.
	 */
	public function sweep_orphans( $cutoff ) {
		$dir = $this->get_dir();

		if ( '' === $dir || ! is_dir( $dir ) ) {
			return 0;
		}

		$known = [];

		$ids = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => self::META_PRIVATE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a daily housekeeping job.
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);

		foreach ( $ids as $id ) {
			$file = get_attached_file( (int) $id );
			$real = $file ? realpath( $file ) : false;

			if ( $real ) {
				$known[ wp_normalize_path( $real ) ] = true;
			}
		}

		$removed = 0;

		foreach ( (array) glob( $dir . '/*', GLOB_ONLYDIR ) as $sub ) {
			foreach ( (array) glob( $sub . '/*' ) as $file ) {
				$base = basename( $file );

				if ( 'index.php' === $base || '.htaccess' === $base || ! is_file( $file ) ) {
					continue;
				}

				$real = realpath( $file );

				if ( ! $real || isset( $known[ wp_normalize_path( $real ) ] ) || filemtime( $real ) >= $cutoff ) {
					continue;
				}

				wp_delete_file( $real );

				++$removed;
			}

			$this->remove_empty_dir( $sub );
		}

		return $removed;
	}
}
