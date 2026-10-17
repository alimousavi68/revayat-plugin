<?php
/** Provider settings, diagnostics and notification routing. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Revayat_Companion_SMS_Settings {
	const OPTION = 'revayat_sms_settings';

	public static function providers(): array {
		return array( 'smsir' => 'SMS.ir', 'ippanel' => 'مدیرپیامک / IPPanel', 'webhook' => 'Webhook', 'disabled' => 'غیرفعال' );
	}
	public static function defaults(): array {
		$defaults = array(
			'provider' => 'smsir', 'smsir_api_key' => '', 'smsir_parameter' => 'Code', 'smsir_line_number' => '',
			'smsir_admin_access_id' => 0, 'smsir_admin_post_id' => 0,
			'ippanel_api_key' => '', 'ippanel_sender' => '', 'ippanel_admin_access_id' => '', 'ippanel_admin_post_id' => '',
			'webhook_url' => '', 'webhook_token' => '', 'webhook_template' => '',
			'admin_numbers' => '', 'enabled_access' => 0, 'enabled_post' => 0, 'admin_access' => 0, 'admin_post' => 0,
			'smsir_credit_warning' => 0, 'ippanel_credit_warning' => 0,
			'support_text' => 'در صورت عدم دریافت پیامک یا نیاز به راهنمایی، از طریق پیام‌رسان‌ها با ما در ارتباط باشید:',
			'support_bale' => 'https://ble.ir/revayatiran',
			'support_eitaa' => 'https://eitaa.com/revayatiran',
			'support_telegram' => 'https://t.me/revayatiran',
		);
		foreach ( Revayat_Companion_SMS_Service::contexts() as $context => $label ) {
			$defaults[ 'route_' . $context ] = '';
			foreach ( array( 'smsir', 'ippanel' ) as $provider ) {
				$id = 'smsir' === $provider && 'auth' === $context ? 'smsir_register_id' : $provider . '_' . $context . '_id';
				$defaults[ $id ] = 'smsir' === $provider ? 0 : '';
				$map = array();
				foreach ( Revayat_Companion_SMS_Service::variables( $context ) as $variable ) { $map[] = $variable . '=' . ( 'ippanel' === $provider ? strtolower( $variable ) : $variable ); }
				$defaults[ $provider . '_map_' . $context ] = 'smsir' === $provider && in_array( $context, array( 'auth', 'recover' ), true ) ? '' : implode( "\n", $map );
			}
		}
		return $defaults;
	}
	public static function all(): array {
		$value = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $value ) ? $value : array(), self::defaults() );
	}
	public static function support_links(): array {
		$all = self::all();
		return array(
			'text'     => $all['support_text'] ?: 'در صورت عدم دریافت پیامک یا نیاز به راهنمایی، از طریق پیام‌رسان‌ها با ما در ارتباط باشید:',
			'bale'     => $all['support_bale'] ?: 'https://ble.ir/revayatiran',
			'eitaa'    => $all['support_eitaa'] ?: 'https://eitaa.com/revayatiran',
			'telegram' => $all['support_telegram'] ?: 'https://t.me/revayatiran',
		);
	}
	public static function constant_name( string $key ): string {
		$aliases = array( 'smsir_register_id' => defined( 'REVAYAT_SMSIR_AUTH_TEMPLATE_ID' ) ? 'REVAYAT_SMSIR_AUTH_TEMPLATE_ID' : 'REVAYAT_SMSIR_REGISTER_TEMPLATE_ID', 'smsir_recover_id' => 'REVAYAT_SMSIR_RECOVER_TEMPLATE_ID', 'smsir_access_id' => 'REVAYAT_SMSIR_ACCESS_STATUS_TEMPLATE_ID', 'smsir_post_id' => 'REVAYAT_SMSIR_POST_STATUS_TEMPLATE_ID', 'smsir_parameter' => 'REVAYAT_SMSIR_CODE_PARAMETER', 'webhook_url' => 'REVAYAT_OTP_WEBHOOK_URL', 'webhook_token' => 'REVAYAT_OTP_WEBHOOK_TOKEN', 'webhook_template' => 'REVAYAT_OTP_WEBHOOK_TEMPLATE' );
		return $aliases[ $key ] ?? 'REVAYAT_' . strtoupper( $key );
	}
	public static function get( string $key ) {
		$constant = self::constant_name( $key );
		return defined( $constant ) ? constant( $constant ) : ( self::all()[ $key ] ?? '' );
	}
	private static function fields(): array {
		$groups = array(
			'common' => array( 'provider' => 'درگاه پیش‌فرض', 'admin_numbers' => 'موبایل مدیران دریافت‌کننده (حداکثر ۵ شماره)', 'enabled_access' => 'اعلان وضعیت دسترسی به کاربر', 'enabled_post' => 'اعلان وضعیت یادداشت به کاربر', 'admin_access' => 'اطلاع درخواست تازه دسترسی به مدیر', 'admin_post' => 'اطلاع یادداشت در انتظار بررسی به مدیر' ),
			'smsir' => array( 'smsir_api_key' => 'کلید API', 'smsir_parameter' => 'نام متغیر کد (سازگاری قبلی)', 'smsir_line_number' => 'خط اختیاری؛ در ارسال قالبی مصرف نمی‌شود', 'smsir_credit_warning' => 'آستانه هشدار اعتبار (۰: خاموش)', 'smsir_admin_access_id' => 'شناسه قالب اختصاصی مدیر: درخواست دسترسی (اختیاری)', 'smsir_admin_post_id' => 'شناسه قالب اختصاصی مدیر: یادداشت تازه (اختیاری)' ),
			'ippanel' => array( 'ippanel_api_key' => 'کلید API', 'ippanel_sender' => 'شماره فرستنده مجاز با +98', 'ippanel_credit_warning' => 'آستانه هشدار اعتبار (۰: خاموش)', 'ippanel_admin_access_id' => 'کد پترن اختصاصی مدیر: درخواست دسترسی (اختیاری)', 'ippanel_admin_post_id' => 'کد پترن اختصاصی مدیر: یادداشت تازه (اختیاری)' ),
			'webhook' => array( 'webhook_url' => 'نشانی HTTPS', 'webhook_token' => 'توکن', 'webhook_template' => 'شناسه قالب' ),
			'support' => array( 'support_text' => 'متن راهنمای پشتیبانی در فرم‌ها', 'support_bale' => 'لینک یا شناسه پشتیبانی بله (Bale)', 'support_eitaa' => 'لینک یا شناسه پشتیبانی ایتا (Eitaa)', 'support_telegram' => 'لینک یا شناسه پشتیبانی تلگرام (Telegram)' ),
		);
		foreach ( Revayat_Companion_SMS_Service::contexts() as $context => $label ) {
			$groups['common'][ 'route_' . $context ] = 'درگاه ' . $label;
			foreach ( array( 'smsir', 'ippanel' ) as $provider ) {
				$id = 'smsir' === $provider && 'auth' === $context ? 'smsir_register_id' : $provider . '_' . $context . '_id';
				$groups[ $provider ][ $id ] = ( 'smsir' === $provider ? 'شناسه قالب ' : 'کد پترن ' ) . $label;
				$groups[ $provider ][ $provider . '_map_' . $context ] = 'نگاشت متغیرهای ' . $label;
			}
		}
		return $groups;
	}
	public static function register(): void {
		register_setting( 'revayat_sms', self::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( __CLASS__, 'sanitize' ), 'default' => self::defaults() ) );
		add_action( 'wp_ajax_revayat_sms_tool', array( __CLASS__, 'tool' ) );
		add_action( 'wp_ajax_revayat_test_smsir_connection', array( __CLASS__, 'test_connection' ) );
		add_action( 'admin_notices', array( __CLASS__, 'credit_notice' ) );
		foreach ( self::fields() as $group => $fields ) {
			$page = 'revayat-sms-' . $group;
			add_settings_section( $page, '', '__return_false', $page );
			foreach ( $fields as $key => $label ) { add_settings_field( $key, $label, array( __CLASS__, 'field' ), $page, $page, array( 'key' => $key, 'label_for' => 'rv-sms-' . $key ) ); }
		}
	}
	private static string $hook_suffix = '';

	public static function menu(): void {
		self::$hook_suffix = (string) add_options_page( 'تنظیمات پیامک روایت ایران', 'پیامک روایت ایران', 'manage_options', 'revayat-sms', array( __CLASS__, 'page' ) );
	}
	public static function enqueue( $hook ): void {
		if ( $hook !== self::$hook_suffix && false === strpos( (string) $hook, 'revayat-sms' ) ) { return; }
		$base = REVAYAT_COMPANION_PATH . 'assets/';
		$url  = REVAYAT_COMPANION_URL . 'assets/';
		wp_enqueue_script( 'revayat-sms-settings', $url . 'sms-settings.js', array(), filemtime( $base . 'sms-settings.js' ), true );
		wp_enqueue_style( 'revayat-sms-settings', $url . 'sms-settings.css', array(), filemtime( $base . 'sms-settings.css' ) );
		wp_localize_script( 'revayat-sms-settings', 'revayatSMSTools', array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'revayat_sms_tools' ) ) );
	}
	public static function credit_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$alert = get_transient( 'revayat_sms_low_credit_alert' );
		if ( $alert && is_array( $alert ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p><strong>هشدار پیامک روایت ایران:</strong> اعتبار پنل ' . esc_html( $alert['provider_label'] ?? '' ) . ' به ' . esc_html( (string) ( $alert['credit'] ?? '' ) ) . ' کاهش یافته است (کمتر از آستانه هشدار ' . esc_html( (string) ( $alert['threshold'] ?? '' ) ) . '). لطفاً نسبت به شارژ حساب اقدام فرمایید.</p></div>';
		}
	}
	public static function sanitize( $input ): array {
		$old = self::all();
		foreach ( is_array( $input ) ? $input : array() as $key => $value ) {
			if ( ! array_key_exists( $key, self::defaults() ) || ! is_scalar( $value ) ) { continue; }
			$value = (string) $value;
			if ( in_array( $key, array( 'smsir_api_key', 'ippanel_api_key', 'webhook_token' ), true ) ) { $old[ $key ] = sanitize_text_field( $value ); }
			elseif ( 'provider' === $key || 0 === strpos( $key, 'route_' ) ) { $old[ $key ] = isset( self::providers()[ $value ] ) ? $value : ( 'provider' === $key ? 'disabled' : '' ); }
			elseif ( 0 === strpos( $key, 'enabled_' ) || in_array( $key, array( 'admin_access', 'admin_post' ), true ) ) { $old[ $key ] = empty( $value ) ? 0 : 1; }
			elseif ( 'admin_numbers' === $key ) {
				$numbers = array_map( array( 'Revayat_Companion_User_Portal', 'normalize_mobile' ), preg_split( '/[\s,]+/', $value ) );
				$old[ $key ] = implode( "\n", array_slice( array_unique( array_filter( $numbers, function( $number ) { return preg_match( '/^09\d{9}$/', $number ); } ) ), 0, 5 ) );
			}
			elseif ( false !== strpos( $key, '_map_' ) ) {
				$context = substr( $key, strpos( $key, '_map_' ) + 5 ); $lines = array();
				foreach ( preg_split( '/[\r\n,]+/', $value ) as $line ) {
					$pair = array_map( 'trim', explode( '=', $line, 2 ) );
					if ( 2 === count( $pair ) && in_array( $pair[0], Revayat_Companion_SMS_Service::variables( $context ), true ) && preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $pair[1] ) ) { $lines[] = implode( '=', $pair ); }
				}
				$old[ $key ] = implode( "\n", $lines );
			}
			elseif ( false !== strpos( $key, '_credit_warning' ) ) { $old[ $key ] = max( 0, (float) $value ); }
			elseif ( 'smsir' === substr( $key, 0, 5 ) && '_id' === substr( $key, -3 ) ) { $old[ $key ] = absint( $value ); }
			elseif ( 'webhook_url' === $key ) { $old[ $key ] = esc_url_raw( $value, array( 'https' ) ); }
			elseif ( 'ippanel_sender' === $key ) { $old[ $key ] = preg_replace( '/[^+0-9]/', '', $value ); }
			elseif ( 'smsir_parameter' === $key ) { $old[ $key ] = preg_replace( '/[^A-Za-z0-9_]/', '', $value ) ?: 'Code'; }
			elseif ( in_array( $key, array( 'support_bale', 'support_eitaa', 'support_telegram' ), true ) ) { $old[ $key ] = esc_url_raw( $value ); }
			else { $old[ $key ] = sanitize_text_field( $value ); }
		}
		return $old;
	}
	public static function field( array $args ): void {
		$key = $args['key']; $value = self::all()[ $key ] ?? ''; $name = self::OPTION . '[' . $key . ']'; $id = 'rv-sms-' . $key;
		$attrs = ' id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"';
		if ( 'provider' === $key || 0 === strpos( $key, 'route_' ) ) {
			echo '<select' . $attrs . '>';
			$options = 'provider' === $key ? self::providers() : array( '' => 'همان درگاه پیش‌فرض' ) + self::providers();
			foreach ( $options as $option => $label ) { echo '<option value="' . esc_attr( $option ) . '" ' . selected( $value, $option, false ) . '>' . esc_html( $label ) . '</option>'; }
			echo '</select>'; return;
		}
		if ( 0 === strpos( $key, 'enabled_' ) || in_array( $key, array( 'admin_access', 'admin_post' ), true ) ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0"><input type="checkbox"' . $attrs . ' value="1" ' . checked( $value, 1, false ) . '> فعال'; return;
		}
		if ( false !== strpos( $key, '_map_' ) || 'admin_numbers' === $key ) {
			echo '<textarea class="regular-text" dir="ltr" rows="3"' . $attrs . '>' . esc_textarea( $value ) . '</textarea>';
			if ( false !== strpos( $key, '_map_' ) ) { echo '<p class="description">هر خط: نام داخلی=نام دقیق متغیر در پنل پیامک. مثال: Code=code؛ حروف بزرگ و کوچک مهم‌اند.</p>'; }
		} else {
			$secret = in_array( $key, array( 'smsir_api_key', 'ippanel_api_key', 'webhook_token' ), true );
			$numeric = ( 0 === strpos( $key, 'smsir_' ) && '_id' === substr( $key, -3 ) ) || false !== strpos( $key, '_credit_warning' );
			echo '<div class="rv-sms-input-wrap">';
			echo '<input class="regular-text" dir="ltr" type="' . ( $secret ? 'password' : ( $numeric ? 'number' : 'text' ) ) . '"' . $attrs . ' value="' . esc_attr( $value ) . '" autocomplete="new-password"' . ( $numeric ? ' min="0" step="any"' : '' ) . '>';
			if ( $secret ) {
				echo ' <button type="button" class="button button-secondary rv-sms-toggle-visibility" data-target="' . esc_attr( $id ) . '" title="نمایش/مخفی‌سازی مقدار">👁 نمایش</button>';
				echo '<p class="description">' . ( $value ? 'مقدار کلید در سیستم ذخیره شده است. می‌توانید با دکمه بالا آن را مشاهده یا ویرایش کنید.' : 'کلید هنوز تنظیم نشده است.' ) . '</p>';
			}
			echo '</div>';
		}
		if ( '_recover_id' === substr( $key, -11 ) || false !== strpos( $key, '_admin_' ) ) { echo '<p class="description">اختیاری؛ خالی یا صفر باشد از قالب عمومی همان بخش استفاده می‌شود.</p>'; }
		if ( defined( self::constant_name( $key ) ) ) { echo '<p class="description">مقدار سرور اولویت دارد: <code>' . esc_html( self::constant_name( $key ) ) . '</code></p>'; }
	}
	private static function respond( $result ): void {
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ) ); }
		wp_send_json_success( $result );
	}
	public static function test_connection(): void {
		check_ajax_referer( 'revayat_smsir_test_connection', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 ); }
		self::respond( Revayat_Companion_SMS_Service::connection( 'smsir', sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) ) ) );
	}
	public static function tool(): void {
		check_ajax_referer( 'revayat_sms_tools', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 ); }
		$mode = sanitize_key( wp_unslash( $_POST['mode'] ?? '' ) );
		$provider = sanitize_key( wp_unslash( $_POST['provider'] ?? '' ) );
		$context = sanitize_key( wp_unslash( $_POST['context'] ?? 'auth' ) );
		if ( ! isset( self::providers()[ $provider ], Revayat_Companion_SMS_Service::contexts()[ $context ] ) ) { self::respond( new WP_Error( 'invalid_tool', 'سرویس یا کاربرد معتبر نیست.' ) ); }
		if ( 'connection' === $mode ) {
			$result = Revayat_Companion_SMS_Service::connection( $provider, sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) ) );
			$threshold = (float) self::get( $provider . '_credit_warning' );
			if ( ! is_wp_error( $result ) && $threshold > 0 && null !== $result['credit'] && $result['credit'] < $threshold ) { $result['message'] .= ' هشدار: اعتبار کمتر از آستانه تنظیم‌شده است.'; }
			self::respond( $result );
		}
		if ( 'pattern' === $mode ) { self::respond( Revayat_Companion_SMS_Service::pattern( $provider, $context ) ); }
		$entry = sanitize_text_field( wp_unslash( $_POST['entry'] ?? '' ) );
		if ( 'report' === $mode ) { self::respond( Revayat_Companion_SMS_Service::report( $entry ) ); }
		if ( ! in_array( $mode, array( 'send_test', 'retry' ), true ) || '1' !== ( $_POST['confirm'] ?? '' ) ) { self::respond( new WP_Error( 'sms_confirmation', 'برای ارسال واقعی، تأیید هزینه و مالکیت شماره لازم است.' ) ); }
		$limit = 'revayat_sms_admin_test_' . get_current_user_id();
		if ( get_transient( $limit ) ) { self::respond( new WP_Error( 'sms_test_limit', 'بین دو ارسال آزمایشی ۳۰ ثانیه فاصله بگذارید.' ) ); }
		set_transient( $limit, 1, 30 );
		$result = 'retry' === $mode ? Revayat_Companion_SMS_Service::retry( $entry ) : Revayat_Companion_SMS_Service::send( wp_unslash( $_POST['mobile'] ?? '' ), $context, array( 'Code' => (string) wp_rand( 100000, 999999 ), 'Name' => 'مدیر سایت', 'Request' => 'درخواست آزمایشی', 'Title' => 'یادداشت آزمایشی', 'Status' => 'تأیید' ), $provider );
		self::respond( is_wp_error( $result ) ? $result : array( 'message' => 'ارسال توسط سرویس پذیرفته شد؛ دریافت روی گوشی را جداگانه بررسی کنید.' ) );
	}
	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		require __DIR__ . '/sms-settings-view.php';
	}
}
