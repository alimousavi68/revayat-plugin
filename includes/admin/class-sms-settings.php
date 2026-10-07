<?php
/** SMS provider settings managed through the native WordPress Settings API. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Revayat_Companion_SMS_Settings {
	const OPTION = 'revayat_sms_settings';

	public static function defaults(): array {
		return array(
			'provider'             => 'smsir',
			'smsir_api_key'        => '',
			'smsir_register_id'    => 0,
			'smsir_recover_id'     => 0,
			'smsir_parameter'      => 'Code',
			'smsir_line_number'    => '',
			'webhook_url'          => '',
			'webhook_token'        => '',
			'webhook_template'     => '',
		);
	}

	public static function all(): array {
		$value = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $value ) ? $value : array(), self::defaults() );
	}

	public static function get( string $key ) {
		$constants = array(
			'smsir_api_key'     => 'REVAYAT_SMSIR_API_KEY',
			'smsir_register_id' => 'REVAYAT_SMSIR_REGISTER_TEMPLATE_ID',
			'smsir_recover_id'  => 'REVAYAT_SMSIR_RECOVER_TEMPLATE_ID',
			'smsir_parameter'   => 'REVAYAT_SMSIR_CODE_PARAMETER',
			'webhook_url'       => 'REVAYAT_OTP_WEBHOOK_URL',
			'webhook_token'     => 'REVAYAT_OTP_WEBHOOK_TOKEN',
			'webhook_template'  => 'REVAYAT_OTP_WEBHOOK_TEMPLATE',
		);
		if ( isset( $constants[ $key ] ) && defined( $constants[ $key ] ) ) {
			return constant( $constants[ $key ] );
		}
		$settings = self::all();
		return $settings[ $key ] ?? '';
	}

	public static function register(): void {
		register_setting( 'revayat_sms', self::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( __CLASS__, 'sanitize' ), 'default' => self::defaults() ) );
		add_settings_section( 'revayat_sms_delivery', 'ارسال کد یک‌بارمصرف', array( __CLASS__, 'section' ), 'revayat-sms' );
		$fields = array(
			'provider'          => 'روش ارسال',
			'smsir_api_key'     => 'کلید API سرویس SMS.ir',
			'smsir_register_id' => 'شناسه قالب ثبت‌نام SMS.ir',
			'smsir_recover_id'  => 'شناسه قالب بازیابی SMS.ir',
			'smsir_parameter'   => 'نام پارامتر کد در قالب',
			'smsir_line_number' => 'شماره خط / هویت ارسال‌کننده',
			'webhook_url'       => 'نشانی webhook عمومی',
			'webhook_token'     => 'توکن webhook عمومی',
			'webhook_template'  => 'شناسه قالب webhook عمومی',
		);
		foreach ( $fields as $key => $label ) {
			add_settings_field( $key, $label, array( __CLASS__, 'field' ), 'revayat-sms', 'revayat_sms_delivery', array( 'key' => $key ) );
		}
	}

	public static function menu(): void {
		add_options_page( 'تنظیمات پیامک روایت ایران', 'پیامک روایت ایران', 'manage_options', 'revayat-sms', array( __CLASS__, 'page' ) );
	}

	public static function sanitize( $input ): array {
		$old = self::all();
		$input = is_array( $input ) ? $input : array();
		$provider = sanitize_key( $input['provider'] ?? 'disabled' );
		if ( ! in_array( $provider, array( 'smsir', 'webhook', 'disabled' ), true ) ) { $provider = 'disabled'; }
		$api_key = sanitize_text_field( $input['smsir_api_key'] ?? '' );
		$webhook_token = sanitize_text_field( $input['webhook_token'] ?? '' );
		return array(
			'provider'          => $provider,
			'smsir_api_key'     => '' === $api_key ? $old['smsir_api_key'] : $api_key,
			'smsir_register_id' => absint( $input['smsir_register_id'] ?? 0 ),
			'smsir_recover_id'  => absint( $input['smsir_recover_id'] ?? 0 ),
			'smsir_parameter'   => preg_replace( '/[^A-Za-z0-9_]/', '', (string) ( $input['smsir_parameter'] ?? 'Code' ) ) ?: 'Code',
			'smsir_line_number' => preg_replace( '/\D+/', '', (string) ( $input['smsir_line_number'] ?? '' ) ),
			'webhook_url'       => esc_url_raw( $input['webhook_url'] ?? '' ),
			'webhook_token'     => '' === $webhook_token ? $old['webhook_token'] : $webhook_token,
			'webhook_template'  => sanitize_text_field( $input['webhook_template'] ?? '' ),
		);
	}

	public static function section(): void {
		echo '<p>برای SMS.ir از ارسال سریع مبتنی بر قالب استفاده می‌شود. در پنل SMS.ir برای ثبت‌نام و بازیابی قالب بسازید و نام پارامتر کد را دقیقاً مطابق قالب وارد کنید.</p>';
		echo '<p><strong>نکته:</strong> شماره خط در endpoint ارسال سریع OTP استفاده نمی‌شود و فقط برای شفافیت هویت حساب/ارسال‌های آینده نگهداری می‌شود. تعریف constantهای سرور بر مقدار پنل اولویت دارد.</p>';
	}

	public static function field( array $args ): void {
		$key = $args['key'];
		$value = self::all()[ $key ] ?? '';
		$name = self::OPTION . '[' . $key . ']';
		if ( 'provider' === $key ) {
			echo '<select name="' . esc_attr( $name ) . '">';
			foreach ( array( 'smsir' => 'SMS.ir (ارسال سریع قالبی)', 'webhook' => 'Webhook عمومی', 'disabled' => 'غیرفعال' ) as $option => $label ) {
				echo '<option value="' . esc_attr( $option ) . '" ' . selected( $value, $option, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select>';
			return;
		}
		$type = in_array( $key, array( 'smsir_api_key', 'webhook_token' ), true ) ? 'password' : ( false !== strpos( $key, '_id' ) ? 'number' : 'text' );
		$shown = 'password' === $type && $value ? '' : $value;
		echo '<input class="regular-text" type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $shown ) . '" autocomplete="off" placeholder="' . esc_attr( 'password' === $type && $value ? 'برای حفظ مقدار فعلی خالی بگذارید' : '' ) . '">';
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		?>
		<div class="wrap"><h1>تنظیمات پیامک روایت ایران</h1><form method="post" action="options.php">
			<?php settings_fields( 'revayat_sms' ); do_settings_sections( 'revayat-sms' ); submit_button(); ?>
		</form></div>
		<?php
	}
}
