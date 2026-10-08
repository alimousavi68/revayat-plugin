<?php
/** Shared local avatar for accounts and their professional person profile. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Revayat_Companion_Profile_Avatar {
	public static function person_attachment( $person_id ) {
		$id = get_post_thumbnail_id( $person_id ) ?: absint( get_post_meta( $person_id, '_revayat_avatar_id', true ) );
		if ( ! $id ) {
			$user_id = absint( get_post_meta( $person_id, '_revayat_user_id', true ) );
			$id = $user_id ? absint( get_user_meta( $user_id, '_revayat_avatar_id', true ) ) : 0;
		}
		return $id && wp_attachment_is_image( $id ) ? (int) $id : 0;
	}

	public static function person_url( $person_id ) {
		$id = self::person_attachment( $person_id );
		if ( $id ) { return (string) wp_get_attachment_image_url( $id, 'revayat-avatar' ); }
		$user_id = absint( get_post_meta( $person_id, '_revayat_user_id', true ) );
		return $user_id ? (string) get_avatar_url( $user_id ) : '';
	}

	public static function filter_avatar( $args, $identity ) {
		$user = null;
		if ( $identity instanceof WP_User ) { $user = $identity; }
		elseif ( $identity instanceof WP_Comment ) { $user = $identity->user_id ? get_user_by( 'id', $identity->user_id ) : null; }
		elseif ( $identity instanceof WP_Post ) { $user = get_user_by( 'id', $identity->post_author ); }
		elseif ( is_numeric( $identity ) ) { $user = get_user_by( 'id', absint( $identity ) ); }
		elseif ( is_string( $identity ) && is_email( $identity ) ) { $user = get_user_by( 'email', $identity ); }
		if ( ! $user ) { return $args; }
		$person_id = absint( get_user_meta( $user->ID, '_revayat_person_id', true ) );
		$id = $person_id && 'person' === get_post_type( $person_id ) ? self::person_attachment( $person_id ) : 0;
		$id = $id ?: absint( get_user_meta( $user->ID, '_revayat_avatar_id', true ) );
		$url = $id && wp_attachment_is_image( $id ) ? wp_get_attachment_image_url( $id, 'revayat-avatar' ) : '';
		if ( $url ) { $args['url'] = $url; $args['found_avatar'] = true; }
        else { $args['url'] = plugins_url( '../../assets/member-avatar.svg', __FILE__ ); $args['found_avatar'] = true; }
		return $args;
	}

	/** The caller authorizes the account; attachment IDs are never accepted from the client. */
	public static function upload( $user_id, $field = 'profile_avatar' ) {
		if ( empty( $_FILES[ $field ] ) || UPLOAD_ERR_NO_FILE === (int) $_FILES[ $field ]['error'] ) { return true; }
		$file = $_FILES[ $field ];
		if ( UPLOAD_ERR_OK !== (int) $file['error'] || (int) $file['size'] > 2 * MB_IN_BYTES ) {
			return new WP_Error( 'avatar_invalid', 'تصویر باید JPG، PNG یا WebP و حداکثر ۲ مگابایت باشد.' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$person_id = absint( get_user_meta( $user_id, '_revayat_person_id', true ) );
		if ( 'person' !== get_post_type( $person_id ) ) { $person_id = 0; }
		$id = media_handle_upload( $field, $person_id, array(), array( 'test_form' => false, 'mimes' => array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ) ) );
		if ( is_wp_error( $id ) ) { return $id; }
		if ( ! wp_attachment_is_image( $id ) ) {
			wp_delete_attachment( $id, true );
			return new WP_Error( 'avatar_invalid', 'فایل تصویر معتبر نیست.' );
		}
		update_user_meta( $user_id, '_revayat_avatar_id', $id );
		if ( $person_id ) { delete_post_meta( $person_id, '_revayat_avatar_id' ); set_post_thumbnail( $person_id, $id ); }
		return true;
	}

	public static function form_tag() { echo ' enctype="multipart/form-data"'; }

	public static function admin_fields( $user ) {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) { return; }
		?><h2><?php esc_html_e( 'تصویر پروفایل روایت ایران', 'revayat-companion' ); ?></h2>
		<?php echo get_avatar( $user->ID, 96 ); ?>
		<?php wp_nonce_field( 'revayat_avatar_' . $user->ID, 'revayat_avatar_nonce' ); ?>
		<p><label for="profile_avatar"><?php esc_html_e( 'ثبت یا تغییر تصویر (JPG، PNG، WebP؛ حداکثر ۲ مگابایت)', 'revayat-companion' ); ?></label><br><input id="profile_avatar" type="file" name="profile_avatar" accept="image/jpeg,image/png,image/webp"></p><?php
	}

	public static function admin_save( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) || empty( $_POST['revayat_avatar_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['revayat_avatar_nonce'] ) ), 'revayat_avatar_' . $user_id ) ) { return; }
		$result = self::upload( $user_id );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'back_link' => true ) ); }
	}

	public static function frontend_save() {
		if ( ! is_user_logged_in() ) { wp_die( 'دسترسی غیرمجاز', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'revayat_update_avatar', 'revayat_avatar_nonce' );
		$result = self::upload( get_current_user_id() );
		wp_safe_redirect( add_query_arg( 'notice', is_wp_error( $result ) ? 'avatar_invalid' : 'profile_updated', home_url( '/dashboard/' ) ) );
		exit;
	}
}
