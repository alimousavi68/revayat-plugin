<?php
/**
 * ثبت پست‌تایپ‌های سفارشی افزونه مکمل روایت ایران (Custom Post Types Registry)
 *
 * چرا این فایل لازم است؟
 * مدیریت و ثبت متمرکز تمامی Custom Post Type های پایگاه خبری تحلیلی بدون وابستگی به پوسته، جهت تضمین پایداری داده‌ها (Data Portability).
 *
 * چه مسئولیتی دارد؟
 * تعریف ساختار، برچسب‌های فارسی، قابلیت‌ها، آرشیوها و نگاشت بازنویسی URL برای ۶ پست‌تایپ:
 * - situation_room (اتاق وضعیت)
 * - special_dossier (پرونده‌های ویژه)
 * - media_observatory (دیده‌بان رسانه)
 * - analyst_post (یادداشت‌های تحلیلی)
 * - multimedia (چندرسانه‌ای)
 * - person (پروفایل اشخاص و تحلیلگران)
 *
 * ارتباط با معماری:
 * لایه Content Model افزونه طبق اسناد معماری docs/architecture/cpt-schema.md و تصمیمات ADR-001 و ADR-004.
 *
 * @package Revayat_Companion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Revayat_Companion_Post_Types' ) ) {

	/**
	 * کلاس ثبت پست‌تایپ‌های اختصاصی پایگاه
	 */
	class Revayat_Companion_Post_Types {

		/**
		 * ثبت کلیه CPTها در هوک init وردپرس
		 *
		 * @return void
		 */
		public function register() {
			$this->register_situation_room();
			$this->register_special_dossier();
			$this->register_media_observatory();
			$this->register_analyst_post();
			$this->register_multimedia();
			$this->register_person();
		}

		/**
		 * ثبت پست‌تایپ اتاق وضعیت
		 *
		 * @return void
		 */
		protected function register_situation_room() {
			$labels = array(
				'name'                  => 'اتاق وضعیت',
				'singular_name'         => 'بولتن تحلیلی',
				'menu_name'             => 'اتاق وضعیت',
				'name_admin_bar'        => 'بولتن اتاق وضعیت',
				'add_new'               => 'افزودن بولتن',
				'add_new_item'          => 'افزودن بولتن تحلیلی جدید',
				'new_item'              => 'بولتن جدید',
				'edit_item'             => 'ویرایش بولتن تحلیلی',
				'view_item'             => 'مشاهده بولتن',
				'all_items'             => 'همه بولتن‌ها',
				'search_items'          => 'جستجوی بولتن‌های وضعیت',
				'parent_item_colon'     => 'بولتن والد:',
				'not_found'             => 'بولتن تحلیلی یافت نشد.',
				'not_found_in_trash'    => 'بولتنی در زباله‌دان یافت نشد.',
				'featured_image'        => 'تصویر شاخص بولتن',
				'set_featured_image'    => 'تنظیم تصویر شاخص',
				'remove_featured_image' => 'حذف تصویر شاخص',
				'use_featured_image'    => 'استفاده به عنوان تصویر شاخص',
			);

			$args = array(
				'labels'             => $labels,
				'public'             => true,
				'publicly_queryable' => true,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'query_var'          => true,
				'rewrite'            => array(
					'slug'       => 'situation-room',
					'with_front' => false,
				),
				'capability_type'    => 'post',
				'has_archive'        => 'situation-room',
				'hierarchical'       => false,
				'menu_position'      => 20,
				'menu_icon'          => 'dashicons-shield-alt',
				'supports'           => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions' ),
				'show_in_rest'       => true,
			);

			register_post_type( 'situation_room', $args );
		}

		/**
		 * ثبت پست‌تایپ پرونده‌های ویژه
		 *
		 * @return void
		 */
		protected function register_special_dossier() {
			$labels = array(
				'name'                  => 'پرونده‌های ویژه',
				'singular_name'         => 'پرونده ویژه',
				'menu_name'             => 'پرونده‌های ویژه',
				'name_admin_bar'        => 'پرونده ویژه',
				'add_new'               => 'افزودن پرونده',
				'add_new_item'          => 'افزودن پرونده ویژه جدید',
				'new_item'              => 'پرونده جدید',
				'edit_item'             => 'ویرایش پرونده ویژه',
				'view_item'             => 'مشاهده پرونده ویژه',
				'all_items'             => 'همه پرونده‌ها',
				'search_items'          => 'جستجوی پرونده‌های ویژه',
				'not_found'             => 'پرونده ویژه‌ای یافت نشد.',
				'not_found_in_trash'    => 'پرونده‌ای در زباله‌دان یافت نشد.',
				'featured_image'        => 'پوستر پرونده ویژه',
				'set_featured_image'    => 'تنظیم پوستر پرونده',
				'remove_featured_image' => 'حذف پوستر پرونده',
			);

			$args = array(
				'labels'             => $labels,
				'public'             => true,
				'publicly_queryable' => true,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'query_var'          => true,
				'rewrite'            => array(
					'slug'       => 'dossiers',
					'with_front' => false,
				),
				'capability_type'    => 'post',
				'has_archive'        => 'dossiers',
				'hierarchical'       => false,
				'menu_position'      => 21,
				'menu_icon'          => 'dashicons-portfolio',
				'supports'           => array( 'title', 'editor', 'excerpt', 'thumbnail', 'comments', 'revisions' ),
				'show_in_rest'       => true,
			);

			register_post_type( 'special_dossier', $args );
		}

		/**
		 * ثبت پست‌تایپ دیده‌بان رسانه
		 *
		 * @return void
		 */
		protected function register_media_observatory() {
			$labels = array(
				'name'                  => 'دیده‌بان رسانه',
				'singular_name'         => 'واکاوی رسانه‌ای',
				'menu_name'             => 'دیده‌بان رسانه',
				'name_admin_bar'        => 'گزارش دیده‌بان',
				'add_new'               => 'افزودن واکاوی',
				'add_new_item'          => 'افزودن واکاوی رسانه‌ای جدید',
				'new_item'              => 'واکاوی جدید',
				'edit_item'             => 'ویرایش واکاوی رسانه‌ای',
				'view_item'             => 'مشاهده واکاوی',
				'all_items'             => 'همه واکاوی‌ها',
				'search_items'          => 'جستجوی گزارش‌های دیده‌بان',
				'not_found'             => 'واکاوی رسانه‌ای یافت نشد.',
				'not_found_in_trash'    => 'موردی در زباله‌دان یافت نشد.',
				'featured_image'        => 'تصویر مقایسه‌ای / شاخص',
				'set_featured_image'    => 'تنظیم تصویر شاخص',
				'remove_featured_image' => 'حذف تصویر شاخص',
			);

			$args = array(
				'labels'             => $labels,
				'public'             => true,
				'publicly_queryable' => true,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'query_var'          => true,
				'rewrite'            => array(
					'slug'       => 'observatory',
					'with_front' => false,
				),
				'capability_type'    => 'post',
				'has_archive'        => 'observatory',
				'hierarchical'       => false,
				'menu_position'      => 22,
				'menu_icon'          => 'dashicons-visibility',
				'supports'           => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions' ),
				'show_in_rest'       => true,
			);

			register_post_type( 'media_observatory', $args );
		}

		/**
		 * ثبت پست‌تایپ یادداشت تحلیلی شبکه تحلیلگران
		 *
		 * @return void
		 */
		protected function register_analyst_post() {
			$labels = array(
				'name'                  => 'شبکه تحلیلگران',
				'singular_name'         => 'یادداشت تحلیلی',
				'menu_name'             => 'یادداشت‌های تحلیلی',
				'name_admin_bar'        => 'یادداشت تحلیلی',
				'add_new'               => 'افزودن یادداشت',
				'add_new_item'          => 'افزودن یادداشت تحلیلی جدید',
				'new_item'              => 'یادداشت جدید',
				'edit_item'             => 'ویرایش یادداشت تحلیلی',
				'view_item'             => 'مشاهده یادداشت',
				'all_items'             => 'همه یادداشت‌ها',
				'search_items'          => 'جستجوی یادداشت‌ها',
				'not_found'             => 'یادداشتی یافت نشد.',
				'not_found_in_trash'    => 'یادداشتی در زباله‌دان یافت نشد.',
				'featured_image'        => 'تصویر یادداشت',
				'set_featured_image'    => 'تنظیم تصویر یادداشت',
				'remove_featured_image' => 'حذف تصویر یادداشت',
			);

			$args = array(
				'labels'             => $labels,
				'public'             => true,
				'publicly_queryable' => true,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'query_var'          => true,
				'rewrite'            => array(
					'slug'       => 'analysts',
					'with_front' => false,
				),
				'capability_type'    => 'post',
				'has_archive'        => 'analysts',
				'hierarchical'       => false,
				'menu_position'      => 23,
				'menu_icon'          => 'dashicons-groups',
				'supports'           => array( 'title', 'editor', 'excerpt', 'thumbnail', 'comments', 'revisions' ),
				'show_in_rest'       => true,
			);

			register_post_type( 'analyst_post', $args );
		}

		/**
		 * ثبت پست‌تایپ چندرسانه‌ای
		 *
		 * @return void
		 */
		protected function register_multimedia() {
			$labels = array(
				'name'                  => 'رسانه‌نگار',
				'singular_name'         => 'آیتم چندرسانه‌ای',
				'menu_name'             => 'چندرسانه‌ای',
				'name_admin_bar'        => 'چندرسانه‌ای',
				'add_new'               => 'افزودن رسانه',
				'add_new_item'          => 'افزودن آیتم چندرسانه‌ای جدید',
				'new_item'              => 'رسانه جدید',
				'edit_item'             => 'ویرایش آیتم چندرسانه‌ای',
				'view_item'             => 'مشاهده آیتم چندرسانه‌ای',
				'all_items'             => 'همه رسانه‌ها',
				'search_items'          => 'جستجوی چندرسانه‌ای',
				'not_found'             => 'آیتم چندرسانه‌ای یافت نشد.',
				'not_found_in_trash'    => 'آیتمی در زباله‌دان یافت نشد.',
				'featured_image'        => 'کاور مدیا',
				'set_featured_image'    => 'تنظیم کاور مدیا',
				'remove_featured_image' => 'حذف کاور مدیا',
			);

			$args = array(
				'labels'             => $labels,
				'public'             => true,
				'publicly_queryable' => true,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'query_var'          => true,
				'rewrite'            => array(
					'slug'       => 'multimedia',
					'with_front' => false,
				),
				'capability_type'    => 'post',
				'has_archive'        => 'multimedia',
				'hierarchical'       => false,
				'menu_position'      => 24,
				'menu_icon'          => 'dashicons-video-alt3',
				'supports'           => array( 'title', 'editor', 'excerpt', 'thumbnail', 'comments', 'revisions' ),
				'show_in_rest'       => true,
			);

			register_post_type( 'multimedia', $args );
		}

		/**
		 * ثبت پست‌تایپ پروفایل اشخاص و تحلیلگران
		 *
		 * @return void
		 */
		protected function register_person() {
			$labels = array(
				'name'                  => 'اشخاص و تحلیل‌گران',
				'singular_name'         => 'پروفایل شخص',
				'menu_name'             => 'اشخاص / کارشناسان',
				'name_admin_bar'        => 'پروفایل شخص',
				'add_new'               => 'افزودن شخص',
				'add_new_item'          => 'افزودن پروفایل شخص جدید',
				'new_item'              => 'شخص جدید',
				'edit_item'             => 'ویرایش پروفایل شخص',
				'view_item'             => 'مشاهده پروفایل',
				'all_items'             => 'همه اشخاص',
				'search_items'          => 'جستجوی اشخاص',
				'not_found'             => 'پروفایل شخصی یافت نشد.',
				'not_found_in_trash'    => 'پروفایلی در زباله‌دان یافت نشد.',
				'featured_image'        => 'تصویر پرتره / آواتار',
				'set_featured_image'    => 'تنظیم تصویر پرتره',
				'remove_featured_image' => 'حذف تصویر پرتره',
			);

			$args = array(
				'labels'             => $labels,
				'public'             => true,
				'publicly_queryable' => true,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'query_var'          => true,
				'rewrite'            => array(
					'slug'       => 'person',
					'with_front' => false,
				),
				'capability_type'    => 'post',
				'has_archive'        => 'persons',
				'hierarchical'       => false,
				'menu_position'      => 25,
				'menu_icon'          => 'dashicons-businessperson',
				'supports'           => array( 'title', 'editor', 'thumbnail', 'revisions' ),
				'show_in_rest'       => true,
			);

			register_post_type( 'person', $args );
		}
	}
}
