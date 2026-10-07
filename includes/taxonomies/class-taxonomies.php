<?php
/**
 * ثبت تاکسونومی‌های سفارشی افزونه مکمل روایت ایران (Custom Taxonomies Registry)
 *
 * چرا این فایل لازم است؟
 * مدیریت و ثبت متمرکز دسته‌بندی‌ها و ساختارهای طبقه‌بندی محتوا، منابع خبری و مدل‌های رابطه‌ای بدون وابستگی به پوسته.
 *
 * چه مسئولیتی دارد؟
 * تعریف ساختار، برچسب‌های فارسی، آرگومان‌های REST و اسلاگ‌های بازنویسی برای ۸ تاکسونومی کلیدی:
 * - editorial_placement (جایگاه‌های تحریریه در صفحه نخست)
 * - person_author (مدل رابطه‌ای مبتنی بر تاکسونومی برای انتساب اشخاص به مقالات)
 * - news_source (منابع خبری)
 * - security_level (سطوح طبقه‌بندی امنیتی)
 * - dossier_topic (محورهای پرونده‌های ویژه)
 * - observatory_badge (گونه‌های واکاوی رسانه‌ای)
 * - analyst_field (رشته‌های تخصصی تحلیلگران)
 * - media_format (قالب‌های چندرسانه‌ای)
 *
 * ارتباط با معماری:
 * تحقق مدل داده طبق سند docs/architecture/taxonomy-map.md و تصمیم معماری ADR-004.
 *
 * @package Revayat_Companion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Revayat_Companion_Taxonomies' ) ) {

	/**
	 * کلاس ثبت تاکسونومی‌های اختصاصی پایگاه
	 */
	class Revayat_Companion_Taxonomies {

		/**
		 * ثبت کلیه تاکسونومی‌ها در هوک init وردپرس
		 *
		 * @return void
		 */
		public function register() {
			$this->register_editorial_placement();
			$this->register_person_author();
			$this->register_news_source();
			$this->register_security_level();
			$this->register_dossier_topic();
			$this->register_observatory_badge();
			$this->register_analyst_field();
			$this->register_media_format();
		}

		/**
		 * ثبت تاکسونومی جایگاه تحریریه در صفحه اصلی
		 *
		 * @return void
		 */
		protected function register_editorial_placement() {
			$labels = array(
				'name'                       => 'جایگاه‌های تحریریه',
				'singular_name'              => 'جایگاه تحریریه',
				'menu_name'                  => 'جایگاه‌های تحریریه',
				'all_items'                  => 'همه جایگاه‌ها',
				'edit_item'                  => 'ویرایش جایگاه',
				'view_item'                  => 'مشاهده جایگاه',
				'update_item'                => 'به‌روزرسانی جایگاه',
				'add_new_item'               => 'افزودن جایگاه جدید',
				'new_item_name'              => 'نام جایگاه جدید',
				'search_items'               => 'جستجوی جایگاه‌ها',
				'popular_items'              => 'جایگاه‌های پرکاربرد',
				'separate_items_with_commas' => 'جایگاه‌ها را با کاما جدا کنید',
				'add_or_remove_items'        => 'افزودن یا حذف جایگاه‌ها',
				'choose_from_most_used'      => 'انتخاب از پرکاربردترین جایگاه‌ها',
				'not_found'                  => 'جایگاهی یافت نشد.',
			);

			$args = array(
				'labels'            => $labels,
				'hierarchical'      => false,
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_nav_menus' => false,
				'show_tagcloud'     => false,
				'show_in_rest'      => true,
				'query_var'         => true,
				'rewrite'           => array(
					'slug'       => 'placement',
					'with_front' => false,
				),
			);

			register_taxonomy( 'editorial_placement', array( 'post' ), $args );
		}

		/**
		 * ثبت تاکسونومی رابطه‌ای پدیدآورنده / شخص (مطابق با ADR-004)
		 *
		 * @return void
		 */
		protected function register_person_author() {
			$labels = array(
				'name'                       => 'پدیدآورندگان / اشخاص',
				'singular_name'              => 'پدیدآورنده',
				'menu_name'                  => 'پدیدآورندگان',
				'all_items'                  => 'همه پدیدآورندگان',
				'edit_item'                  => 'ویرایش پدیدآورنده',
				'view_item'                  => 'مشاهده پدیدآورنده',
				'update_item'                => 'به‌روزرسانی پدیدآورنده',
				'add_new_item'               => 'افزودن پدیدآورنده جدید',
				'new_item_name'              => 'نام پدیدآورنده جدید',
				'search_items'               => 'جستجوی پدیدآورندگان',
				'popular_items'              => 'پدیدآورندگان پرکاربرد',
				'separate_items_with_commas' => 'اسامی را با کاما جدا کنید',
				'add_or_remove_items'        => 'افزودن یا حذف پدیدآورنده',
				'choose_from_most_used'      => 'انتخاب از پدیدآورندگان پرکاربرد',
				'not_found'                  => 'پدیدآورنده‌ای یافت نشد.',
			);

			$args = array(
				'labels'            => $labels,
				'hierarchical'      => false,
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_nav_menus' => true,
				'show_tagcloud'     => false,
				'show_in_rest'      => true,
				'query_var'         => true,
				'rewrite'           => array(
					'slug'       => 'contributor',
					'with_front' => false,
				),
			);

			register_taxonomy( 'person_author', array( 'post', 'analyst_post' ), $args );
		}

		/**
		 * ثبت تاکسونومی منبع خبری
		 *
		 * @return void
		 */
		protected function register_news_source() {
			$labels = array(
				'name'                       => 'منابع خبری',
				'singular_name'              => 'منبع خبر',
				'menu_name'                  => 'منابع خبر',
				'all_items'                  => 'همه منابع',
				'edit_item'                  => 'ویرایش منبع',
				'view_item'                  => 'مشاهده منبع',
				'update_item'                => 'به‌روزرسانی منبع',
				'add_new_item'               => 'افزودن منبع جدید',
				'new_item_name'              => 'نام منبع جدید',
				'search_items'               => 'جستجوی منابع خبری',
				'not_found'                  => 'منبع خبری یافت نشد.',
			);

			$args = array(
				'labels'            => $labels,
				'hierarchical'      => false,
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_nav_menus' => false,
				'show_tagcloud'     => false,
				'show_in_rest'      => true,
				'query_var'         => true,
				'rewrite'           => array(
					'slug'       => 'source',
					'with_front' => false,
				),
			);

			register_taxonomy( 'news_source', array( 'post' ), $args );
		}

		/**
		 * ثبت تاکسونومی سطح طبقه‌بندی امنیتی
		 *
		 * @return void
		 */
		protected function register_security_level() {
			$labels = array(
				'name'          => 'سطوح طبقه‌بندی امنیتی',
				'singular_name' => 'سطح طبقه‌بندی',
				'menu_name'     => 'سطوح امنیت',
				'all_items'     => 'همه سطوح',
				'edit_item'     => 'ویرایش سطح طبقه‌بندی',
				'view_item'     => 'مشاهده سطح طبقه‌بندی',
				'update_item'   => 'به‌روزرسانی سطح',
				'add_new_item'  => 'افزودن سطح طبقه‌بندی جدید',
				'new_item_name' => 'نام سطح جدید',
				'search_items'  => 'جستجوی سطوح',
				'not_found'     => 'سطحی یافت نشد.',
			);

			$args = array(
				'labels'            => $labels,
				'hierarchical'      => false,
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_nav_menus' => false,
				'show_tagcloud'     => false,
				'show_in_rest'      => true,
				'query_var'         => true,
				'rewrite'           => array(
					'slug'       => 'security-level',
					'with_front' => false,
				),
			);

			register_taxonomy( 'security_level', array( 'situation_room' ), $args );
		}

		/**
		 * ثبت تاکسونومی محور موضوعی پرونده‌های ویژه
		 *
		 * @return void
		 */
		protected function register_dossier_topic() {
			$labels = array(
				'name'              => 'محورهای موضوعی پرونده',
				'singular_name'     => 'محور پرونده',
				'menu_name'         => 'محورهای پرونده',
				'all_items'         => 'همه محورها',
				'parent_item'       => 'محور والد',
				'parent_item_colon' => 'محور والد:',
				'edit_item'         => 'ویرایش محور',
				'update_item'       => 'به‌روزرسانی محور',
				'add_new_item'      => 'افزودن محور موضوعی جدید',
				'search_items'      => 'جستجوی محورها',
				'not_found'         => 'محوری یافت نشد.',
			);

			$args = array(
				'labels'            => $labels,
				'hierarchical'      => true,
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'query_var'         => true,
				'rewrite'           => array(
					'slug'       => 'dossier-topic',
					'with_front' => false,
				),
			);

			register_taxonomy( 'dossier_topic', array( 'special_dossier' ), $args );
		}

		/**
		 * ثبت تاکسونومی گونه واکاوی دیده‌بان رسانه
		 *
		 * @return void
		 */
		protected function register_observatory_badge() {
			$labels = array(
				'name'          => 'گونه‌های واکاوی دیده‌بان',
				'singular_name' => 'گونه واکاوی',
				'menu_name'     => 'گونه‌های واکاوی',
				'all_items'     => 'همه گونه‌ها',
				'edit_item'     => 'ویرایش گونه',
				'update_item'   => 'به‌روزرسانی گونه',
				'add_new_item'  => 'افزودن گونه جدید',
				'search_items'  => 'جستجوی گونه‌ها',
				'not_found'     => 'موردی یافت نشد.',
			);

			$args = array(
				'labels'            => $labels,
				'hierarchical'      => false,
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'query_var'         => true,
				'rewrite'           => array(
					'slug'       => 'observatory-badge',
					'with_front' => false,
				),
			);

			register_taxonomy( 'observatory_badge', array( 'media_observatory' ), $args );
		}

		/**
		 * ثبت تاکسونومی رشته تخصصی تحلیلگر
		 *
		 * @return void
		 */
		protected function register_analyst_field() {
			$labels = array(
				'name'              => 'رشته‌های تخصصی تحلیلگران',
				'singular_name'     => 'رشته تخصصی',
				'menu_name'         => 'رشته‌های تخصصی',
				'all_items'         => 'همه رشته‌ها',
				'parent_item'       => 'رشته والد',
				'parent_item_colon' => 'رشته والد:',
				'edit_item'         => 'ویرایش رشته تخصصی',
				'update_item'       => 'به‌روزرسانی رشته',
				'add_new_item'      => 'افزودن رشته تخصصی جدید',
				'search_items'      => 'جستجوی رشته‌ها',
				'not_found'         => 'رشته‌ای یافت نشد.',
			);

			$args = array(
				'labels'            => $labels,
				'hierarchical'      => true,
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'query_var'         => true,
				'rewrite'           => array(
					'slug'       => 'analyst-field',
					'with_front' => false,
				),
			);

			register_taxonomy( 'analyst_field', array( 'analyst_post', 'person' ), $args );
		}

		/**
		 * ثبت تاکسونومی قالب رسانه‌ای چندرسانه‌ای
		 *
		 * @return void
		 */
		protected function register_media_format() {
			$labels = array(
				'name'          => 'قالب‌های رسانه‌ای',
				'singular_name' => 'قالب رسانه',
				'menu_name'     => 'قالب‌های رسانه',
				'all_items'     => 'همه قالب‌ها',
				'edit_item'     => 'ویرایش قالب',
				'update_item'   => 'به‌روزرسانی قالب',
				'add_new_item'  => 'افزودن قالب جدید',
				'search_items'  => 'جستجوی قالب‌ها',
				'not_found'     => 'قالبی یافت نشد.',
			);

			$args = array(
				'labels'            => $labels,
				'hierarchical'      => false,
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'query_var'         => true,
				'rewrite'           => array(
					'slug'       => 'media-format',
					'with_front' => false,
				),
			);

			register_taxonomy( 'media_format', array( 'multimedia' ), $args );
		}
	}
}
