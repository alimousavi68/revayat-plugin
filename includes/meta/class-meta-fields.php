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
		 * پاکسازی آرایه پیوست‌های بولتن اتاق وضعیت
		 *
		 * @param mixed $meta_value مقدار خام فراداده.
		 * @return array
		 */
		public static function sanitize_attachments_meta( $meta_value ) {
			if ( empty( $meta_value ) || ! is_array( $meta_value ) ) {
				return array();
			}

			$sanitized = array();
			foreach ( $meta_value as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$sanitized[] = array(
					'id'                 => absint( $item['id'] ?? 0 ),
					'title'              => sanitize_text_field( $item['title'] ?? ( $item['name'] ?? '' ) ),
					'file_name'          => sanitize_text_field( $item['file_name'] ?? ( $item['title'] ?? '' ) ),
					'file_size'          => sanitize_text_field( $item['file_size'] ?? '' ),
					'file_type'          => sanitize_key( $item['file_type'] ?? 'pdf' ),
					'url'                => esc_url_raw( $item['url'] ?? '' ),
					'is_view_only'       => ! empty( $item['is_view_only'] ),
					'is_locked'          => ! empty( $item['is_locked'] ),
					'min_security_level' => sanitize_key( $item['min_security_level'] ?? 'public' ),
					'security_note'      => sanitize_text_field( $item['security_note'] ?? '' ),
				);
			}

			return $sanitized;
		}

		/**
		 * پاکسازی آرایه گالری سنسورهای پایش میدانی اتاق وضعیت
		 *
		 * @param mixed $meta_value مقدار خام فراداده.
		 * @return array
		 */
		public static function sanitize_sensor_gallery_meta( $meta_value ) {
			if ( empty( $meta_value ) || ! is_array( $meta_value ) ) {
				return array();
			}

			$sanitized = array();
			foreach ( $meta_value as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$sanitized[] = array(
					'id'                 => absint( $item['id'] ?? 0 ),
					'sensor_label'       => sanitize_text_field( $item['sensor_label'] ?? 'SENSOR' ),
					'url'                => esc_url_raw( $item['url'] ?? '' ),
					'thumb_url'          => esc_url_raw( $item['thumb_url'] ?? ( $item['url'] ?? '' ) ),
					'caption'            => sanitize_text_field( $item['caption'] ?? '' ),
					'timestamp'          => sanitize_text_field( $item['timestamp'] ?? '' ),
					'coordinates'        => sanitize_text_field( $item['coordinates'] ?? '' ),
					'min_security_level' => sanitize_key( $item['min_security_level'] ?? 'normal' ),
				);
			}

			return $sanitized;
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

			register_post_meta(
				$post_type,
				'_revayat_sr_attachments',
				array(
					'type'              => 'array',
					'description'       => 'لیست اسناد و فایل‌های ضمیمه بولتن پدافندی',
					'single'            => true,
					'show_in_rest'      => array(
						'schema' => array(
							'type'  => 'array',
							'items' => array(
								'type' => 'object',
							),
						),
					),
					'sanitize_callback' => array( 'Revayat_Companion_Meta_Fields', 'sanitize_attachments_meta' ),
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_sr_sensor_gallery',
				array(
					'type'              => 'array',
					'description'       => 'تصاویر تاکتیکال و فریم‌های سنسور رصد میدانی',
					'single'            => true,
					'show_in_rest'      => array(
						'schema' => array(
							'type'  => 'array',
							'items' => array(
								'type' => 'object',
							),
						),
					),
					'sanitize_callback' => array( 'Revayat_Companion_Meta_Fields', 'sanitize_sensor_gallery_meta' ),
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_sr_source_org',
				array(
					'type'              => 'string',
					'description'       => 'نام یگان یا نهاد رصدکننده بولتن',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_sr_redacted_notice',
				array(
					'type'              => 'string',
					'description'       => 'پیام راهنمای احراز هویت برای کاربران غیرمجاز',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_textarea_field',
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

			register_post_meta(
				$post_type,
				'_revayat_obs_infographic_id',
				array(
					'type'              => 'integer',
					'description'       => 'شناسه اتچمنت تصویر اینفوگرافیک یا داده‌نمای فریمینگ',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'absint',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_obs_technique',
				array(
					'type'              => 'string',
					'description'       => 'تکنیک شاخص چارچوب‌سازی و بازنمایی رسانه‌ای',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_obs_target_outlets',
				array(
					'type'              => 'string',
					'description'       => 'فهرست رسانه‌ها و مراجع مورد رصد',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_obs_time_period',
				array(
					'type'              => 'string',
					'description'       => 'بازه زمانی پایش و تحلیل روایت',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_obs_focal_quote',
				array(
					'type'              => 'string',
					'description'       => 'گزاره یا کلیدواژه محوری مورد مناقشه',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_textarea_field',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_obs_frame_a_text',
				array(
					'type'              => 'string',
					'description'       => 'متن فریم اول واکاوی (آلیاس استاندارد معماری)',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_textarea_field',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_obs_frame_b_text',
				array(
					'type'              => 'string',
					'description'       => 'متن فریم دوم واکاوی (آلیاس استاندارد معماری)',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_textarea_field',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_obs_summary',
				array(
					'type'              => 'string',
					'description'       => 'چکیده تحلیل روایت (آلیاس استاندارد معماری)',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_textarea_field',
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
				'_revayat_editorial_note',
				array(
					'type'              => 'string',
					'description'       => 'بازخورد تحریریه برای تحلیلگر',
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => 'sanitize_textarea_field',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_review_status',
				array(
					'type'              => 'string',
					'description'       => 'وضعیت گردش کار تحریریه',
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => 'sanitize_key',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

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
					'show_in_rest'      => false,
					'sanitize_callback' => array( 'Revayat_Companion_Meta_Fields', 'sanitize_float' ),
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_analyst_quote',
				array(
					'type'              => 'string',
					'description'       => 'نقل‌قول برجسته یا تز محوری یادداشت تحلیلی',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_textarea_field',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_reading_depth',
				array(
					'type'              => 'string',
					'description'       => 'عمق مطالعه یا سطح تخصصی یادداشت تحلیلی (راهبردی، تخصصی، عمومی)',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_key_takeaways',
				array(
					'type'              => 'string',
					'description'       => 'گزاره‌های کلیدی، دستاوردها و توصیه‌های راهبردی یادداشت',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_textarea_field',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_references',
				array(
					'type'              => 'string',
					'description'       => 'منابع، ارجاعات و پانوشت‌های یادداشت تحلیلی',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_textarea_field',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_analyst_votes',
				array(
					'type'              => 'integer',
					'description'       => 'تعداد آرای ثبت‌شده برای یادداشت تحلیلی',
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => 'absint',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			foreach ( array( '_revayat_analyst_vote_sum', '_revayat_analyst_rating_ready' ) as $aggregate_key ) {
				register_post_meta(
					$post_type,
					$aggregate_key,
					array(
						'type'              => 'integer',
						'description'       => 'داده تجمیعی داخلی سامانه رأی‌دهی',
						'single'            => true,
						'show_in_rest'      => false,
						'sanitize_callback' => 'absint',
						'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
					)
				);
			}
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
				'_revayat_user_id',
				array(
					'type'              => 'integer',
					'description'       => 'شناسه حساب وردپرس متصل به پروفایل تحلیلگر',
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => 'absint',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

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
					'show_in_rest'      => false,
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
					'show_in_rest'      => false,
					'sanitize_callback' => 'absint',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);

			register_post_meta(
				$post_type,
				'_revayat_person_analyses_count',
				array(
					'type'              => 'integer',
					'description'       => 'تعداد یادداشت‌های منتشرشده شخص',
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => 'absint',
					'auth_callback'     => array( $this, 'auth_edit_post_meta' ),
				)
			);
		}
	}
}
