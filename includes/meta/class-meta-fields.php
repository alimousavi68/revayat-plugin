<?php
/**
 * ثبت و مدیریت فیلدهای متای سفارشی (Custom Meta Fields Registry)
 *
 * چرا این فایل لازم است؟
 * جهت ثبت استاندارد فیلدهای متای بومی وردپرس (Custom Fields) با استفاده از register_post_meta()
 * و تعریف دقیق انواع داده، قوانین پاکسازی و دسترس‌پذیری در REST API بدون وابستگی به افزونه‌های سنگین خارجی (مانند ACF).
 *
 * چه مسئولیتی دارد؟
 * ثبت فراداده‌های اختصاصی برای پست‌تایپ‌های ۶ گانه پایگاه:
 * - situation_room: بولتن، فوریت، وضعیت صحت‌سنجی، زمان به‌روزرسانی و وضعیت قفل
 * - special_dossier: وضعیت پوشش، کد پرونده و آمارهای مستند، فریم و یادداشت
 * - media_observatory: متن فریم‌های تقابلی، خلاصه تحلیل و منبع رصد
 * - multimedia: نوع رسانه، مدت‌زمان و آدرس ویدیو
 * - analyst_post: نوع تحلیل، نقل‌قول ویژه و امتیاز تحلیلی
 * - person: عنوان جایگاه، سازمان متبوع، تخصص، شناسه آواتار و امتیاز کل
 *
 * ارتباط با معماری:
 * تحقق لایه متادیتای افزونه مطابق سند معماری docs/architecture/meta-fields.md با پیشوند امن _revayat_.
 *
 * @package Revayat_Companion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Revayat_Companion_Meta_Fields' ) ) {

	/**
	 * کلاس مدیریت و ثبت فیلدهای متای اختصاصی
	 */
	class Revayat_Companion_Meta_Fields {

		/**
		 * ثبت کلیه متادیتاها در هوک init وردپرس
		 *
		 * @return void
		 */
		public function register() {
			$this->register_situation_room_meta();
			$this->register_special_dossier_meta();
			$this->register_media_observatory_meta();
			$this->register_multimedia_meta();
			$this->register_analyst_post_meta();
			$this->register_person_meta();
		}

		/**
		 * بررسی سطح دسترسی کاربر برای ویرایش فیلد متا
		 *
		 * @param bool   $allowed   وضعیت پیش‌فرض مجاز بودن.
		 * @param string $meta_key  کلید متا.
		 * @param int    $post_id   شناسه پست.
		 * @param int    $user_id   شناسه کاربر.
		 * @param string $cap       قابلیت درخواستی.
		 * @param array  $caps      قابلیت‌های مورد نیاز.
		 * @return bool
		 */
		public function auth_edit_post_meta( $allowed, $meta_key, $post_id, $user_id, $cap, $caps ) {
			if ( $post_id ) {
				return current_user_can( 'edit_post', $post_id );
			}
			return current_user_can( 'edit_posts' );
		}

		/**
		 * پاکسازی مقادیر عددی اعشاری (سازگار با PHP 8+)
		 *
		 * @param mixed $meta_value مقدار خام متا.
		 * @return float
		 */
		public static function sanitize_float( $meta_value ) {
			return (float) $meta_value;
		}

		/**
		 * ثبت فیلدهای متای اتاق وضعیت (situation_room)
		 *
		 * @return void
		 */
		protected function register_situation_room_meta() {
			$post_type = 'situation_room';

			register_post_meta(
				$post_type,
				'_revayat_bulletin_type',
				array(
					'type'              => 'string',
					'description'       => 'گونه بولتن تحلیلی (عادی، ویژه، پدافندی)',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_urgency_level',
				array(
					'type'              => 'string',
					'description'       => 'سطح فوریت بولتن (عادی، فوری، آنی، بحرانی)',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_verification_status',
				array(
					'type'              => 'string',
					'description'       => 'وضعیت تایید و صحت‌سنجی اطلاعات',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_update_time',
				array(
					'type'              => 'string',
					'description'       => 'متن زمان آخرین به‌روزرسانی یا ساعت رویداد',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_sr_is_locked',
				array(
					'type'              => 'boolean',
					'description'       => 'آیا محتوای بولتن نیازمند احراز هویت / قفل است؟',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'rest_sanitize_boolean',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_sr_auth_url',
				array(
					'type'              => 'string',
					'description'       => 'لینک اختصاصی ارتقای سطح دسترسی کاربر',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'esc_url_raw',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);
		}

		/**
		 * ثبت فیلدهای متای پرونده‌های ویژه (special_dossier)
		 *
		 * @return void
		 */
		protected function register_special_dossier_meta() {
			$post_type = 'special_dossier';

			register_post_meta(
				$post_type,
				'_revayat_lead_status',
				array(
					'type'              => 'string',
					'description'       => 'وضعیت پرونده (پوشش زنده، در جریان، تکمیل‌شده)',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_dossier_code',
				array(
					'type'              => 'string',
					'description'       => 'کد یا شناسه مرجع پرونده تحلیلی',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_stats_documents',
				array(
					'type'              => 'integer',
					'description'       => 'تعداد اسناد و مدارک پیوست پرونده',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'absint',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_stats_frames',
				array(
					'type'              => 'integer',
					'description'       => 'تعداد فریم‌های مستند پرونده',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'absint',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_stats_notes',
				array(
					'type'              => 'integer',
					'description'       => 'تعداد یادداشت‌های تحلیلی پیوست پرونده',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'absint',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_dossier_is_live',
				array(
					'type'              => 'boolean',
					'description'       => 'آیا پرونده در حال پوشش زنده است؟',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'rest_sanitize_boolean',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_dossier_last_update',
				array(
					'type'              => 'string',
					'description'       => 'متن زمان آخرین به‌روزرسانی پرونده',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_dossier_editor_lead',
				array(
					'type'              => 'string',
					'description'       => 'نام مسئول یا گروه تحریریه پرونده',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);
		}

		/**
		 * ثبت فیلدهای متای دیده‌بان رسانه (media_observatory)
		 *
		 * @return void
		 */
		protected function register_media_observatory_meta() {
			$post_type = 'media_observatory';

			register_post_meta(
				$post_type,
				'_revayat_frame_a',
				array(
					'type'              => 'string',
					'description'       => 'متن فریم اول واکاوی رسانه‌ای',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_textarea_field',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_frame_b',
				array(
					'type'              => 'string',
					'description'       => 'متن فریم دوم واکاوی رسانه‌ای (جهت مقایسه تقابلی)',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_textarea_field',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_narrative_summary',
				array(
					'type'              => 'string',
					'description'       => 'چکیده و جمع‌بندی تحلیل روایت',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_textarea_field',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_monitoring_source',
				array(
					'type'              => 'string',
					'description'       => 'منبع رصد شده اصلی',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_obs_frame_a_source',
				array(
					'type'              => 'string',
					'description'       => 'نام رسانه یا منبع فریم اول',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_obs_frame_b_source',
				array(
					'type'              => 'string',
					'description'       => 'نام رسانه یا منبع فریم دوم',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);
		}

		/**
		 * ثبت فیلدهای متای چندرسانه‌ای (multimedia)
		 *
		 * @return void
		 */
		protected function register_multimedia_meta() {
			$post_type = 'multimedia';

			register_post_meta(
				$post_type,
				'_revayat_media_type',
				array(
					'type'              => 'string',
					'description'       => 'نوع رسانه (video, audio, gallery)',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_duration',
				array(
					'type'              => 'string',
					'description'       => 'مدت زمان ویدیو، پادکست یا رسانه (مانند ۰۴:۱۵)',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_video_url',
				array(
					'type'              => 'string',
					'description'       => 'آدرس فایل ویدیو یا پادکست',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'esc_url_raw',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);
		}

		/**
		 * ثبت فیلدهای متای یادداشت‌های تحلیلی (analyst_post)
		 *
		 * @return void
		 */
		protected function register_analyst_post_meta() {
			$post_type = 'analyst_post';

			register_post_meta(
				$post_type,
				'_revayat_analysis_type',
				array(
					'type'              => 'string',
					'description'       => 'نوع یادداشت تحلیلی (راهبردی، دیدگاه، سرمقاله)',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_featured_quote',
				array(
					'type'              => 'string',
					'description'       => 'نقل‌قول طلایی یا فراز کلیدی یادداشت برای نمایش در کارت',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_textarea_field',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_analyst_score',
				array(
					'type'              => 'number',
					'description'       => 'امتیاز کیفی یادداشت تحلیلی',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => array( 'Revayat_Companion_Meta_Fields', 'sanitize_float' ),
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);
		}

		/**
		 * ثبت فیلدهای متای پروفایل اشخاص (person)
		 *
		 * @return void
		 */
		protected function register_person_meta() {
			$post_type = 'person';

			register_post_meta(
				$post_type,
				'_revayat_role_title',
				array(
					'type'              => 'string',
					'description'       => 'سمت یا عنوان تخصصی شخص (مثلاً: پژوهشگر ارشد)',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_organization',
				array(
					'type'              => 'string',
					'description'       => 'سازمان، اندیشکده یا مرکز پژوهشی متبوع',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_expertise',
				array(
					'type'              => 'string',
					'description'       => 'حوزه تخصصی و موضوعات مطالعاتی کارشناس',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_avatar_id',
				array(
					'type'              => 'integer',
					'description'       => 'شناسه اتچمنت تصویر آواتار اختصاصی شخص',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'absint',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_person_total_score',
				array(
					'type'              => 'number',
					'description'       => 'امتیاز تحلیلی میانگین کارشناس',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => array( 'Revayat_Companion_Meta_Fields', 'sanitize_float' ),
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_person_votes',
				array(
					'type'              => 'integer',
					'description'       => 'مجموع آرای کسب‌شده شخص در پلتفرم',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'absint',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);
		}
	}
}
