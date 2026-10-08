<?php
/** Documents never enter the public attachment library. Fail closed until configured. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Revayat_Companion_Private_Documents {
	public static function directory() {
		$path = defined( 'REVAYAT_PRIVATE_STORAGE_PATH' ) ? REVAYAT_PRIVATE_STORAGE_PATH : get_option( 'revayat_private_storage_path', '' );
		$real = $path ? realpath( $path ) : false;
		if ( ! $real || ! is_dir( $real ) || ! is_writable( $real ) ) { return ''; }
		foreach ( array( realpath( ABSPATH ), ! empty( $_SERVER['DOCUMENT_ROOT'] ) ? realpath( $_SERVER['DOCUMENT_ROOT'] ) : false ) as $public ) {
			if ( $public && ( $real === $public || 0 === strpos( $real . '/', trailingslashit( $public ) ) ) ) { return ''; }
		}
		return $real;
	}
	public static function ready() { return self::directory() && (int) get_option( 'revayat_document_retention_days', 0 ) > 0 && (int) get_option( 'revayat_document_draft_days', 0 ) > 0 && function_exists( 'sodium_crypto_secretbox' ); }
	private static function key() { return hash( 'sha256', wp_salt( 'auth' ) . '|revayat-private-documents-v1', true ); }
	public static function encrypt( $value ) {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return base64_encode( $nonce . sodium_crypto_secretbox( $value, $nonce, self::key() ) );
	}
	public static function decrypt( $value ) {
		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) { return false; }
		$raw = base64_decode( (string) $value, true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) { return false; }
		return sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key() );
	}
	public static function upload( $field ) {
		if ( ! self::ready() ) { return new WP_Error( 'documents_unavailable', 'دریافت مدارک هنوز فعال نیست؛ سیاست نگهداری و فضای خصوصی باید تنظیم شوند.' ); }
		$file = $_FILES[ $field ] ?? array();
		if ( ! $file || UPLOAD_ERR_NO_FILE === (int) $file['error'] ) { return null; }
		if ( UPLOAD_ERR_OK !== (int) $file['error'] || (int) $file['size'] > 5 * MB_IN_BYTES || ! is_uploaded_file( $file['tmp_name'] ) ) { return new WP_Error( 'document_invalid', 'هر تصویر باید معتبر و حداکثر ۵ مگابایت باشد.' ); }
		$info = wp_getimagesize( $file['tmp_name'] );
		if ( ! $info || ! in_array( $info['mime'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) || $info[0] * $info[1] > 24000000 ) { return new WP_Error( 'document_invalid', 'تصویر JPG، PNG یا WebP با حداکثر ۲۴ مگاپیکسل انتخاب کنید.' ); }
		// Decode/re-encode through WordPress to remove non-image payloads and metadata.
		$editor = wp_get_image_editor( $file['tmp_name'] );
		if ( is_wp_error( $editor ) ) { return new WP_Error( 'document_invalid', 'تصویر قابل پردازش نیست.' ); }
		$editor->resize( 2400, 2400 );
		$tmp = tempnam( self::directory(), 'rv-image-' );
		$result = $editor->save( $tmp . '.jpg', 'image/jpeg' );
		@unlink( $tmp );
		if ( is_wp_error( $result ) ) { return new WP_Error( 'document_invalid', 'پردازش تصویر انجام نشد.' ); }
		$bytes = file_get_contents( $result['path'] ); @unlink( $result['path'] );
		$id = bin2hex( random_bytes( 24 ) );
		$target = self::directory() . '/' . $id . '.rvdoc';
		if ( false === file_put_contents( $target, self::encrypt( $bytes ), LOCK_EX ) ) { return new WP_Error( 'document_storage', 'ذخیره فایل انجام نشد.' ); }
		chmod( $target, 0600 );
		return $id;
	}
	public static function delete( $id ) {
		if ( self::directory() && preg_match( '/^[a-f0-9]{48}$/', (string) $id ) ) { $path = self::directory() . '/' . $id . '.rvdoc'; if ( is_file( $path ) ) { unlink( $path ); } }
	}
	public static function download() {
		$id = absint( $_GET['request_id'] ?? 0 ); $field = sanitize_key( $_GET['field'] ?? '' );
		check_admin_referer( 'rv_document_' . $id . '_' . $field );
		$post = get_post( $id );
		if ( ! $post || 'rv_application' !== $post->post_type || ! Revayat_Companion_Member_Policy::active( get_current_user_id() ) || ( (int) $post->post_author !== get_current_user_id() && ! current_user_can( 'revayat_manage_approvals' ) ) || ! in_array( $field, array( 'portrait', 'national_card' ), true ) ) { wp_die( 'دسترسی غیرمجاز', '', array( 'response' => 403 ) ); }
		$documents = get_post_meta( $id, '_rv_documents', true );
		$token = $documents[ $field ] ?? '';
		if ( ! self::directory() || ! preg_match( '/^[a-f0-9]{48}$/', $token ) ) { wp_die( 'فایل موجود نیست.', '', array( 'response' => 404 ) ); }
		$path = self::directory() . '/' . $token . '.rvdoc';
		$bytes = is_file( $path ) ? self::decrypt( file_get_contents( $path ) ) : false;
		if ( false === $bytes ) { wp_die( 'فایل قابل دریافت نیست.', '', array( 'response' => 404 ) ); }
		add_post_meta( $id, '_rv_document_access', array( 'actor' => get_current_user_id(), 'at' => time(), 'field' => $field ) );
		nocache_headers(); header( 'Content-Type: image/jpeg' ); header( 'X-Content-Type-Options: nosniff' ); header( 'X-Robots-Tag: noindex, nofollow' ); header( 'Content-Disposition: attachment; filename="document.jpg"' );
		echo $bytes; exit;
	}
	public static function cleanup() {
		$days = (int) get_option( 'revayat_document_retention_days', 0 );
		if ( ! $days || ! self::directory() ) { return; }
		$ids = get_posts( array( 'post_type' => 'rv_application', 'post_status' => 'private', 'fields' => 'ids', 'posts_per_page' => 100, 'meta_query' => array( array( 'key' => '_rv_purge_at', 'value' => time(), 'compare' => '<=', 'type' => 'NUMERIC' ) ) ) );
		foreach ( $ids as $id ) { foreach ( (array) get_post_meta( $id, '_rv_documents', true ) as $token ) { self::delete( $token ); } delete_post_meta( $id, '_rv_documents' ); delete_post_meta( $id, '_rv_national_id' ); delete_post_meta( $id, '_rv_purge_at' ); update_post_meta( $id, '_rv_documents_purged_at', time() ); }
	}
}
