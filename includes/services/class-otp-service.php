<?php
/** OTP امن و درگاه webhook قابل‌تعویض. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Revayat_OTP_Gateway_Interface {
	public function send( $mobile, $code, $context );
}

class Revayat_OTP_SMSIR_Gateway implements Revayat_OTP_Gateway_Interface {
	public function send( $mobile, $code, $context ) {
		$result = Revayat_Companion_SMS_Service::send( $mobile, 'recover' === $context ? 'recover' : 'auth', array( 'Code' => (string) $code ), 'smsir' );
		return is_wp_error( $result ) ? $result : true;
	}
}

class Revayat_OTP_IPPanel_Gateway implements Revayat_OTP_Gateway_Interface {
	public function send( $mobile, $code, $context ) {
		$result = Revayat_Companion_SMS_Service::send( $mobile, 'recover' === $context ? 'recover' : 'auth', array( 'Code' => (string) $code ), 'ippanel' );
		return is_wp_error( $result ) ? $result : true;
	}
}

class Revayat_OTP_Webhook_Gateway implements Revayat_OTP_Gateway_Interface {
	public function send( $mobile, $code, $context ) {
		// Keep the old webhook context names for existing integrations.
		$result = Revayat_Companion_SMS_Service::send( $mobile, 'recover' === $context ? 'recover' : 'auth', array( 'Code' => (string) $code, '_webhook_context' => $context ), 'webhook' );
		return is_wp_error( $result ) ? $result : true;
	}
}

class Revayat_Companion_OTP_Service {
	const TTL_SECONDS = 120;
	const RESEND_SECONDS = 60;
	const WINDOW      = 900;
	const MAX_SENDS   = 3;
	const MAX_TRIES   = 5;

	private static function key( $prefix, $value ) {
		return 'revayat_otp_' . $prefix . '_' . hash( 'sha256', wp_salt( 'auth' ) . '|' . $value );
	}

	private static function client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	}

	public static function digits( $value ) {
		return strtr( (string) $value, array_combine( preg_split( '//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY ), str_split( '01234567890123456789' ) ) );
	}
	public static function timing( $mobile, $context ) {
		$data = get_transient( self::key( 'challenge', $context . ':' . Revayat_Companion_User_Portal::normalize_mobile( $mobile ) ) );
		$created = is_array( $data ) ? absint( $data['created'] ?? 0 ) : 0;
		return array( 'server_time' => time(), 'expires_at' => $created ? $created + self::TTL_SECONDS : 0, 'resend_available_at' => $created ? $created + self::RESEND_SECONDS : 0 );
	}
	public static function request( $mobile, $context = 'register' ) {
		$mobile = Revayat_Companion_User_Portal::normalize_mobile( $mobile );
		return Revayat_Companion_Workflow_Lock::run( 'otp-ip:' . self::client_ip(), static function () use ( $mobile, $context ) {
			return Revayat_Companion_Workflow_Lock::run( 'otp:' . $mobile, static function () use ( $mobile, $context ) { return self::issue( $mobile, $context ); } );
		} );
	}

	private static function issue( $mobile, $context ) {
		$mobile  = Revayat_Companion_User_Portal::normalize_mobile( $mobile );
		$context = in_array( $context, array( 'auth', 'register', 'recover', 'mobile_change' ), true ) ? $context : 'auth';
		if ( ! preg_match( '/^09\d{9}$/', $mobile ) ) {
			return new WP_Error( 'invalid_mobile', 'شماره موبایل معتبر نیست.' );
		}
		$timing = self::timing( $mobile, $context );
		if ( $timing['resend_available_at'] > time() ) { return new WP_Error( 'otp_cooldown', 'برای ارسال دوباره کد تا پایان شمارش معکوس صبر کنید.', $timing ); }
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
			$provider = Revayat_Companion_SMS_Service::provider( 'recover' === $context ? 'recover' : 'auth' );
			if ( 'disabled' === $provider ) { return new WP_Error( 'otp_disabled', 'ارسال کد یک‌بارمصرف موقتاً غیرفعال است.' ); }
			$gateways = array( 'webhook' => 'Revayat_OTP_Webhook_Gateway', 'smsir' => 'Revayat_OTP_SMSIR_Gateway', 'ippanel' => 'Revayat_OTP_IPPanel_Gateway' );
			if ( ! isset( $gateways[ $provider ] ) ) { return new WP_Error( 'invalid_otp_gateway', 'درگاه OTP معتبر نیست.' ); }
			$gateway = new $gateways[ $provider ]();
			$gateway = apply_filters( 'revayat_otp_gateway', $gateway, $context );
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
		$code = preg_replace( '/\D+/', '', self::digits( $code ) );
		return Revayat_Companion_Workflow_Lock::run( 'otp:' . $mobile, static function () use ( $mobile, $code, $context ) { return self::consume( $mobile, $code, $context ); } );
	}

	private static function consume( $mobile, $code, $context ) {
		$mobile  = Revayat_Companion_User_Portal::normalize_mobile( $mobile );
		$context = in_array( $context, array( 'auth', 'register', 'recover', 'mobile_change' ), true ) ? $context : 'auth';
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
