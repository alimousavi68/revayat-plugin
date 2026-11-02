<?php
/**
 * مدیریت متاباکس انتخاب تگ کارت در هیرو سکشن
 *
 * چرا این فایل لازم است؟
 * جهت افزودن فیلد انتخابی تگ نمایشی کارت در هیرو سکشن صفحه اصلی برای پست‌ها در پنل مدیریت وردپرس.
 *
 * چه مسئولیتی دارد؟
 * - افزودن متاباکس در نوار کناری صفحه ویرایش/افزودن نوشته
 * - ارائه گزینه‌های: «بدون تگ (پیش‌فرض)»، «فوری»، «مهم»، «ویژه»
 * - اعتبارسنجی نانس و سطح دسترسی و ذخیره مقدار در فراداده _revayat_hero_tag
 *
 * ارتباط با معماری:
 * مدیریت متادیتا در افزونه مکمل بدون وابستگی به افزونه‌های خارجی، جهت نمایش کنترل‌شده در ویترین اصلی قالب.
 *
 * @package Revayat_Companion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Revayat_Companion_Hero_Tag_Admin {

	/**
	 * گزینه‌های مجاز تگ هیرو
	 */
	public static function get_allowed_tags() {
		return array(
			''      => __( 'بدون تگ (پیش‌فرض)', 'revayat-companion' ),
			'فوری'  => __( 'فوری', 'revayat-companion' ),
			'مهم'   => __( 'مهم', 'revayat-companion' ),
			'ویژه'  => __( 'ویژه', 'revayat-companion' ),
		);
	}

	/**
	 * ثبت متاباکس در صفحه ویرایش پست
	 */
	public static function register_metabox() {
		add_meta_box(
			'revayat-hero-tag',
			__( 'تگ هیرو سکشن (صفحه اصلی)', 'revayat-companion' ),
			array( __CLASS__, 'render_metabox' ),
			array( 'post' ),
			'side',
			'default'
		);
	}

	/**
	 * رندر فیلد انتخاب تگ در متاباکس
	 *
	 * @param WP_Post $post شیء پست جاری.
	 */
	public static function render_metabox( $post ) {
		$current_tag  = (string) get_post_meta( $post->ID, '_revayat_hero_tag', true );
		$allowed_tags = self::get_allowed_tags();
		wp_nonce_field( 'revayat_save_hero_tag', 'revayat_hero_tag_nonce' );
		?>
		<p>
			<label for="revayat-hero-tag-select">
				<?php esc_html_e( 'تگ نمایشی کارت در هیرو سکشن:', 'revayat-companion' ); ?>
			</label>
		</p>
		<select id="revayat-hero-tag-select" name="revayat_hero_tag" style="width: 100%;">
			<?php foreach ( $allowed_tags as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_tag, $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description" style="margin-top: 8px;">
			<?php esc_html_e( 'در صورت انتخاب هر یک از گزینه‌ها، تگ در گوشه بالای کارت در هیرو سکشن نمایش داده می‌شود. گزینه پیش‌فرض تگی نشان نمی‌دهد.', 'revayat-companion' ); ?>
		</p>
		<?php
	}

	/**
	 * ذخیره تگ انتخابی هنگام ذخیره پست
	 *
	 * @param int     $post_id شناسه پست.
	 * @param WP_Post $post    شیء پست.
	 */
	public static function save( $post_id, $post ) {
		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type
			|| wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id )
			|| ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}

		$nonce = $_POST['revayat_hero_tag_nonce'] ?? null;
		if ( ! is_string( $nonce )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $nonce ) ), 'revayat_save_hero_tag' )
			|| ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$raw_tag = isset( $_POST['revayat_hero_tag'] ) ? sanitize_text_field( wp_unslash( $_POST['revayat_hero_tag'] ) ) : '';
		$allowed = self::get_allowed_tags();

		if ( array_key_exists( $raw_tag, $allowed ) && '' !== $raw_tag ) {
			update_post_meta( $post_id, '_revayat_hero_tag', $raw_tag );
		} else {
			delete_post_meta( $post_id, '_revayat_hero_tag' );
		}
	}
}
