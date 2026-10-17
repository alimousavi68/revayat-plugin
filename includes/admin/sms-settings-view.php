<?php
/** Admin-only settings view; secrets are deliberately never rendered. */
if ( ! defined( 'ABSPATH' ) || ! current_user_can( 'manage_options' ) ) { exit; }
$tabs = array( 'common' => 'عمومی و اعلان‌ها', 'smsir' => 'SMS.ir', 'ippanel' => 'مدیرپیامک', 'webhook' => 'Webhook', 'support' => 'پشتیبانی شبکه‌ها', 'tools' => 'آزمون و مانیتورینگ' );
$active_tab = isset( $_GET['tab'] ) && array_key_exists( sanitize_key( $_GET['tab'] ), $tabs ) ? sanitize_key( $_GET['tab'] ) : 'common';
?>
<div class="wrap revayat-sms-settings">
	<h1>پیامک روایت ایران</h1>
	<p>تنظیمات هر سرویس جدا ذخیره می‌شود. در صورت اختلال درگاه‌های پیامکی، کد تایید به‌طور خودکار از طریق ایمیل ارسال می‌شود.</p>
	<nav class="nav-tab-wrapper" aria-label="تنظیمات پیامک">
		<?php foreach ( $tabs as $key => $label ) : ?>
			<a class="nav-tab<?php echo $key === $active_tab ? ' nav-tab-active' : ''; ?>" data-sms-tab="<?php echo esc_attr( $key ); ?>" href="?page=revayat-sms&amp;tab=<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
	</nav>
	<form method="post" action="options.php">
		<?php settings_fields( 'revayat_sms' ); ?>
		<input type="hidden" name="_rv_active_tab" id="rv-sms-active-tab-field" value="<?php echo esc_attr( $active_tab ); ?>">
		<?php foreach ( array( 'common', 'smsir', 'ippanel', 'webhook', 'support' ) as $group ) : ?>
			<section id="rv-sms-panel-<?php echo esc_attr( $group ); ?>" data-sms-panel="<?php echo esc_attr( $group ); ?>"<?php echo $group !== $active_tab ? ' hidden' : ''; ?>>
				<h2><?php echo esc_html( $tabs[ $group ] ); ?></h2>
				<?php do_settings_sections( 'revayat-sms-' . $group ); ?>
			</section>
		<?php endforeach; ?>
		<?php submit_button( 'ذخیره تنظیمات همه سرویس‌ها' ); ?>
	</form>
	<section id="rv-sms-panel-tools" data-sms-panel="tools"<?php echo 'tools' !== $active_tab ? ' hidden' : ''; ?>>
		<h2>وضعیت مانیتورینگ سلامت سرویس پیامک (Circuit Breaker)</h2>
		<?php
		$sms_health = class_exists( 'Revayat_Companion_OTP_Service' ) ? Revayat_Companion_OTP_Service::get_sms_health() : array();
		$is_healthy = 'healthy' === ( $sms_health['status'] ?? 'healthy' );
		?>
		<div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:16px;margin-bottom:20px;max-width:700px;">
			<p style="margin:0 0 10px 0;font-size:14px;">
				<strong>وضعیت سامانه پیامک:</strong>
				<span style="display:inline-block;padding:3px 10px;border-radius:12px;font-weight:bold;color:#fff;background:<?php echo $is_healthy ? '#16a34a' : '#dc2626'; ?>;">
					<?php echo $is_healthy ? 'پایدار و فعال (Healthy)' : 'دارای اختلال — سوئیچ خودکار به ایمیل فعال (Degraded)'; ?>
				</span>
			</p>
			<p style="margin:0 0 8px 0;color:#64748b;font-size:13px;">تعداد خطاهای متوالی اخیر: <strong><?php echo esc_html( (string) ( $sms_health['consecutive_failures'] ?? 0 ) ); ?></strong></p>
			<?php if ( ! empty( $sms_health['last_error'] ) ) : ?>
				<p style="margin:0;color:#b91c1c;font-size:12px;">آخرین خطای ثبت‌شده در مانیتورینگ: <code><?php echo esc_html( $sms_health['last_error'] ); ?></code></p>
			<?php endif; ?>
		</div>

		<h2>آزمون اتصال و ارسال</h2>
		<p>تنظیمات را ابتدا ذخیره کنید. آزمون اتصال پیامک نمی‌فرستد؛ ارسال آزمایشی واقعی و هزینه‌دار است.</p>
		<p><label>سرویس <select id="rv-sms-test-provider"><?php foreach ( self::providers() as $key => $label ) : if ( 'disabled' === $key ) { continue; } ?><option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
		<label>کاربرد <select id="rv-sms-test-context"><?php foreach ( Revayat_Companion_SMS_Service::contexts() as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label></p>
		<p><label for="rv-sms-test-mobile">شماره موبایل خودتان</label> <input id="rv-sms-test-mobile" dir="ltr" type="tel" inputmode="tel" placeholder="09123456789" autocomplete="off"></p>
		<p><label><input id="rv-sms-test-confirm" type="checkbox"> مالک شماره هستم و ارسال واقعی و هزینه آن را تأیید می‌کنم.</label></p>
		<p><button class="button" type="button" data-sms-tool="connection">آزمون اتصال و کلید</button> <button class="button" type="button" data-sms-tool="pattern">بررسی پترن مدیرپیامک</button> <button class="button button-primary" type="button" data-sms-tool="send_test">ارسال آزمایشی</button></p>
		<p id="rv-sms-tool-result" role="status" aria-live="polite"></p>
		<h2>آخرین اعتبار بررسی‌شده</h2>
		<?php foreach ( array( 'smsir', 'ippanel' ) as $provider ) : $credit = get_option( 'revayat_sms_credit_' . $provider, array() ); ?>
			<p><?php echo esc_html( self::providers()[ $provider ] . ': ' . ( isset( $credit['credit'] ) ? (string) $credit['credit'] : 'بررسی نشده' ) . ( empty( $credit['checked_at'] ) ? '' : ' — ' . $credit['checked_at'] . ' UTC' ) ); ?></p>
		<?php endforeach; ?>
		<p class="description">واحد اعتبار همان واحد API سرویس است. هشدار آستانه هنگام آزمون اتصال بررسی می‌شود، نه به‌صورت پایش خودکار.</p>
		<h2>۱۰۰ ارسال اخیر</h2>
		<p>پذیرش ارسال با تحویل به گوشی یکسان نیست. کد ورود و کلید API در این گزارش ثبت نمی‌شود. برای OTP ناموفق کد تازه درخواست کنید.</p>
		<div class="rv-sms-table-scroll"><table class="widefat striped">
			<thead><tr><th>زمان UTC</th><th>سرویس / کاربرد</th><th>گیرنده</th><th>نتیجه</th><th>عملیات</th></tr></thead>
			<tbody><?php foreach ( Revayat_Companion_SMS_Service::log() as $entry ) : ?>
				<tr><td><?php echo esc_html( $entry['time'] ); ?></td><td><?php echo esc_html( ( self::providers()[ $entry['provider'] ] ?? $entry['provider'] ) . ' / ' . ( Revayat_Companion_SMS_Service::contexts()[ $entry['context'] ] ?? $entry['context'] ) ); ?></td><td dir="ltr"><?php echo esc_html( $entry['mobile'] ); ?></td><td><?php echo esc_html( $entry['accepted'] ? 'پذیرفته شد — ' . $entry['message_id'] : $entry['error'] ); ?></td><td>
				<?php if ( ! empty( $entry['message_id'] ) ) : ?><button class="button" type="button" data-sms-tool="report" data-entry="<?php echo esc_attr( $entry['id'] ); ?>">گزارش سرویس</button><?php endif; ?>
				<?php if ( ! empty( $entry['retry'] ) ) : ?><button class="button" type="button" data-sms-tool="retry" data-entry="<?php echo esc_attr( $entry['id'] ); ?>">تلاش مجدد اعلان</button><?php endif; ?>
				</td></tr>
			<?php endforeach; if ( ! Revayat_Companion_SMS_Service::log() ) : ?><tr><td colspan="5">هنوز ارسالی ثبت نشده است.</td></tr><?php endif; ?></tbody>
		</table></div>
	</section>
</div>
