<?php
/** Shared SMS delivery, provider diagnostics and private bounded audit. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Revayat_Companion_SMS_Service {
	const LOG = 'revayat_sms_log';
	const EDGE = 'https://edge.ippanel.com/v1';

	public static function contexts(): array {
		return array( 'auth' => 'ورود و ثبت‌نام', 'recover' => 'بازیابی رمز', 'access' => 'وضعیت درخواست دسترسی', 'post' => 'وضعیت یادداشت' );
	}

	public static function variables( string $context ): array {
		return 'access' === $context ? array( 'Name', 'Request', 'Status' ) : ( 'post' === $context ? array( 'Name', 'Title', 'Status' ) : array( 'Code' ) );
	}

	public static function provider( string $context ): string {
		$route = (string) Revayat_Companion_SMS_Settings::get( 'route_' . $context );
		return in_array( $route, array( 'smsir', 'ippanel', 'webhook', 'disabled' ), true ) ? $route : (string) Revayat_Companion_SMS_Settings::get( 'provider' );
	}

	public static function template( string $provider, string $context ): string {
		$field = 'smsir' === $provider && 'auth' === $context ? 'smsir_register_id' : $provider . '_' . $context . '_id';
		$value = (string) Revayat_Companion_SMS_Settings::get( $field );
		if ( 'recover' === $context && ( '' === $value || '0' === $value ) ) { return self::template( $provider, 'auth' ); }
		return $value;
	}

	private static function params( string $provider, string $context, array $values ) {
		$mapping = (string) Revayat_Companion_SMS_Settings::get( $provider . '_map_' . $context );
		if ( '' === $mapping && 'recover' === $context ) { $mapping = (string) Revayat_Companion_SMS_Settings::get( $provider . '_map_auth' ); }
		$map = array();
		foreach ( preg_split( '/[\r\n,]+/', $mapping ) as $line ) {
			$pair = array_map( 'trim', explode( '=', $line, 2 ) );
			if ( 2 === count( $pair ) ) { $map[ $pair[0] ] = $pair[1]; }
		}
		if ( ! $map && in_array( $context, array( 'auth', 'recover' ), true ) ) {
			$map = array( 'Code' => 'smsir' === $provider ? (string) Revayat_Companion_SMS_Settings::get( 'smsir_parameter' ) : 'code' );
		}
		$result = array();
		foreach ( self::variables( $context ) as $key ) {
			if ( empty( $map[ $key ] ) || ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $map[ $key ] ) || ! isset( $values[ $key ] ) ) {
				return new WP_Error( 'sms_invalid_mapping', 'نام متغیرهای قالب کامل یا معتبر نیست.' );
			}
			$result[ $map[ $key ] ] = (string) $values[ $key ];
		}
		return $result;
	}

	/** Fixed endpoints; API responses and headers never enter the audit. */
	private static function request( string $provider, string $method, string $path, array $body = array(), string $candidate = '' ) {
		$key = $candidate ?: (string) Revayat_Companion_SMS_Settings::get( $provider . '_api_key' );
		if ( ! $key ) { return new WP_Error( 'sms_missing_key', 'کلید API تنظیم نشده است.' ); }
		$url = 'ippanel' === $provider ? self::EDGE . $path : 'https://api.sms.ir/v1' . $path;
		$args = array( 'method' => $method, 'timeout' => 15, 'redirection' => 0, 'headers' => array( 'Accept' => 'application/json', 'Content-Type' => 'application/json', 'ippanel' === $provider ? 'Authorization' : 'X-API-KEY' => $key ) );
		if ( 'GET' !== $method ) { $args['body'] = wp_json_encode( $body ); }
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) { return new WP_Error( 'sms_transport_error', 'ارتباط با سرویس پیامک برقرار نشد؛ اتصال اینترنت سرور را بررسی کنید.' ); }
		$http = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$success = is_array( $data ) && ( 'ippanel' === $provider ? true === ( $data['meta']['status'] ?? false ) : 1 === (int) ( $data['status'] ?? 0 ) );
		if ( $http >= 200 && $http < 300 && $success ) { return $data['data'] ?? array(); }
		$code = 'ippanel' === $provider ? ( $data['meta']['message_code'] ?? '' ) : ( $data['status'] ?? '' );
		if ( 401 === $http || 403 === $http ) { $message = 'کلید یا دسترسی سرویس معتبر نیست؛ محدودیت IP و مجوزهای کلید را بررسی کنید.'; }
		elseif ( 422 === $http || 400 === $http ) { $message = 'سرویس درخواست را رد کرد؛ قالب تأییدشده، متغیرها و شماره فرستنده را بررسی کنید.'; }
		elseif ( 429 === $http ) { $message = 'تعداد درخواست به سرویس زیاد است؛ کمی بعد دوباره تلاش کنید.'; }
		else { $message = 'سرویس پیامک پاسخ موفق نداد.'; }
		$code = preg_match( '/^[0-9-]{1,16}$/', (string) $code ) ? (string) $code : 'unknown';
		return new WP_Error( 'sms_provider_rejected', $message . ' HTTP: ' . absint( $http ) . ' / ' . $code );
	}

	public static function connection( string $provider, string $candidate = '' ) {
		if ( ! in_array( $provider, array( 'smsir', 'ippanel' ), true ) ) { return new WP_Error( 'sms_unsupported_probe', 'این ابزار برای SMS.ir و مدیرپیامک است.' ); }
		$data = self::request( $provider, 'GET', 'ippanel' === $provider ? '/api/payment/credit/mine' : '/credit', array(), $candidate );
		if ( is_wp_error( $data ) ) { return $data; }
		$credit = is_array( $data ) ? ( $data['credit'] ?? null ) : $data;
		$result = array( 'message' => 'اتصال برقرار است و کلید معتبر است.', 'credit' => is_numeric( $credit ) ? (float) $credit : null, 'checked_at' => current_time( 'mysql', true ) );
		// Probe of an unsaved candidate must not replace the saved account's balance.
		if ( '' === $candidate ) { update_option( 'revayat_sms_credit_' . $provider, $result, false ); }
		return $result;
	}

	public static function pattern( string $provider, string $context ) {
		if ( 'ippanel' !== $provider ) { return new WP_Error( 'sms_pattern_probe_unavailable', 'بررسی وضعیت قالب SMS.ir از پنل همان سرویس انجام می‌شود؛ ارسال آزمایشی در اینجا در دسترس است.' ); }
		$code = self::template( $provider, $context );
		if ( ! $code ) { return new WP_Error( 'sms_missing_pattern', 'کد پترن را وارد و ذخیره کنید.' ); }
		$data = self::request( $provider, 'GET', '/api/patterns/' . rawurlencode( $code ) );
		if ( is_wp_error( $data ) ) { return $data; }
		return array( 'message' => 'وضعیت پترن: ' . sanitize_text_field( $data['pattern_status_fa'] ?? $data['pattern_status'] ?? 'نامشخص' ), 'active' => 'active' === ( $data['pattern_status'] ?? '' ), 'variables' => array_map( 'sanitize_text_field', array_column( $data['variable'] ?? array(), 'name' ) ), 'text' => sanitize_textarea_field( $data['pattern_message'] ?? '' ) );
	}

	public static function send( $mobile, string $context, array $values, string $provider = '', string $recipient_ref = '', string $event = '' ) {
		if ( ! isset( self::contexts()[ $context ] ) ) { return new WP_Error( 'sms_invalid_context', 'کاربرد پیامک معتبر نیست.' ); }
		$mobile = Revayat_Companion_User_Portal::normalize_mobile( $mobile );
		if ( ! preg_match( '/^09\d{9}$/', $mobile ) ) { return new WP_Error( 'invalid_mobile', 'شماره موبایل معتبر نیست.' ); }
		$provider = $provider ?: self::provider( $context );
		if ( ! in_array( $provider, array( 'smsir', 'ippanel', 'webhook' ), true ) ) { return new WP_Error( 'otp_disabled', 'ارسال پیامک برای این کاربرد غیرفعال است.' ); }
		$result = self::deliver( $mobile, $provider, $context, $values );
		self::audit( $mobile, $provider, $context, $result, $values, $recipient_ref, $event );
		return $result;
	}

	private static function deliver( string $mobile, string $provider, string $context, array $values ) {
		if ( 'webhook' === $provider ) {
			$url = (string) Revayat_Companion_SMS_Settings::get( 'webhook_url' );
			if ( ! $url ) { return new WP_Error( 'otp_gateway_unconfigured', 'درگاه Webhook تنظیم نشده است.' ); }
			$token = (string) Revayat_Companion_SMS_Settings::get( 'webhook_token' );
			$headers = array( 'Content-Type' => 'application/json' );
			if ( $token ) { $headers['Authorization'] = 'Bearer ' . $token; }
			$body = array( 'mobile' => $mobile, 'context' => $values['_webhook_context'] ?? $context, 'template' => Revayat_Companion_SMS_Settings::get( 'webhook_template' ) );
			if ( isset( $values['Code'] ) ) { $body['code'] = $values['Code']; } else { $body['parameters'] = $values; }
			$response = wp_safe_remote_post( $url, array( 'timeout' => 15, 'redirection' => 0, 'headers' => $headers, 'body' => wp_json_encode( $body ) ) );
			if ( is_wp_error( $response ) ) { return new WP_Error( 'sms_transport_error', 'ارتباط با Webhook برقرار نشد.' ); }
			$http = wp_remote_retrieve_response_code( $response );
			return $http >= 200 && $http < 300 ? array( 'id' => '' ) : new WP_Error( 'otp_gateway_rejected', 'Webhook درخواست را نپذیرفت.' );
		}
		$template = self::template( $provider, $context );
		if ( ! $template || ( 'smsir' === $provider && (int) $template < 1 ) ) { return new WP_Error( 'sms_missing_pattern', 'شناسه قالب برای این کاربرد تنظیم نشده است.' ); }
		$params = self::params( $provider, $context, $values );
		if ( is_wp_error( $params ) ) { return $params; }
		if ( 'ippanel' === $provider ) {
			$sender = (string) Revayat_Companion_SMS_Settings::get( 'ippanel_sender' );
			if ( ! preg_match( '/^\+98\d{3,20}$/', $sender ) ) { return new WP_Error( 'sms_missing_sender', 'شماره فرستنده مجاز را با پیش‌شماره +98 تنظیم کنید.' ); }
			// Numeric OTP variables are accepted by Edge as integers.
			if ( isset( $values['Code'] ) ) { foreach ( $params as &$value ) { $value = (int) $value; } unset( $value ); }
			$data = self::request( $provider, 'POST', '/api/send', array( 'sending_type' => 'pattern', 'from_number' => $sender, 'code' => $template, 'recipients' => array( '+98' . substr( $mobile, 1 ) ), 'params' => $params ) );
			return is_wp_error( $data ) ? $data : array( 'id' => sanitize_text_field( (string) ( $data['message_outbox_ids'][0] ?? '' ) ) );
		}
		$parameters = array();
		foreach ( $params as $name => $value ) { $parameters[] = array( 'name' => $name, 'value' => $value ); }
		$data = self::request( $provider, 'POST', '/send/verify', array( 'mobile' => $mobile, 'templateId' => (int) $template, 'parameters' => $parameters ) );
		return is_wp_error( $data ) ? $data : array( 'id' => sanitize_text_field( (string) ( $data['messageId'] ?? '' ) ) );
	}

	public static function log(): array {
		$log = get_option( self::LOG, array() );
		return is_array( $log ) ? $log : array();
	}

	private static function audit( $mobile, $provider, $context, $result, $values, $recipient_ref, $event ): void {
		$log = self::log();
		$entry = array( 'id' => wp_generate_uuid4(), 'time' => current_time( 'mysql', true ), 'provider' => $provider, 'context' => $context, 'mobile' => substr( $mobile, 0, 4 ) . '***' . substr( $mobile, -3 ), 'accepted' => ! is_wp_error( $result ), 'message_id' => is_wp_error( $result ) ? '' : $result['id'], 'error' => is_wp_error( $result ) ? $result->get_error_message() : '', 'event' => $event );
		if ( is_wp_error( $result ) && $recipient_ref && in_array( $context, array( 'access', 'post' ), true ) ) { $entry['retry'] = array( 'recipient' => $recipient_ref, 'values' => array_intersect_key( $values, array_flip( self::variables( $context ) ) ) ); }
		array_unshift( $log, $entry );
		update_option( self::LOG, array_slice( $log, 0, 100 ), false );
	}

	public static function report( string $entry_id ) {
		foreach ( self::log() as $entry ) {
			if ( $entry_id !== $entry['id'] ) { continue; }
			if ( empty( $entry['message_id'] ) ) { return new WP_Error( 'sms_no_message_id', 'شناسه ارسال موجود نیست.' ); }
			$path = 'ippanel' === $entry['provider'] ? '/api/report/by_bulk?messages_outbox_id=' . rawurlencode( $entry['message_id'] ) : '/send/' . rawurlencode( $entry['message_id'] );
			$data = self::request( $entry['provider'], 'GET', $path );
			if ( is_wp_error( $data ) ) { return $data; }
			// Whitelist status only; delivery endpoints may return the original OTP text.
			if ( 'ippanel' === $entry['provider'] ) { return array( 'message' => 'وضعیت سرویس: ' . sanitize_text_field( $data['status'] ?? $data['state'] ?? 'نامشخص' ) . '؛ این گزارش به‌تنهایی دریافت توسط گیرنده را تضمین نمی‌کند.' ); }
			return array( 'message' => 'کد وضعیت تحویل SMS.ir: ' . sanitize_text_field( (string) ( $data['deliveryState'] ?? 'نامشخص' ) ) );
		}
		return new WP_Error( 'sms_log_missing', 'گزارش پیدا نشد.' );
	}

	public static function retry( string $entry_id ) {
		foreach ( self::log() as $entry ) {
			if ( $entry_id !== $entry['id'] ) { continue; }
			if ( empty( $entry['retry'] ) || ! empty( $entry['accepted'] ) || ! in_array( $entry['context'], array( 'access', 'post' ), true ) ) { return new WP_Error( 'sms_retry_forbidden', 'فقط اعلان ناموفق قابل ارسال مجدد است؛ برای OTP کد تازه درخواست کنید.' ); }
			$ref = $entry['retry']['recipient'];
			$mobile = self::recipient_mobile( $ref );
			if ( ! $mobile ) { return new WP_Error( 'sms_recipient_missing', 'شماره تأییدشده گیرنده دیگر در دسترس نیست.' ); }
			foreach ( self::log() as $other ) { if ( $other['event'] && $other['event'] === $entry['event'] && $other['accepted'] ) { return new WP_Error( 'sms_already_sent', 'این اعلان قبلاً پذیرفته شده است.' ); } }
			$lock = 'revayat_sms_retry_' . md5( $entry['event'] ?: $entry_id );
			if ( ! add_option( $lock, time(), '', false ) ) { return new WP_Error( 'sms_busy', 'ارسال مجدد در حال انجام است.' ); }
			try { return self::send( $mobile, $entry['context'], $entry['retry']['values'], '', $ref, $entry['event'] ); }
			finally { delete_option( $lock ); }
		}
		return new WP_Error( 'sms_log_missing', 'گزارش پیدا نشد.' );
	}

	private static function recipient_mobile( string $ref ): string {
		if ( 0 === strpos( $ref, 'user:' ) ) {
			$id = absint( substr( $ref, 5 ) );
			return get_user_meta( $id, '_revayat_mobile_verified', true ) ? Revayat_Companion_User_Portal::normalize_mobile( get_user_meta( $id, '_revayat_mobile', true ) ) : '';
		}
		foreach ( self::admin_numbers() as $mobile ) { if ( 'admin:' . hash( 'sha256', $mobile ) === $ref ) { return $mobile; } }
		return '';
	}

	private static function admin_numbers(): array {
		return array_filter( array_unique( preg_split( '/[\s,]+/', (string) Revayat_Companion_SMS_Settings::get( 'admin_numbers' ) ) ) );
	}

	public static function notify_access( $user_id, $request, $status, $event ): void {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) { return; }
		$labels = array( 'pending' => 'در انتظار بررسی', 'approved' => 'تأیید شده', 'rejected' => 'رد شده', 'changes_requested' => 'نیازمند تکمیل', 'revoked' => 'لغو شده', 'suspended' => 'معلق شده' );
		$values = array( 'Name' => mb_substr( $user->display_name, 0, 30 ), 'Request' => $request, 'Status' => $labels[ $status ] ?? $status );
		self::notify( 'access', $user_id, $status, $values, $event );
	}

	public static function notify_post( $post_id, $status, $event ): void {
		$post = get_post( $post_id );
		if ( ! $post || 'analyst_post' !== $post->post_type ) { return; }
		$user = get_user_by( 'id', $post->post_author );
		if ( ! $user ) { return; }
		$labels = Revayat_Companion_User_Portal::get_review_status_labels();
		self::notify( 'post', $user->ID, $status, array( 'Name' => mb_substr( $user->display_name, 0, 30 ), 'Title' => mb_substr( wp_strip_all_tags( $post->post_title ), 0, 60 ), 'Status' => $labels[ $status ] ?? $status ), $event );
	}

	private static function notify( $context, $user_id, $status, $values, $event ): void {
		$recipients = array();
		if ( 'pending' !== $status && Revayat_Companion_SMS_Settings::get( 'enabled_' . $context ) ) { $recipients[] = 'user:' . absint( $user_id ); }
		if ( 'pending' === $status && Revayat_Companion_SMS_Settings::get( 'admin_' . $context ) ) { foreach ( self::admin_numbers() as $mobile ) { $recipients[] = 'admin:' . hash( 'sha256', $mobile ); } }
		foreach ( $recipients as $ref ) {
			$mobile = self::recipient_mobile( $ref );
			if ( ! $mobile ) { continue; }
			$seen = false;
			foreach ( self::log() as $entry ) { if ( $entry['event'] === $event . ':' . $ref ) { $seen = true; break; } }
			if ( $seen ) { continue; }
			$key = 'revayat_sms_event_' . md5( $event . ':' . $ref );
			if ( ! add_option( $key, time(), '', false ) ) { continue; }
			try { self::send( $mobile, $context, $values, '', $ref, $event . ':' . $ref ); }
			finally { delete_option( $key ); }
		}
	}
}
