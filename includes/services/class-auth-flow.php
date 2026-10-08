<?php
/** Compact auth transport, with session-bound challenges and no PII in URLs. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Revayat_Companion_Auth_Flow {
	private static function session( $create = false ) {
		$token = isset( $_COOKIE['rv_auth_session'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['rv_auth_session'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			if ( ! $create ) { return ''; }
			$token = bin2hex( random_bytes( 32 ) );
			setcookie( 'rv_auth_session', $token, array( 'expires' => time() + HOUR_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
			$_COOKIE['rv_auth_session'] = $token;
		}
		return hash_hmac( 'sha256', $token, wp_salt( 'nonce' ) );
	}
	private static function key() { return 'rv_auth_flow_' . self::session(); }
	public static function context() {
		nocache_headers();
		$session = self::session( true );
		$data = get_transient( self::key() );
		$state = array( 'nonce' => wp_create_nonce( 'rv_auth_' . $session ), 'logged_in' => is_user_logged_in(), 'server_time' => time() );
		if ( is_array( $data ) ) {
			$state += Revayat_Companion_OTP_Service::timing( $data['mobile'], $data['context'] );
			$state['masked_mobile'] = substr( $data['mobile'], 0, 4 ) . '•••' . substr( $data['mobile'], -4 );
			$state['context'] = $data['context'];
		}
		wp_send_json_success( $state );
	}
	public static function handle() {
		nocache_headers();
		$session = self::session();
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! $session || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'rv_auth_' . $session ) ) {
			wp_send_json_error( array( 'code' => 'invalid_nonce', 'message' => 'نشست فرم تازه شود؛ دوباره تلاش کنید.' ), 403 );
		}
		$action = sanitize_key( $_POST['operation'] ?? '' );
		$result = self::perform( $action, wp_unslash( $_POST ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message(), 'timing' => $result->get_error_data() ), 400 );
		}
		wp_send_json_success( $result );
	}
	private static function destination( $value ) {
		$target = wp_validate_redirect( esc_url_raw( $value ), home_url( '/dashboard/' ) );
		if ( wp_parse_url( $target, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) || false !== strpos( $target, '/auth/' ) || false !== strpos( $target, '/wp-admin' ) ) { return home_url( '/dashboard/' ); }
		return $target;
	}
	public static function perform( $action, $input ) {
		if ( is_user_logged_in() ) { return array( 'redirect' => home_url( '/dashboard/' ) ); }
		if ( 'request' === $action ) {
			$mobile = Revayat_Companion_User_Portal::normalize_mobile( $input['mobile'] ?? '' );
			$context = 'recover' === ( $input['context'] ?? '' ) ? 'recover' : 'auth';
			$result = Revayat_Companion_OTP_Service::request( $mobile, $context );
			if ( is_wp_error( $result ) ) { return $result; }
			set_transient( self::key(), array( 'mobile' => $mobile, 'context' => $context, 'redirect' => self::destination( $input['redirect_to'] ?? '' ) ), HOUR_IN_SECONDS );
			return array_merge( Revayat_Companion_OTP_Service::timing( $mobile, $context ), array( 'step' => 'verify', 'masked_mobile' => substr( $mobile, 0, 4 ) . '•••' . substr( $mobile, -4 ), 'context' => $context ) );
		}
		if ( 'resend' === $action ) {
			$data = get_transient( self::key() );
			if ( ! is_array( $data ) ) { return new WP_Error( 'otp_expired', 'شماره موبایل را دوباره وارد کنید.' ); }
			return self::perform( 'request', array( 'mobile' => $data['mobile'], 'context' => $data['context'], 'redirect_to' => $data['redirect'] ) );
		}
		if ( 'password' === $action ) {
			$key = 'rv_password_rate_' . hash( 'sha256', ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
			$tries = (int) get_transient( $key );
			if ( $tries >= 10 ) { return new WP_Error( 'rate_limited', 'تلاش‌های ورود زیاد بوده است؛ ۱۵ دقیقه بعد دوباره تلاش کنید.' ); }
			set_transient( $key, $tries + 1, 15 * MINUTE_IN_SECONDS );
			$user = wp_signon( array( 'user_login' => Revayat_Companion_User_Portal::resolve_login_identifier( $input['login'] ?? '' ), 'user_password' => (string) ( $input['password'] ?? '' ), 'remember' => ! empty( $input['remember'] ) ), is_ssl() );
			if ( is_wp_error( $user ) ) { return new WP_Error( 'login_failed', 'اطلاعات ورود صحیح نیست یا حساب فعال نیست.' ); }
			delete_transient( $key );
			return array( 'redirect' => self::destination( $input['redirect_to'] ?? '' ) );
		}
		if ( 'verify' !== $action ) { return new WP_Error( 'invalid_action', 'درخواست معتبر نیست.' ); }
		$data = get_transient( self::key() );
		if ( ! is_array( $data ) ) { return new WP_Error( 'otp_expired', 'درخواست کد تازه‌ای ثبت کنید.' ); }
		if ( 'recover' === $data['context'] && strlen( (string) ( $input['password'] ?? '' ) ) < 8 ) { return new WP_Error( 'invalid_password', 'رمز تازه باید حداقل ۸ نویسه داشته باشد.' ); }
		$result = Revayat_Companion_OTP_Service::verify( $data['mobile'], $input['otp_code'] ?? '', $data['context'] );
		if ( is_wp_error( $result ) ) { return $result; }
		$user = Revayat_Companion_User_Portal::get_user_by_mobile( $data['mobile'] );
		if ( 'recover' === $data['context'] ) {
			if ( ! $user || ! Revayat_Companion_Member_Policy::active( $user->ID ) ) { return new WP_Error( 'recovery_failed', 'امکان بازیابی این حساب وجود ندارد.' ); }
			wp_set_password( (string) $input['password'], $user->ID );
			delete_transient( self::key() );
			return array( 'step' => 'password', 'message' => 'رمز تغییر کرد؛ اکنون وارد شوید.' );
		}
		$is_new = ! $user;
		if ( ! $user ) { $user = Revayat_Companion_User_Portal::create_mobile_member( $data['mobile'] ); }
		if ( is_wp_error( $user ) || ! $user ) { return new WP_Error( 'registration_failed', 'ایجاد حساب انجام نشد؛ دوباره تلاش کنید.' ); }
		if ( ! Revayat_Companion_Member_Policy::active( $user->ID ) ) { return new WP_Error( 'account_suspended', 'حساب شما غیرفعال است؛ با مدیریت تماس بگیرید.' ); }
		update_user_meta( $user->ID, '_revayat_mobile_verified', 1 );
		update_user_meta( $user->ID, '_revayat_mobile_verified_at', current_time( 'mysql', true ) );
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, ! empty( $input['remember'] ), is_ssl() );
		do_action( 'wp_login', $user->user_login, $user );
		delete_transient( self::key() );
		if ( $is_new ) {
			update_user_meta( $user->ID, '_revayat_onboarding_destination', $data['redirect'] );
			return array( 'redirect' => home_url( '/dashboard/?view=profile&onboarding=1' ) );
		}
		return array( 'redirect' => $data['redirect'] );
	}
}
