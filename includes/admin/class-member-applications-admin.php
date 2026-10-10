<?php
/** Review private applications without exposing them through the post editor or REST. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Revayat_Companion_Member_Applications_Admin {
	public static function menu() { add_users_page( 'درخواست‌های اعضا', 'درخواست‌های اعضا', 'revayat_manage_approvals', 'revayat-member-applications', array( __CLASS__, 'render' ) ); }
	public static function settings() {
		register_setting( 'revayat_private_documents', 'revayat_private_storage_path', array( 'sanitize_callback' => array( __CLASS__, 'storage_path' ) ) );
		register_setting( 'revayat_private_documents', 'revayat_document_retention_days', array( 'sanitize_callback' => static function ( $n ) { return min( 365, absint( $n ) ); } ) );
		register_setting( 'revayat_private_documents', 'revayat_document_draft_days', array( 'sanitize_callback' => static function ( $n ) { return min( 90, absint( $n ) ); } ) );
	}
	public static function storage_path( $value ) {
		$value = sanitize_text_field( $value );
		if ( ! current_user_can( 'manage_options' ) ) { return get_option( 'revayat_private_storage_path', '' ); }
		$effective = defined( 'REVAYAT_PRIVATE_STORAGE_PATH' ) ? REVAYAT_PRIVATE_STORAGE_PATH : $value;
		$result = Revayat_Companion_Private_Documents::check_directory( $effective, true );
		if ( is_wp_error( $result ) ) { add_settings_error( 'revayat_private_documents', $result->get_error_code(), $result->get_error_message() ); }
		return $value;
	}
	public static function decide() {
		if ( ! current_user_can( 'revayat_manage_approvals' ) ) { wp_die( 'دسترسی غیرمجاز', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'rv_application_review' );
		$result = Revayat_Companion_Member_Applications::review( absint( $_POST['request_id'] ?? 0 ), sanitize_key( $_POST['decision'] ?? '' ), wp_unslash( $_POST['reason'] ?? '' ), absint( $_POST['version'] ?? 0 ), get_current_user_id() );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'back_link' => true, 'response' => 409 ) ); }
		wp_safe_redirect( admin_url( 'users.php?page=revayat-member-applications' ) ); exit;
	}
	public static function render() {
		if ( ! current_user_can( 'revayat_manage_approvals' ) ) { return; }
		$page = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$filter = sanitize_key( $_GET['state'] ?? 'pending' );
		$args = array( 'post_type' => 'rv_application', 'post_status' => 'private', 'posts_per_page' => 15, 'paged' => $page, 'orderby' => 'ID', 'order' => 'DESC' );
		if ( isset( Revayat_Companion_Member_Applications::labels()[ $filter ] ) ) { $args['meta_query'] = array( array( 'key' => '_rv_state', 'value' => $filter ) ); }
		$query = new WP_Query( $args ); ?>
		<div class="wrap"><h1>درخواست‌های اعضا</h1><p>مدارک هویتی فقط برای تصمیم همین درخواست قابل دریافت‌اند؛ مشاهده و دریافت ثبت می‌شود.</p>
		<form method="get"><input type="hidden" name="page" value="revayat-member-applications"><label>وضعیت <select name="state"><option value="all">همه</option><?php foreach ( Revayat_Companion_Member_Applications::labels() as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filter, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label> <button class="button">نمایش</button></form>
		<?php if ( ! $query->posts ) : ?><p>درخواستی در این وضعیت وجود ندارد.</p><?php endif; ?>
		<?php foreach ( $query->posts as $post ) : $data = (array) get_post_meta( $post->ID, '_rv_payload', true ); $state = Revayat_Companion_Member_Applications::state( $post ); $type = get_post_meta( $post->ID, '_rv_type', true ); ?>
		<hr><section><h2><?php echo esc_html( ( 'analyst' === $type ? 'تحلیلگری' : 'اتاق وضعیت' ) . ' — ' . ( $data['full_name'] ?? $post->post_author ) ); ?></h2><p><?php echo esc_html( Revayat_Companion_Member_Applications::labels()[ $state ] ); ?></p><dl><?php foreach ( $data as $key => $value ) : if ( ! $value ) { continue; } ?><dt><strong><?php echo esc_html( array( 'full_name' => 'نام کامل', 'role_title' => 'سمت', 'organization' => 'سازمان', 'expertise' => 'تخصص', 'bio' => 'معرفی', 'sample_url' => 'نمونه تحلیل', 'reason' => 'دلیل درخواست' )[ $key ] ?? $key ); ?></strong></dt><dd><?php echo esc_html( $value ); ?></dd><?php endforeach; ?></dl>
		<?php if ( 'situation' === $type && function_exists( 'sodium_crypto_secretbox_open' ) ) : $national = Revayat_Companion_Private_Documents::decrypt( get_post_meta( $post->ID, '_rv_national_id', true ) ); ?><p>کد ملی: <bdi><?php echo esc_html( $national ?: 'حذف شده / ثبت نشده' ); ?></bdi></p><?php endif; ?>
		<?php foreach ( (array) get_post_meta( $post->ID, '_rv_documents', true ) as $field => $token ) : if ( ! $token ) { continue; } ?><p><a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'revayat_private_document', 'request_id' => $post->ID, 'field' => $field ), admin_url( 'admin-post.php' ) ), 'rv_document_' . $post->ID . '_' . $field ) ); ?>"><?php echo 'portrait' === $field ? 'دریافت عکس پرسنلی' : 'دریافت کارت ملی'; ?></a></p><?php endforeach; ?>
		<details><summary>تاریخچه بررسی</summary><ul><?php foreach ( get_post_meta( $post->ID, '_rv_history', false ) as $event ) : ?><li><?php echo esc_html( $event['at'] . ' — ' . ( Revayat_Companion_Member_Applications::labels()[ $event['to'] ] ?? $event['to'] ) . ' — ' . $event['reason'] ); ?></li><?php endforeach; ?></ul></details>
		<?php if ( in_array( $state, array( 'pending', 'approved' ), true ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'rv_application_review' ); ?><input type="hidden" name="action" value="revayat_application_review"><input type="hidden" name="request_id" value="<?php echo esc_attr( $post->ID ); ?>"><input type="hidden" name="version" value="<?php echo esc_attr( get_post_meta( $post->ID, '_rv_version', true ) ); ?>"><p><label>دلیل تصمیم / توضیح قابل مشاهده متقاضی<br><textarea name="reason" rows="3" cols="70" maxlength="1000"></textarea></label></p><select name="decision"><?php foreach ( 'approved' === $state ? array( 'revoked' => 'لغو دسترسی' ) : array( 'approved' => 'تأیید', 'needs_changes' => 'درخواست اصلاح', 'rejected' => 'رد' ) as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select> <button class="button button-primary">ثبت تصمیم</button></form><?php endif; ?></section>
		<?php endforeach; echo wp_kses_post( paginate_links( array( 'total' => $query->max_num_pages, 'current' => $page ) ) ); ?>
		<?php if ( current_user_can( 'manage_options' ) ) : ?><hr><h2>فعال‌سازی مدارک خصوصی</h2><?php settings_errors( 'revayat_private_documents' ); ?><p>تا تعیین هر دو مدت نگهداری و فضای امن خارج ریشه وب، دریافت مدارک بسته است. صفر یعنی هنوز تصمیم گرفته نشده؛ هیچ پیش‌فرضی به جای تصمیم شما ذخیره نمی‌شود.</p><p>هنگام ذخیره، ساخت پوشه امن با دسترسی محدود امتحان می‌شود. نمونه مسیر روی هاست: <code dir="ltr">/home/account/private-documents</code> در کنار <code dir="ltr">public_html</code>، نه داخل آن. پوشه را به وب‌سرور Alias نکنید.</p><?php if ( defined( 'REVAYAT_PRIVATE_STORAGE_PATH' ) ) : ?><p>مسیر با ثابت <code>REVAYAT_PRIVATE_STORAGE_PATH</code> تعیین شده است؛ تغییر این فیلد مسیر فعال را عوض نمی‌کند.</p><?php endif; ?><form action="options.php" method="post"><?php settings_fields( 'revayat_private_documents' ); ?><p><label>مسیر مطلق پوشه خصوصی خارج ریشه وب <input class="large-text" dir="ltr" name="revayat_private_storage_path" value="<?php echo esc_attr( get_option( 'revayat_private_storage_path', '' ) ); ?>"></label></p><p><label>نگهداری پیش‌نویس رهاشده (روز) <input type="number" min="0" max="90" name="revayat_document_draft_days" value="<?php echo esc_attr( get_option( 'revayat_document_draft_days', 0 ) ); ?>"></label></p><p><label>نگهداری پس از بسته‌شدن درخواست (روز) <input type="number" min="0" max="365" name="revayat_document_retention_days" value="<?php echo esc_attr( get_option( 'revayat_document_retention_days', 0 ) ); ?>"></label></p><?php submit_button(); ?></form><p><?php $issues = Revayat_Companion_Private_Documents::issues(); echo $issues ? 'دریافت مدارک غیرفعال است؛ علت:' : 'دریافت مدارک فعال است.'; ?></p><?php if ( $issues ) : ?><ul><?php foreach ( $issues as $issue ) : ?><li><?php echo esc_html( $issue ); ?></li><?php endforeach; ?></ul><?php endif; ?><p></p><?php endif; ?></div><?php
	}
}
