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

		/** @var Revayat_Companion_User_Portal */
		protected $user_portal;

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
			require_once REVAYAT_COMPANION_PATH . 'includes/meta/class-dossier-status.php';
			require_once REVAYAT_COMPANION_PATH . 'includes/services/class-dossier-links.php';
			require_once REVAYAT_COMPANION_PATH . 'includes/meta/class-dossier-links-admin.php';
			require_once REVAYAT_COMPANION_PATH . 'includes/services/class-content-service.php';
			require_once REVAYAT_COMPANION_PATH . 'includes/services/class-person-identity.php';
			require_once REVAYAT_COMPANION_PATH . 'includes/services/class-profile-avatar.php';
			require_once REVAYAT_COMPANION_PATH . 'includes/services/class-analyst-ratings.php';
			require_once REVAYAT_COMPANION_PATH . 'includes/services/class-sms-service.php';
			require_once REVAYAT_COMPANION_PATH . 'includes/services/class-workflow-lock.php';
			require_once REVAYAT_COMPANION_PATH . 'includes/services/class-otp-service.php';
			require_once REVAYAT_COMPANION_PATH . 'includes/services/class-media-performance.php';
			require_once REVAYAT_COMPANION_PATH . 'includes/admin/class-sms-settings.php';
			require_once REVAYAT_COMPANION_PATH . 'includes/services/class-homepage-query.php';
			require_once REVAYAT_COMPANION_PATH . 'includes/services/class-homepage-provider.php';
			require_once REVAYAT_COMPANION_PATH . 'includes/users/class-user-portal.php';
			require_once REVAYAT_COMPANION_PATH . 'includes/services/class-member-policy.php';
			require_once REVAYAT_COMPANION_PATH . 'includes/services/class-auth-flow.php';
			require_once REVAYAT_COMPANION_PATH . 'includes/services/class-member-profile.php';
			require_once REVAYAT_COMPANION_PATH . 'includes/services/class-member-notes.php';
			require_once REVAYAT_COMPANION_PATH . 'includes/services/class-private-documents.php';
			require_once REVAYAT_COMPANION_PATH . 'includes/services/class-member-applications.php';
			require_once REVAYAT_COMPANION_PATH . 'includes/admin/class-member-applications-admin.php';
			require_once REVAYAT_COMPANION_PATH . 'includes/widgets/class-contextual-widget.php';

			$this->post_types  = new Revayat_Companion_Post_Types();
			$this->taxonomies  = new Revayat_Companion_Taxonomies();
			$this->meta_fields = new Revayat_Companion_Meta_Fields();
			$this->user_portal = new Revayat_Companion_User_Portal();
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
			$this->loader->add_action('widgets_init', 'Revayat_Companion_Contextual_Widget', 'register');
			$this->loader->add_action('admin_init', 'Revayat_Companion_Contextual_Widget', 'seed_defaults');
			$this->loader->add_action('admin_init', 'Revayat_Companion_Contextual_Widget', 'refine_sidebars');
			$this->loader->add_action( 'init', 'Revayat_Companion_Dossier_Links', 'register_meta', 20 );
			$this->loader->add_action( 'init', 'Revayat_Companion_User_Portal', 'register_roles', 1 );
			// تاکسونومی‌ها در اولویت ۵ ثبت می‌شوند تا قبل از CPTها در دسترس باشند
			$this->loader->add_action( 'init', $this->taxonomies, 'register', 5 );
			// پست‌تایپ‌های سفارشی در اولویت ۱۰ ثبت می‌شوند
			$this->loader->add_action( 'init', $this->post_types, 'register', 10 );
			// فیلدهای متا در اولویت ۲۰ ثبت می‌شوند تا بعد از CPTها رجیستر شوند
			$this->loader->add_action( 'init', $this->meta_fields, 'register', 20 );
			$this->loader->add_filter( 'get_avatar_data', 'Revayat_Companion_Profile_Avatar', 'filter_avatar', 20, 2 );
			$this->loader->add_action( 'user_edit_form_tag', 'Revayat_Companion_Profile_Avatar', 'form_tag' );
			$this->loader->add_action( 'show_user_profile', 'Revayat_Companion_Profile_Avatar', 'admin_fields' );
			$this->loader->add_action( 'edit_user_profile', 'Revayat_Companion_Profile_Avatar', 'admin_fields' );
			$this->loader->add_action( 'personal_options_update', 'Revayat_Companion_Profile_Avatar', 'admin_save' );
			$this->loader->add_action( 'edit_user_profile_update', 'Revayat_Companion_Profile_Avatar', 'admin_save' );
			$this->loader->add_action( 'admin_post_revayat_update_avatar', 'Revayat_Companion_Profile_Avatar', 'frontend_save' );
			$this->loader->add_action( 'save_post_person', 'Revayat_Companion_Person_Identity', 'sync_person', 20, 2 );
			$this->loader->add_action( 'transition_post_status', 'Revayat_Companion_Analyst_Ratings', 'handle_status_change', 20, 3 );
			$this->loader->add_action( 'set_object_terms', 'Revayat_Companion_Analyst_Ratings', 'handle_terms_change', 20, 6 );
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
			$this->loader->add_action( 'revayat_access_status_changed', 'Revayat_Companion_SMS_Service', 'notify_access', 10, 4 );
			$this->loader->add_action( 'revayat_analyst_post_status_changed', 'Revayat_Companion_SMS_Service', 'notify_post', 10, 3 );
			$this->loader->add_action( 'admin_init', 'Revayat_Companion_SMS_Settings', 'register' );
			$this->loader->add_action( 'admin_menu', 'Revayat_Companion_SMS_Settings', 'menu' );
			$this->loader->add_action( 'admin_enqueue_scripts', 'Revayat_Companion_SMS_Settings', 'enqueue' );
			$this->loader->add_action( 'admin_menu', 'Revayat_Companion_Person_Identity', 'register_tools_page' );
			$this->loader->add_action( 'admin_post_revayat_migrate_analyst_identity', 'Revayat_Companion_Person_Identity', 'handle_migration' );
			$this->loader->add_action( 'add_meta_boxes', 'Revayat_Companion_Dossier_Links_Admin', 'register_metabox' );
			$this->loader->add_action( 'save_post_special_dossier', 'Revayat_Companion_Dossier_Links_Admin', 'save', 10, 2 );
			$this->loader->add_action( 'admin_enqueue_scripts', 'Revayat_Companion_Dossier_Links_Admin', 'enqueue' );
			$this->loader->add_action( 'wp_ajax_revayat_search_dossier_content', 'Revayat_Companion_Dossier_Links_Admin', 'search' );
			$this->loader->add_action( 'wp_ajax_revayat_validate_dossier_documents', 'Revayat_Companion_Dossier_Links_Admin', 'validate_documents' );
			$this->loader->add_action( 'add_meta_boxes', 'Revayat_Companion_Dossier_Status', 'register_metabox' );
			$this->loader->add_action( 'save_post_special_dossier', 'Revayat_Companion_Dossier_Status', 'save_status', 10, 2 );
			$this->loader->add_action( 'admin_init', $this->user_portal, 'restrict_admin' );
			$this->loader->add_action( 'admin_menu', $this->user_portal, 'register_approval_page' );
			$this->loader->add_action( 'admin_menu', $this->user_portal, 'register_special_access_page' );
			$this->loader->add_action( 'admin_menu', $this->user_portal, 'register_email_report_page' );
			$this->loader->add_action( 'admin_post_revayat_review_user', $this->user_portal, 'handle_review_user' );
			$this->loader->add_action( 'admin_post_revayat_review_special_access', $this->user_portal, 'handle_review_special_access' );
			$this->loader->add_action( 'add_meta_boxes', $this->user_portal, 'register_editorial_note_metabox' );
			$this->loader->add_action( 'save_post_analyst_post', $this->user_portal, 'save_editorial_note', 10, 2 );
		}

		/**
		 * ثبت هوک‌های عمومی و فرانت‌اند (برای کامیت‌های آتی)
		 */
		private function define_public_hooks() {
			$this->loader->add_filter( 'image_editor_output_format', 'Revayat_Companion_Media_Performance', 'prefer_webp_for_jpeg' );
			$this->loader->add_action( 'rest_api_init', 'Revayat_Companion_Content_Service', 'register_rest_routes' );
			$this->loader->add_action( 'template_redirect', 'Revayat_Companion_Person_Identity', 'redirect_author_archive_to_person', 0 );
			$this->loader->add_action( 'template_redirect', 'Revayat_Companion_Content_Service', 'enforce_situation_room_route', 0 );
			$this->loader->add_action( 'pre_get_posts', 'Revayat_Companion_Content_Service', 'filter_protected_queries', 1 );
            $this->loader->add_action( 'pre_get_posts', 'Revayat_Companion_Content_Service', 'prepare_multimedia_archive_query', 10 );
			$this->loader->add_filter( 'rest_pre_dispatch', 'Revayat_Companion_Content_Service', 'protect_situation_room_rest', 10, 3 );
			$this->loader->add_filter( 'wp_sitemaps_post_types', 'Revayat_Companion_Content_Service', 'filter_protected_sitemap_post_types' );
			// فیلتر حفاظت از محتوای طبقه‌بندی‌شده اتاق وضعیت در زمان رندر the_content
			$this->loader->add_filter( 'the_content', 'Revayat_Companion_Content_Service', 'protect_the_content', 10, 1 );
			$this->loader->add_action( 'init', $this->user_portal, 'enforce_active_session', 20 );
			$this->loader->add_filter( 'wp_authenticate_user', $this->user_portal, 'prevent_suspended_authentication', 30, 1 );
			$this->loader->add_action( 'init', 'Revayat_Companion_Member_Applications', 'register' );
            $this->loader->add_filter( 'preprocess_comment', 'Revayat_Companion_Member_Policy', 'comment_guard' );
            $this->loader->add_filter( 'pre_comment_approved', 'Revayat_Companion_Member_Policy', 'moderate_guest_comment', 20, 2 );
			$this->loader->add_action( 'admin_menu', 'Revayat_Companion_Member_Applications_Admin', 'menu' );
			$this->loader->add_action( 'admin_init', 'Revayat_Companion_Member_Applications_Admin', 'settings' );
			$this->loader->add_action( 'admin_post_revayat_application_review', 'Revayat_Companion_Member_Applications_Admin', 'decide' );
			$this->loader->add_action( 'admin_post_revayat_member_application', 'Revayat_Companion_Member_Applications', 'handle' );
			$this->loader->add_action( 'wp_ajax_revayat_member_application', 'Revayat_Companion_Member_Applications', 'handle' );
			$this->loader->add_action( 'admin_post_revayat_private_document', 'Revayat_Companion_Private_Documents', 'download' );
			$this->loader->add_action( 'revayat_private_document_cleanup', 'Revayat_Companion_Private_Documents', 'cleanup' );
			foreach ( array( 'wp_ajax_', 'admin_post_' ) as $prefix ) {
				$this->loader->add_action( $prefix . 'revayat_member_note', 'Revayat_Companion_Member_Notes', 'handle' );
				$this->loader->add_action( $prefix . 'revayat_note_review', 'Revayat_Companion_Member_Notes', 'handle_review' );
			}
			$this->loader->add_action( 'wp_ajax_revayat_member_profile', 'Revayat_Companion_Member_Profile', 'handle' );
			$this->loader->add_action( 'admin_post_revayat_member_profile', 'Revayat_Companion_Member_Profile', 'handle' );
			foreach ( array( 'wp_ajax_', 'wp_ajax_nopriv_' ) as $prefix ) {
				$this->loader->add_action( $prefix . 'revayat_auth_context', 'Revayat_Companion_Auth_Flow', 'context' );
				$this->loader->add_action( $prefix . 'revayat_auth_flow', 'Revayat_Companion_Auth_Flow', 'handle' );
			}
			$this->loader->add_action( 'admin_post_nopriv_revayat_login', $this->user_portal, 'handle_login' );
			$this->loader->add_action( 'admin_post_nopriv_revayat_otp_auth', $this->user_portal, 'handle_otp_auth' );
			$this->loader->add_action( 'admin_post_nopriv_revayat_request_otp', $this->user_portal, 'handle_request_otp' );
			$this->loader->add_action( 'admin_post_nopriv_revayat_recover_account', $this->user_portal, 'handle_recover_account' );
			$this->loader->add_action( 'admin_post_revayat_set_password', $this->user_portal, 'handle_set_password' );
			$this->loader->add_action( 'admin_post_revayat_submit_analyst_post', $this->user_portal, 'handle_submit_analyst_post' );
			$this->loader->add_action( 'admin_post_revayat_update_profile', $this->user_portal, 'handle_update_profile' );
			$this->loader->add_action( 'admin_post_revayat_mark_notifications_read', $this->user_portal, 'handle_mark_notifications_read' );
			$this->loader->add_action( 'admin_post_revayat_request_special_access', $this->user_portal, 'handle_request_special_access' );
			$this->loader->add_action( 'admin_post_revayat_vote_analyst_post', $this->user_portal, 'handle_vote_analyst_post' );
			$this->loader->add_action( 'wp_ajax_revayat_vote_analyst_post', $this->user_portal, 'handle_vote_analyst_post' );
			$this->loader->add_filter( 'show_admin_bar', $this->user_portal, 'hide_admin_bar' );
			$this->loader->add_filter( 'login_redirect', $this->user_portal, 'login_redirect', 10, 3 );
			$this->loader->add_action( 'phpmailer_init', $this->user_portal, 'configure_phpmailer' );
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
