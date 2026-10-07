<?php
/** OTP امن و درگاه webhook قابل‌تعویض. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Revayat_OTP_Gateway_Interface {
	public function send( $mobile, $code, $context );
}

class Revayat_OTP_Webhook_Gateway implements Revayat_OTP_Gateway_Interface {
	public function send( $mobile, $code, $context ) {
		if ( ! defined( 'REVAYAT_OTP_WEBHOOK_URL' ) || ! REVAYAT_OTP_WEBHOOK_URL ) {
			return new WP_Error( 'otp_gateway_unconfigured', 'درگاه OTP پیکربندی نشده است.' );
		}
		$headers = array( 'Content-Type' => 'application/json' );
		if ( defined( 'REVAYAT_OTP_WEBHOOK_TOKEN' ) && REVAYAT_OTP_WEBHOOK_TOKEN ) {
			$headers['Authorization'] = 'Bearer ' . REVAYAT_OTP_WEBHOOK_TOKEN;
		}
		$response = wp_remote_post(
			REVAYAT_OTP_WEBHOOK_URL,
			array(
				'timeout' => 10,
				'headers' => $headers,
				'body'    => wp_json_encode( array( 'mobile' => $mobile, 'code' => $code, 'context' => $context, 'template' => defined( 'REVAYAT_OTP_WEBHOOK_TEMPLATE' ) ? REVAYAT_OTP_WEBHOOK_TEMPLATE : '' ) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code_http = wp_remote_retrieve_response_code( $response );
		return $code_http >= 200 && $code_http < 300 ? true : new WP_Error( 'otp_gateway_rejected', 'درگاه OTP درخواست را نپذیرفت.' );
	}
}

class Revayat_Companion_OTP_Service {
	const TTL_SECONDS = 120;
	const WINDOW      = 900;
	const MAX_SENDS   = 3;
	const MAX_TRIES   = 5;

	private static function key( $prefix, $value ) {
		return 'revayat_otp_' . $prefix . '_' . hash( 'sha256', wp_salt( 'auth' ) . '|' . $value );
	}

	private static function client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	}

	public static function request( $mobile, $context = 'register' ) {
		$mobile  = Revayat_Companion_User_Portal::normalize_mobile( $mobile );
		$context = in_array( $context, array( 'register', 'recover' ), true ) ? $context : 'register';
		if ( ! preg_match( '/^09\d{9}$/', $mobile ) ) {
			return new WP_Error( 'invalid_mobile', 'شماره موبایل معتبر نیست.' );
		}
		foreach ( array( 'mobile:' . $mobile, 'ip:' . self::client_ip() ) as $bucket ) {
			$key   = self::key( 'rate', $bucket );
			$count = absint( get_transient( $key ) );
			if ( $count >= self::MAX_SENDS ) {
				return new WP_Error( 'otp_rate_limited', 'تعداد درخواست کد بیش از حد مجاز است.' );
			}
			set_transient( $key, $count + 1, self::WINDOW );
		}

		$test_code = Revayat_Companion_User_Portal::get_test_otp();
		$code      = $test_code ?: (string) wp_rand( 100000, 999999 );
		if ( ! $test_code ) {
			$gateway = apply_filters( 'revayat_otp_gateway', new Revayat_OTP_Webhook_Gateway(), $context );
			if ( ! $gateway instanceof Revayat_OTP_Gateway_Interface ) {
				return new WP_Error( 'invalid_otp_gateway', 'درگاه OTP معتبر نیست.' );
			}
			$sent = $gateway->send( $mobile, $code, $context );
			if ( is_wp_error( $sent ) ) {
				return $sent;
			}
		}

		set_transient(
			self::key( 'challenge', $context . ':' . $mobile ),
			array( 'hash' => wp_hash_password( $code ), 'attempts' => 0, 'created' => time() ),
			self::TTL_SECONDS
		);
		return true;
	}

	public static function verify( $mobile, $code, $context = 'register' ) {
		$mobile = Revayat_Companion_User_Portal::normalize_mobile( $mobile );
		$key    = self::key( 'challenge', $context . ':' . $mobile );
		$data   = get_transient( $key );
		if ( ! is_array( $data ) || empty( $data['hash'] ) ) {
			return new WP_Error( 'otp_expired', 'کد تأیید وجود ندارد یا منقضی شده است.' );
		}
		$remaining = self::TTL_SECONDS - max( 0, time() - absint( $data['created'] ?? 0 ) );
		if ( $remaining <= 0 ) {
			delete_transient( $key );
			return new WP_Error( 'otp_expired', 'کد تأیید وجود ندارد یا منقضی شده است.' );
		}
		$attempts = absint( $data['attempts'] ?? 0 );
		if ( $attempts >= self::MAX_TRIES ) {
			delete_transient( $key );
			return new WP_Error( 'otp_attempts_exceeded', 'تعداد تلاش ناموفق بیش از حد مجاز است.' );
		}
		if ( ! wp_check_password( (string) $code, $data['hash'] ) ) {
			$data['attempts'] = $attempts + 1;
			set_transient( $key, $data, $remaining );
			return new WP_Error( 'invalid_otp', 'کد تأیید صحیح نیست.' );
		}
		delete_transient( $key );
		return true;
	}
}
