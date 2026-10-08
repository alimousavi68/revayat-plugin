<?php
/** Private base account profile; public person exists only for approved analysts. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Revayat_Companion_Member_Profile {
	public static function get( $user_id ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user || ( get_current_user_id() !== (int) $user_id && ! current_user_can( 'edit_user', $user_id ) ) ) { return array(); }
		$person = absint( get_user_meta( $user_id, '_revayat_person_id', true ) );
		$data = array( 'display_name' => $user->display_name, 'full_name' => get_user_meta( $user_id, '_revayat_full_name', true ), 'avatar_url' => get_avatar_url( $user_id ), 'version' => (int) get_user_meta( $user_id, '_rv_profile_version', true ) );
		foreach ( array( 'role_title', 'organization', 'expertise', 'bio' ) as $field ) {
			$value = get_user_meta( $user_id, '_revayat_' . $field, true );
			$data[ $field ] = $value ?: ( $person ? ( 'bio' === $field ? get_post_field( 'post_content', $person ) : get_post_meta( $person, '_revayat_' . $field, true ) ) : '' );
		}
		return $data;
	}
	public static function save( $user_id, $input ) {
		if ( ! Revayat_Companion_Member_Policy::active( $user_id ) ) { return new WP_Error( 'forbidden', 'حساب فعال لازم است.' ); }
		return Revayat_Companion_Workflow_Lock::run( 'profile:' . $user_id, static function () use ( $user_id, $input ) {
			$version = (int) get_user_meta( $user_id, '_rv_profile_version', true );
			if ( (int) ( $input['version'] ?? -1 ) !== $version ) { return new WP_Error( 'conflict', 'اطلاعات در پنجره دیگری تغییر کرده است؛ صفحه را تازه کنید.' ); }
			$data = array();
			foreach ( array( 'display_name', 'full_name', 'role_title', 'organization', 'expertise', 'bio' ) as $field ) {
				$data[ $field ] = 'bio' === $field ? sanitize_textarea_field( $input[ $field ] ?? '' ) : sanitize_text_field( $input[ $field ] ?? '' );
				if ( mb_strlen( $data[ $field ] ) > ( 'bio' === $field ? 3000 : 160 ) ) { return new WP_Error( 'profile_invalid', 'طول یکی از فیلدها بیش از حد مجاز است.' ); }
			}
			if ( mb_strlen( $data['display_name'] ) < 2 || mb_strlen( $data['full_name'] ) < 3 ) { return new WP_Error( 'profile_invalid', 'نام کامل و نام نمایشی را وارد کنید.' ); }
			// Validate/upload first; invalid images must not partially save textual fields.
			$result = Revayat_Companion_Profile_Avatar::upload( $user_id );
			if ( is_wp_error( $result ) ) { return $result; }
			$result = wp_update_user( array( 'ID' => $user_id, 'display_name' => $data['display_name'] ) );
			if ( is_wp_error( $result ) ) { return $result; }
			foreach ( $data as $field => $value ) { if ( 'display_name' !== $field ) { update_user_meta( $user_id, '_revayat_' . $field, $value ); } }
			$person = absint( get_user_meta( $user_id, '_revayat_person_id', true ) );
			if ( ! empty( $input['remove_avatar'] ) && empty( $_FILES['profile_avatar']['size'] ) ) {
				delete_user_meta( $user_id, '_revayat_avatar_id' );
				if ( $person ) { delete_post_thumbnail( $person ); delete_post_meta( $person, '_revayat_avatar_id' ); }
			}
			if ( $person && 'person' === get_post_type( $person ) ) {
				wp_update_post( array( 'ID' => $person, 'post_title' => $data['display_name'], 'post_content' => $data['bio'] ) );
				foreach ( array( 'role_title', 'organization', 'expertise' ) as $field ) { update_post_meta( $person, '_revayat_' . $field, $data[ $field ] ); }
			}
			update_user_meta( $user_id, '_rv_profile_version', $version + 1 );
			update_user_meta( $user_id, '_revayat_profile_completed', 1 );
			return array( 'message' => 'اطلاعات پروفایل ذخیره شد.', 'version' => $version + 1 );
		} );
	}
	public static function authorize( $nonce_action ) {
		if ( ! Revayat_Companion_Member_Policy::active( get_current_user_id() ) ) { wp_die( 'دسترسی غیرمجاز', '', array( 'response' => 403 ) ); }
		check_admin_referer( $nonce_action, 'rv_nonce' );
	}
	public static function respond( $result, $view ) {
		if ( wp_doing_ajax() ) {
			if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ), 400 ); }
			wp_send_json_success( $result );
		}
		set_transient( 'rv_portal_flash_' . get_current_user_id(), array( 'error' => is_wp_error( $result ), 'message' => is_wp_error( $result ) ? $result->get_error_message() : ( $result['message'] ?? 'ذخیره شد.' ) ), MINUTE_IN_SECONDS );
		wp_safe_redirect( ! is_wp_error( $result ) && ! empty( $result['redirect'] ) ? wp_validate_redirect( $result['redirect'], home_url( '/dashboard/?view=' . $view ) ) : home_url( '/dashboard/?view=' . $view ) ); exit;
	}
	public static function handle() {
		self::authorize( 'rv_member_profile' );
		$result = self::save( get_current_user_id(), wp_unslash( $_POST ) );
		if ( ! is_wp_error( $result ) && ! empty( $_POST['onboarding'] ) ) {
			$destination = get_user_meta( get_current_user_id(), '_revayat_onboarding_destination', true );
			$result['redirect'] = wp_validate_redirect( $destination, home_url( '/dashboard/' ) );
			delete_user_meta( get_current_user_id(), '_revayat_onboarding_destination' );
		}
		self::respond( $result, 'profile' );
	}
    public static function change_mobile( $user_id, $input ) {
        if ( ! Revayat_Companion_Member_Policy::active( $user_id ) ) { return new WP_Error( 'forbidden', 'حساب فعال لازم است.' ); }
        return Revayat_Companion_Workflow_Lock::run( 'mobile-account:' . $user_id, static function () use ( $user_id, $input ) {
            $key = 'rv_mobile_change_' . $user_id;
            $mode = sanitize_key( $input['mode'] ?? 'request' );
            if ( 'cancel' === $mode ) { delete_transient( $key ); return array( 'message' => 'تغییر شماره لغو شد.', 'reload' => true ); }
            $mobile = 'verify' === $mode ? get_transient( $key ) : Revayat_Companion_User_Portal::normalize_mobile( $input['mobile'] ?? '' );
            if ( ! is_string( $mobile ) || ! preg_match( '/^09[0-9]{9}$/', $mobile ) ) { return new WP_Error( 'invalid_mobile', 'شماره موبایل معتبر وارد کنید یا کد تازه بگیرید.' ); }
            return Revayat_Companion_Workflow_Lock::run( 'mobile-identity:' . $mobile, static function () use ( $user_id, $input, $key, $mobile, $mode ) {
                $owner = Revayat_Companion_User_Portal::get_user_by_mobile( $mobile );
                if ( $owner && (int) $owner->ID !== (int) $user_id ) { return new WP_Error( 'mobile_unavailable', 'امکان استفاده از این شماره وجود ندارد.' ); }
                if ( 'verify' !== $mode ) {
                    $sent = Revayat_Companion_OTP_Service::request( $mobile, 'mobile_change' );
                    if ( is_wp_error( $sent ) ) { return $sent; }
                    set_transient( $key, $mobile, 10 * MINUTE_IN_SECONDS );
                    return array( 'message' => 'کد تأیید برای شماره تازه ارسال شد.', 'reload' => true );
                }
                $verified = Revayat_Companion_OTP_Service::verify( $mobile, $input['otp_code'] ?? '', 'mobile_change' );
                if ( is_wp_error( $verified ) ) { return $verified; }
                update_user_meta( $user_id, '_revayat_mobile', $mobile );
                update_user_meta( $user_id, '_revayat_mobile_verified', 1 );
                update_user_meta( $user_id, '_revayat_mobile_verified_at', current_time( 'mysql', true ) );
                delete_transient( $key );
                $token = wp_get_session_token();
                if ( $token ) { WP_Session_Tokens::get_instance( $user_id )->destroy_others( $token ); }
                return array( 'message' => 'شماره تأیید و ذخیره شد؛ نشست‌های دیگر بسته شدند.', 'reload' => true );
            } );
        } );
    }
    public static function handle_mobile() {
        self::authorize( 'rv_member_mobile' );
        self::respond( self::change_mobile( get_current_user_id(), wp_unslash( $_POST ) ), 'security' );
    }

}
