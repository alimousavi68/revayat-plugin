<?php
/** OTP امن و درگاه webhook قابل‌تعویض. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Revayat_OTP_Gateway_Interface {
	public function send( $mobile, $code, $context );
}

class Revayat_OTP_Email_Gateway implements Revayat_OTP_Gateway_Interface {
	public function send( $email, $code, $context, $mobile = '' ) {
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', 'آدرس ایمیل معتبر نیست.' );
		}
		$site_name = get_bloginfo( 'name' ) ?: 'روایت ایران';
		$title = 'کد تأیید ورود و ثبت‌نام';
		if ( 'recover' === $context ) {
			$title = 'کد بازیابی کلمه عبور';
		}
		$subject = sprintf( '[%s] %s', $site_name, $title );

		$from_email = get_option( 'admin_email' ) ?: ( 'no-reply@' . ( wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'localhost' ) );
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $site_name . ' <' . $from_email . '>',
		);

		$formatted_code = function_exists( 'revayat_en2fa' ) ? revayat_en2fa( $code ) : $code;
		$masked_mobile  = $mobile ? ( substr( $mobile, 0, 4 ) . '•••' . substr( $mobile, -4 ) ) : '';

		$message  = '<div dir="rtl" style="font-family: Tahoma, Arial, sans-serif; max-width: 520px; margin: 0 auto; padding: 24px; border: 1px solid #e2e8f0; border-radius: 12px; background-color: #ffffff; color: #1e293b;">';
		$message .= '<div style="text-align: center; margin-bottom: 20px;">';
		$message .= '<h2 style="color: #b80000; margin: 0 0 8px 0; font-size: 20px;">' . esc_html( $site_name ) . '</h2>';
		$message .= '<p style="color: #64748b; font-size: 13px; margin: 0;">' . esc_html( $title ) . '</p>';
		$message .= '</div>';
		$message .= '<div style="background-color: #f8fafc; border-radius: 8px; padding: 20px; text-align: center; margin-bottom: 20px;">';
		$message .= '<span style="display: block; font-size: 12px; color: #64748b; margin-bottom: 8px;">کد تأیید یک‌بارمصرف شما:</span>';
		$message .= '<span style="display: inline-block; font-size: 32px; font-weight: bold; letter-spacing: 6px; color: #0f172a; padding: 6px 16px; background: #ffffff; border: 1px dashed #cbd5e1; border-radius: 8px;">' . esc_html( $formatted_code ) . '</span>';
		$message .= '<p style="font-size: 12px; color: #94a3b8; margin: 12px 0 0 0;">این کد تا ۲ دقیقه معتبر است.</p>';
		$message .= '</div>';
		if ( $masked_mobile ) {
			$message .= '<p style="font-size: 13px; color: #475569; line-height: 1.6; margin: 0 0 12px 0;">این کد به دلیل اختلال موقت در سامانه پیامکی یا به عنوان کانال جایگزین برای شماره <strong>' . esc_html( $masked_mobile ) . '</strong> ارسال شده است.</p>';
		}
		$message .= '<p style="font-size: 12px; color: #94a3b8; line-height: 1.5; margin: 0; border-top: 1px solid #f1f5f9; padding-top: 12px;">اگر شما این درخواست را در روایت ایران ثبت نکرده‌اید، این پیام را نادیده بگیرید.</p>';
		$message .= '</div>';

		$sent = wp_mail( $email, $subject, $message, $headers );
		if ( ! $sent ) {
			return new WP_Error( 'email_send_failed', 'ارسال ایمیل با خطا مواجه شد. لطفاً دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.' );
		}
		return true;
	}
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
	const TTL_SECONDS    = 120;
	const RESEND_SECONDS = 60;
	const WINDOW         = 900;
	const MAX_SENDS      = 3;
	const MAX_TRIES      = 5;
	const HEALTH_KEY     = 'revayat_sms_health_circuit';
	const HEALTH_THRESHOLD = 2; // سوئیچ به fallback پس از ۲ خطای متوالی

	public static function get_sms_health(): array {
		$data = get_transient( self::HEALTH_KEY );
		if ( ! is_array( $data ) ) {
			return array(
				'status'               => 'healthy',
				'consecutive_failures' => 0,
				'last_error'           => '',
				'last_error_time'      => 0,
				'last_success_time'    => 0,
			);
		}
		return wp_parse_args( $data, array(
			'status'               => 'healthy',
			'consecutive_failures' => 0,
			'last_error'           => '',
			'last_error_time'      => 0,
			'last_success_time'    => 0,
		) );
	}

	public static function record_sms_success(): void {
		$current = self::get_sms_health();
		$current['status']               = 'healthy';
		$current['consecutive_failures'] = 0;
		$current['last_success_time']    = time();
		set_transient( self::HEALTH_KEY, $current, 15 * MINUTE_IN_SECONDS );
	}

	public static function record_sms_failure( string $error_message = '' ): void {
		$current = self::get_sms_health();
		$failures = (int) ( $current['consecutive_failures'] ?? 0 ) + 1;
		$current['consecutive_failures'] = $failures;
		$current['last_error']           = $error_message;
		$current['last_error_time']      = time();
		if ( $failures >= self::HEALTH_THRESHOLD ) {
			$current['status'] = 'degraded';
		}
		set_transient( self::HEALTH_KEY, $current, 15 * MINUTE_IN_SECONDS );
	}

	public static function mask_email( string $email ): string {
		if ( ! is_email( $email ) ) {
			return '';
		}
		$parts  = explode( '@', $email, 2 );
		$name   = $parts[0];
		$domain = $parts[1] ?? '';
		$len    = mb_strlen( $name );
		if ( $len <= 2 ) {
			$masked_name = $name . '•••';
		} else {
			$masked_name = mb_substr( $name, 0, 2 ) . '•••' . mb_substr( $name, -1 );
		}
		return $masked_name . '@' . $domain;
	}

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
		$data    = get_transient( self::key( 'challenge', $context . ':' . Revayat_Companion_User_Portal::normalize_mobile( $mobile ) ) );
		$created = is_array( $data ) ? absint( $data['created'] ?? 0 ) : 0;
		return array(
			'server_time'        => time(),
			'expires_at'         => $created ? $created + self::TTL_SECONDS : 0,
			'resend_available_at'=> $created ? $created + self::RESEND_SECONDS : 0,
			'channel'            => is_array( $data ) ? ( $data['channel'] ?? 'sms' ) : 'sms',
			'masked_target'      => is_array( $data ) ? ( $data['masked_target'] ?? '' ) : '',
		);
	}

	public static function request( $mobile, $context = 'register', $email = '' ) {
		$mobile = Revayat_Companion_User_Portal::normalize_mobile( $mobile );
		$email  = sanitize_email( $email );
		return Revayat_Companion_Workflow_Lock::run( 'otp-ip:' . self::client_ip(), static function () use ( $mobile, $context, $email ) {
			return Revayat_Companion_Workflow_Lock::run( 'otp:' . $mobile, static function () use ( $mobile, $context, $email ) {
				return self::issue( $mobile, $context, $email );
			} );
		} );
	}

	private static function issue( $mobile, $context, $email = '' ) {
		$mobile  = Revayat_Companion_User_Portal::normalize_mobile( $mobile );
		$context = in_array( $context, array( 'auth', 'register', 'recover', 'mobile_change' ), true ) ? $context : 'auth';
		if ( ! preg_match( '/^09\d{9}$/', $mobile ) ) {
			return new WP_Error( 'invalid_mobile', 'شماره موبایل معتبر نیست.' );
		}
		$timing = self::timing( $mobile, $context );
		if ( $timing['resend_available_at'] > time() ) {
			return new WP_Error( 'otp_cooldown', 'برای ارسال دوباره کد تا پایان شمارش معکوس صبر کنید.', $timing );
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
		$channel   = 'sms';
		$target_ref= '';
		$notice    = 'کد تایید به شماره شما پیامک شد.';

		if ( ! $test_code ) {
			$provider = Revayat_Companion_SMS_Service::provider( 'recover' === $context ? 'recover' : 'auth' );
			$health   = self::get_sms_health();

			// بررسی وجود ایمیل برای فال‌بک
			$target_email = $email;
			if ( empty( $target_email ) ) {
				$existing_user = Revayat_Companion_User_Portal::get_user_by_mobile( $mobile );
				if ( $existing_user instanceof WP_User && is_email( $existing_user->user_email ) ) {
					$target_email = $existing_user->user_email;
				}
			}

			$sms_failed = false;
			$last_sms_error = '';

			if ( 'disabled' === $provider ) {
				$sms_failed = true;
				$last_sms_error = 'سامانه پیامک موقتاً غیرفعال است.';
			} else {
				$gateways = array(
					'webhook' => 'Revayat_OTP_Webhook_Gateway',
					'smsir'   => 'Revayat_OTP_SMSIR_Gateway',
					'ippanel' => 'Revayat_OTP_IPPanel_Gateway',
				);
				if ( ! isset( $gateways[ $provider ] ) ) {
					$sms_failed = true;
					$last_sms_error = 'درگاه پیامک نامعتبر است.';
				} else {
					$gateway = new $gateways[ $provider ]();
					$gateway = apply_filters( 'revayat_otp_gateway', $gateway, $context );
					if ( ! $gateway instanceof Revayat_OTP_Gateway_Interface ) {
						$sms_failed = true;
						$last_sms_error = 'درگاه پیامک نامعتبر است.';
					} else {
						$sent = $gateway->send( $mobile, $code, $context );
						if ( is_wp_error( $sent ) ) {
							$last_sms_error = $sent->get_error_message();
							// تلاش با درگاه ثانویه پیامک
							$fallback_provider = ( 'smsir' === $provider ) ? 'ippanel' : ( 'ippanel' === $provider ? 'smsir' : '' );
							$fallback_key      = $fallback_provider ? (string) Revayat_Companion_SMS_Settings::get( $fallback_provider . '_api_key' ) : '';
							if ( $fallback_key && isset( $gateways[ $fallback_provider ] ) ) {
								$fallback_class   = $gateways[ $fallback_provider ];
								$fallback_gateway = new $fallback_class();
								$fallback_sent    = $fallback_gateway->send( $mobile, $code, $context );
								if ( ! is_wp_error( $fallback_sent ) ) {
									$sent = true;
								} else {
									$last_sms_error = $fallback_sent->get_error_message();
								}
							}
						}
						if ( is_wp_error( $sent ) ) {
							$sms_failed = true;
							self::record_sms_failure( $last_sms_error );
						} else {
							self::record_sms_success();
						}
					}
				}
			}

			// در صورت خطای پیامک، فعال‌سازی سوئیچ خودکار به ایمیل
			if ( $sms_failed ) {
				if ( ! empty( $target_email ) && is_email( $target_email ) ) {
					$email_gateway = new Revayat_OTP_Email_Gateway();
					$email_sent    = $email_gateway->send( $target_email, $code, $context, $mobile );
					if ( is_wp_error( $email_sent ) ) {
						return new WP_Error(
							'otp_delivery_failed',
							'ارسال پیامک و ایمیل هر دو با خطا مواجه شدند. لطفاً با پشتیبانی در ارتباط باشید.'
						);
					}
					$channel    = 'email';
					$target_ref = self::mask_email( $target_email );
					$notice     = 'به دلیل اختلال موقت پیامک، کد تایید به ایمیل ' . $target_ref . ' ارسال شد.';
				} else {
					// کاربر جدید است یا ایمیل ندارد: درخواست ایمیل از کاربر
					return new WP_Error(
						'sms_failed_email_required',
						'ارسال پیامک با اختلال مواجه شد. لطفاً برای دریافت کد تایید، آدرس ایمیل خود را وارد فرمایید.',
						array(
							'email_required' => true,
							'mobile'         => $mobile,
							'context'        => $context,
							'sms_error'      => $last_sms_error,
						)
					);
				}
			}
		}

		set_transient(
			self::key( 'challenge', $context . ':' . $mobile ),
			array(
				'hash'          => wp_hash_password( $code ),
				'attempts'      => 0,
				'created'       => time(),
				'channel'       => $channel,
				'masked_target' => $target_ref,
				'email'         => $target_email ?? '',
			),
			self::TTL_SECONDS
		);

		return array(
			'success'       => true,
			'channel'       => $channel,
			'masked_target' => $target_ref,
			'notice'        => $notice,
		);
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
