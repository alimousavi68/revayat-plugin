<?php
/**
 * کلاس اصلی ارکستراسیون هسته افزونه (Core Plugin Orchestrator)
 *
 * چرا این فایل لازم است؟
 * جهت مدیریت چرخه حیات افزونه، مقداردهی اولیه ماژول‌ها و هماهنگی میان کامپوننت‌های مختلف.
 *
 * چه مسئولیتی دارد؟
 * - پیاده‌سازی الگوی Singleton ایمن جهت جلوگیری از نمونه‌سازی‌های مکرر.
 * - نگهداری نمونه Loader.
 * - بارگذاری فایل‌های ترجمه (i18n).
 * - راه‌اندازی و اجرای ثبت هوک‌ها از طریق Loader.
 *
 * ارتباط با معماری:
 * هسته مرکزی افزونه revayat-companion که در تعامل با لایه‌های آتی (CPTها، تاکسونومی‌ها، لایه سرویس و متاها) به عنوان هماهنگ‌کننده اصلی عمل می‌کند.
 *
 * @package Revayat_Companion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Revayat_Companion_Plugin' ) ) {

	/**
	 * کلاس ارکستراتور اصلی افزونه مکمل روایت ایران
	 */
	class Revayat_Companion_Plugin {

		/**
		 * نمونه یکتا (Singleton Instance)
		 *
		 * @var Revayat_Companion_Plugin|null
		 */
		protected static $instance = null;

		/**
		 * نمونه ارکستراتور هوک‌ها
		 *
		 * @var Revayat_Companion_Loader
		 */
		protected $loader;

		/**
		 * نمونه کلاس مدیریت CPTها
		 *
		 * @var Revayat_Companion_Post_Types
		 */
		protected $post_types;

		/**
		 * نمونه کلاس مدیریت تاکسونومی‌ها
		 *
		 * @var Revayat_Companion_Taxonomies
		 */
		protected $taxonomies;

		/**
		 * نمونه کلاس مدیریت فیلدهای متا
		 *
		 * @var Revayat_Companion_Meta_Fields
		 */
		protected $meta_fields;

		/**
		 * سازنده کلاس به صورت محافظت‌شده جهت پیاده‌سازی الگوی Singleton
		 */
		protected function __construct() {
			$this->load_dependencies();
			$this->set_locale();
			$this->define_content_model_hooks();
			$this->define_admin_hooks();
			$this->define_public_hooks();
		}

		/**
		 * جلوگیری از Clone شدن شیء
		 */
		protected function __clone() {}

		/**
		 * جلوگیری از Unserialize شدن شیء
		 *
		 * @throws \Exception در صورت تلاش برای Unserialize.
		 */
		public function __wakeup() {
			throw new \Exception( 'Cannot unserialize singleton' );
		}

		/**
		 * دریافت نمونه یکتای کلاس
		 *
		 * @return Revayat_Companion_Plugin
		 */
		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * بارگذاری و مقداردهی اولیه وابستگی‌های هسته افزونه
		 */
		private function load_dependencies() {
			$this->loader = new Revayat_Companion_Loader();

			require_once REVAYAT_COMPANION_PATH . 'includes/post-types/class-post-types.php';
			require_once REVAYAT_COMPANION_PATH . 'includes/taxonomies/class-taxonomies.php';
			require_once REVAYAT_COMPANION_PATH . 'includes/meta/class-meta-fields.php';
			require_once REVAYAT_COMPANION_PATH . 'includes/services/class-content-service.php';
			require_once REVAYAT_COMPANION_PATH . 'includes/services/class-homepage-provider.php';

			$this->post_types  = new Revayat_Companion_Post_Types();
			$this->taxonomies  = new Revayat_Companion_Taxonomies();
			$this->meta_fields = new Revayat_Companion_Meta_Fields();
		}

		/**
		 * ثبت هوک بارگذاری ترجمه‌ها و چندزبانه
		 */
		private function set_locale() {
			$this->loader->add_action( 'init', $this, 'load_plugin_textdomain' );
		}

		/**
		 * ثبت هوک‌های مدل محتوا (CPTها، تاکسونومی‌ها و فیلدهای متا) در هوک بومی init وردپرس
		 */
		private function define_content_model_hooks() {
			// تاکسونومی‌ها در اولویت ۵ ثبت می‌شوند تا قبل از CPTها در دسترس باشند
			$this->loader->add_action( 'init', $this->taxonomies, 'register', 5 );
			// پست‌تایپ‌های سفارشی در اولویت ۱۰ ثبت می‌شوند
			$this->loader->add_action( 'init', $this->post_types, 'register', 10 );
			// فیلدهای متا در اولویت ۲۰ ثبت می‌شوند تا بعد از CPTها رجیستر شوند
			$this->loader->add_action( 'init', $this->meta_fields, 'register', 20 );
		}

		/**
		 * بارگذاری فایل‌های ترجمه در زمان اجرای هوک init
		 */
		public function load_plugin_textdomain() {
			load_plugin_textdomain(
				'revayat-companion',
				false,
				dirname( REVAYAT_COMPANION_BASENAME ) . '/languages/'
			);
		}

		/**
		 * ثبت هوک‌های مرتبط با بخش مدیریت وردپرس (برای کامیت‌های آتی)
		 */
		private function define_admin_hooks() {
			// اسکلت اولیه - در کامیت‌های بعدی توسعه خواهد یافت.
		}

		/**
		 * ثبت هوک‌های عمومی و فرانت‌اند (برای کامیت‌های آتی)
		 */
		private function define_public_hooks() {
			// اسکلت اولیه - در کامیت‌های بعدی توسعه خواهد یافت.
		}

		/**
		 * اجرای تمام هوک‌های ثبت‌شده در هسته افزونه
		 */
		public function run() {
			$this->loader->run();
		}

		/**
		 * دریافت شیء Loader
		 *
		 * @return Revayat_Companion_Loader
		 */
		public function get_loader() {
			return $this->loader;
		}

		/**
		 * دریافت شیء مدیریت CPTها
		 *
		 * @return Revayat_Companion_Post_Types
		 */
		public function get_post_types() {
			return $this->post_types;
		}

		/**
		 * دریافت شیء مدیریت تاکسونومی‌ها
		 *
		 * @return Revayat_Companion_Taxonomies
		 */
		public function get_taxonomies() {
			return $this->taxonomies;
		}

		/**
		 * دریافت شیء مدیریت فیلدهای متا
		 *
		 * @return Revayat_Companion_Meta_Fields
		 */
		public function get_meta_fields() {
			return $this->meta_fields;
		}
	}
}
