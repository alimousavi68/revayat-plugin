<?php
/** Admin-only settings view; secrets are deliberately never rendered. */
if ( ! defined( 'ABSPATH' ) || ! current_user_can( 'manage_options' ) ) { exit; }
$tabs = array( 'common' => 'عمومی و اعلان‌ها', 'smsir' => 'SMS.ir', 'ippanel' => 'مدیرپیامک', 'webhook' => 'Webhook', 'tools' => 'آزمون و گزارش' );
?>
<div class="wrap revayat-sms-settings">
	<h1>پیامک روایت ایران</h1>
	<p>تنظیمات هر سرویس جدا ذخیره می‌شود. تغییر درگاه دستی است؛ اعلان‌ها تا فعال‌کردن شما ارسال نمی‌شوند.</p>
	<nav class="nav-tab-wrapper" aria-label="تنظیمات پیامک">
		<?php foreach ( $tabs as $key => $label ) : ?>
			<a class="nav-tab" data-sms-tab="<?php echo esc_attr( $key ); ?>" href="#rv-sms-panel-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
	</nav>
	<form method="post" action="options.php">
		<?php settings_fields( 'revayat_sms' ); ?>
		<?php foreach ( array( 'common', 'smsir', 'ippanel', 'webhook' ) as $group ) : ?>
			<section id="rv-sms-panel-<?php echo esc_attr( $group ); ?>" data-sms-panel="<?php echo esc_attr( $group ); ?>">
				<h2><?php echo esc_html( $tabs[ $group ] ); ?></h2>
				<?php do_settings_sections( 'revayat-sms-' . $group ); ?>
			</section>
		<?php endforeach; ?>
		<?php submit_button( 'ذخیره تنظیمات همه سرویس‌ها' ); ?>
	</form>
	<section id="rv-sms-panel-tools" data-sms-panel="tools">
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
