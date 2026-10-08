<?php
/**
 * نقش‌ها، احراز هویت پایه و عملیات امن داشبورد فرانت‌اند.
 *
 * @package Revayat_Companion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Revayat_Companion_User_Portal {
	/** @var bool جلوگیری از بازگشت بازگشتی هنگام تغییر وضعیت نوشته. */
	private static $saving_review = false;

	public static function get_review_status_labels() {
		return array(
			'draft'             => 'پیش‌نویس',
			'pending'           => 'در انتظار بررسی',
			'changes_requested' => 'نیازمند اصلاح',
			'rejected'          => 'ردشده',
			'approved'          => 'تأیید و منتشرشده',
		);
	}
	/** کد ثابت فقط برای محیط‌های غیرپروداکشن. */
	public static function get_test_otp() {
		if ( 'production' === wp_get_environment_type() || ! defined( 'REVAYAT_ENABLE_TEST_OTP' ) || true !== REVAYAT_ENABLE_TEST_OTP ) {
			return '';
		}
		return (string) apply_filters( 'revayat_test_otp_code', '246810' );
	}

	/** نرمال‌سازی شماره موبایل ایران به قالب 09xxxxxxxxx. */
	public static function normalize_mobile( $mobile ) {
		$mobile = strtr(
			(string) $mobile,
			array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9' )
		);
		$mobile = preg_replace( '/\D+/', '', $mobile );
		if ( 12 === strlen( $mobile ) && 0 === strpos( $mobile, '98' ) ) {
			$mobile = '0' . substr( $mobile, 2 );
		}
		return $mobile;
	}

	/** وضعیت حساب؛ نبود متا برای سازگاری با حساب‌های قبلی به معنی فعال است. */
	public static function get_account_status( $user_id ) {
		$status = sanitize_key( get_user_meta( absint( $user_id ), '_revayat_account_status', true ) );
		return 'suspended' === $status ? 'suspended' : 'active';
	}

	public static function is_account_active( $user_id ) {
		return 'active' === self::get_account_status( $user_id );
	}

	/** یافتن حساب با شماره نرمال‌شده. */
	public static function get_user_by_mobile( $mobile ) {
		$mobile = self::normalize_mobile( $mobile );
		if ( ! preg_match( '/^09\d{9}$/', $mobile ) ) {
			return false;
		}
		$users = get_users(
			array(
				'meta_key'   => '_revayat_mobile',
				'meta_value' => $mobile,
				'number'     => 1,
				'fields'     => 'all',
			)
		);
		return $users ? $users[0] : false;
	}

	/** تبدیل شماره موبایل به نام کاربری داخلی برای ورود با رمز. */
	public static function resolve_login_identifier( $login ) {
		$mobile = self::normalize_mobile( $login );
		$user   = preg_match( '/^09\d{9}$/', $mobile ) ? self::get_user_by_mobile( $mobile ) : false;
		return $user instanceof WP_User ? $user->user_login : sanitize_text_field( $login );
	}

	/** ساخت حساب پایه پس از تأیید OTP با قفل کوتاه برای جلوگیری از حساب تکراری. */
    public static function create_mobile_member( $mobile ) {
        $mobile = self::normalize_mobile( $mobile );
        return Revayat_Companion_Workflow_Lock::run( 'mobile-identity:' . $mobile, static function () use ( $mobile ) { return self::create_mobile_member_locked( $mobile ); } );
    }
    private static function create_mobile_member_locked( $mobile ) {
		$mobile = self::normalize_mobile( $mobile );
		if ( ! preg_match( '/^09\d{9}$/', $mobile ) ) {
			return new WP_Error( 'invalid_mobile', 'شماره موبایل معتبر نیست.' );
		}
		$existing = self::get_user_by_mobile( $mobile );
		if ( $existing instanceof WP_User ) {
			return $existing;
		}
		$lock = '_revayat_mobile_create_' . hash_hmac( 'sha256', $mobile, wp_salt( 'auth' ) );
		if ( ! add_option( $lock, time(), '', false ) ) {
			return new WP_Error( 'account_creation_busy', 'ساخت حساب در حال انجام است؛ دوباره تلاش کنید.' );
		}
		try {
			$existing = self::get_user_by_mobile( $mobile );
			if ( $existing instanceof WP_User ) {
				return $existing;
			}
			$base  = 'rv_' . substr( hash_hmac( 'sha256', $mobile, wp_salt( 'logged_in' ) ), 0, 14 );
			$login = $base;
			$index = 1;
			while ( username_exists( $login ) ) {
				$login = $base . '_' . $index++;
			}
			$user_id = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_pass'    => wp_generate_password( 32, true, true ),
					'display_name' => 'عضو روایت ایران',
					'role'         => 'subscriber',
				)
			);
			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}
			update_user_meta( $user_id, '_revayat_mobile', $mobile );
			update_user_meta( $user_id, '_revayat_mobile_verified', 1 );
			update_user_meta( $user_id, '_revayat_mobile_verified_at', current_time( 'mysql', true ) );
			update_user_meta( $user_id, '_revayat_account_status', 'active' );
			return get_user_by( 'id', $user_id );
		} finally {
			delete_option( $lock );
		}
	}

	/** ثبت نقش‌ها و capabilityهای پروژه. */
	public static function register_roles() {
		add_role(
			'analyst',
			'تحلیلگر',
			array(
				'read'                        => true,
				'revayat_submit_analyst_post' => true,
				'revayat_rate_analyst_post'   => true,
			)
		);
		add_role(
			'vip_subscriber',
			'مشترک ویژه',
			array(
				'read'                       => true,
				'revayat_read_situation_room' => true,
				'revayat_rate_analyst_post'  => true,
			)
		);
		add_role( 'voter', 'ارزیاب', array( 'read' => true, 'revayat_rate_analyst_post' => true ) );

		foreach ( array( 'administrator', 'editor' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->add_cap( 'revayat_submit_analyst_post' );
				$role->add_cap( 'revayat_rate_analyst_post' );
				$role->add_cap( 'revayat_manage_pins' );
				$role->add_cap( 'revayat_read_situation_room' );
			}
		}
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( 'revayat_manage_approvals' );
		}
	}

	/** آمار واقعی رأی‌های یک یادداشت، بدون تکیه بر شمارنده قابل‌ویرایش. */
	public static function get_analyst_vote_summary( $post_id ) {
		return class_exists( 'Revayat_Companion_Analyst_Ratings' )
			? Revayat_Companion_Analyst_Ratings::get_summary( $post_id )
			: array( 'count' => 0, 'average' => 0.0, 'sum' => 0 );
	}

	/** ثبت یا تغییر رأی ارزیاب برای یادداشت منتشرشده (پشتیبانی از فرم سنتی و ایجکس ۱-کلیک). */
	public function handle_vote_analyst_post() {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$is_ajax = wp_doing_ajax();

		if ( ! Revayat_Companion_Member_Policy::can_rate( get_current_user_id(), $post_id ) ) {
			if ( $is_ajax ) {
				wp_send_json_error( array( 'message' => esc_html__( 'امکان ثبت رأی برای این یادداشت وجود ندارد.', 'revayat-companion' ) ), 403 );
			}
			wp_die( esc_html__( 'امکان ثبت رأی برای این یادداشت وجود ندارد.', 'revayat-companion' ), '', array( 'response' => 403 ) );
		}

		if ( $is_ajax ) {
			if ( ! check_ajax_referer( 'revayat_vote_analyst_' . $post_id, 'revayat_vote_nonce', false ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'اعتبار نشست یا توکن امنیتی منقضی شده است.', 'revayat-companion' ) ), 403 );
			}
		} else {
			check_admin_referer( 'revayat_vote_analyst_' . $post_id, 'revayat_vote_nonce' );
		}

		$rating = isset( $_POST['rating'] ) ? absint( $_POST['rating'] ) : 0;
		if ( $rating < 1 || $rating > 5 ) {
			if ( $is_ajax ) {
				wp_send_json_error( array( 'message' => esc_html__( 'امتیاز باید بین یک تا پنج باشد.', 'revayat-companion' ) ), 400 );
			}
			wp_die( 'امتیاز باید بین یک تا پنج باشد.', '', array( 'response' => 400 ) );
		}

		$result = Revayat_Companion_Analyst_Ratings::record_vote( $post_id, get_current_user_id(), $rating );
		if ( is_wp_error( $result ) ) {
			if ( $is_ajax ) {
				wp_send_json_error( array( 'message' => esc_html( $result->get_error_message() ) ), 409 );
			}
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 409 ) );
		}

		if ( $is_ajax ) {
			$summary = Revayat_Companion_Analyst_Ratings::get_summary( $post_id );
			wp_send_json_success( array(
				'message' => esc_html__( 'امتیاز شما با موفقیت ثبت شد.', 'revayat-companion' ),
				'rating'  => $rating,
				'average' => $summary['average'],
				'count'   => $summary['count'],
				'score'   => $summary['average'] > 0 ? number_format( (float) $summary['average'], 1 ) : '—',
			) );
		}

		wp_safe_redirect( add_query_arg( 'vote', 'saved', get_permalink( $post_id ) ) . '#engagement-panel' );
		exit;
	}

	/** ایجاد برگه‌های موردنیاز در فعال‌سازی. */
	public static function ensure_pages() {
		$pages = array(
			'auth'      => array( 'title' => 'ورود و عضویت', 'template' => 'page-templates/template-auth.php' ),
			'dashboard' => array( 'title' => 'پیشخوان کاربری', 'template' => 'page-templates/template-dashboard.php' ),
		);
		foreach ( $pages as $slug => $config ) {
			$page = get_page_by_path( $slug, OBJECT, 'page' );
			if ( ! $page ) {
				$page_id = wp_insert_post(
					array(
						'post_type'   => 'page',
						'post_status' => 'publish',
						'post_title'  => $config['title'],
						'post_name'   => $slug,
					)
				);
			} else {
				$page_id = $page->ID;
			}
			if ( $page_id && ! is_wp_error( $page_id ) ) {
				update_post_meta( $page_id, '_wp_page_template', $config['template'] );
			}
		}
	}

	/** ساخت یا بازیابی پروفایل عمومی person برای حساب تحلیلگر. */
	public static function ensure_person_profile( $user_id ) {
		$user_id = absint( $user_id );
		$user    = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new WP_Error( 'invalid_user', 'کاربر معتبر نیست.' );
		}

		$stored_id = absint( get_user_meta( $user_id, '_revayat_person_id', true ) );
		if ( $stored_id && 'person' === get_post_type( $stored_id ) ) {
			return $stored_id;
		}

		$linked = get_posts(
			array(
				'post_type'      => 'person',
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'meta_key'       => '_revayat_user_id',
				'meta_value'     => $user_id,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		if ( $linked ) {
			$person_id = (int) $linked[0];
		} else {
			$person_id = wp_insert_post(
				array(
					'post_type'    => 'person',
					'post_status'  => 'publish',
					'post_author'  => $user_id,
					'post_title'   => $user->display_name,
					'post_content' => wp_kses_post( get_user_meta( $user_id, 'description', true ) ),
				),
				true
			);
		}
		if ( is_wp_error( $person_id ) ) {
			return $person_id;
		}
		update_user_meta( $user_id, '_revayat_person_id', $person_id );
		update_post_meta( $person_id, '_revayat_user_id', $user_id );
		if ( class_exists( 'Revayat_Companion_Person_Identity' ) ) {
			Revayat_Companion_Person_Identity::ensure_term_for_person( $person_id );
		}
		return (int) $person_id;
	}

	/** قرارداد داده پروفایل داشبورد. */
	public static function get_user_profile( $user_id ) {
		$person_id = self::ensure_person_profile( $user_id );
		if ( is_wp_error( $person_id ) ) {
			return array();
		}
		$avatar_id = Revayat_Companion_Profile_Avatar::person_attachment( $person_id );
		$terms     = wp_get_object_terms( $person_id, 'analyst_field', array( 'fields' => 'ids' ) );
		return array(
			'person_id'    => $person_id,
			'permalink'    => get_permalink( $person_id ),
			'role_title'   => (string) get_post_meta( $person_id, '_revayat_role_title', true ),
			'organization' => (string) get_post_meta( $person_id, '_revayat_organization', true ),
			'expertise'    => (string) get_post_meta( $person_id, '_revayat_expertise', true ),
			'bio'          => (string) get_post_field( 'post_content', $person_id ),
			'avatar_id'    => $avatar_id,
			'avatar_url'   => Revayat_Companion_Profile_Avatar::person_url( $person_id ),
			'field_id'     => ( ! is_wp_error( $terms ) && $terms ) ? (int) $terms[0] : 0,
		);
	}

	/** فهرست یادداشت‌های داشبورد بدون کوئری مستقیم در پوسته. */
	public static function get_user_submissions( $user_id, $limit = 10 ) {
		return get_posts(
			array(
				'post_type'      => 'analyst_post',
				'author'         => absint( $user_id ),
				'post_status'    => array( 'pending', 'publish', 'draft' ),
				'posts_per_page' => max( 1, min( 50, absint( $limit ) ) ),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
	}

	/** شمارش واقعی وضعیت نوشته‌های یک تحلیلگر برای کارت‌های پیشخوان. */
	public static function get_user_submission_summary( $user_id ) {
		$user_id = absint( $user_id );
		$summary = array(
			'published'         => 0,
			'pending'           => 0,
			'draft'             => 0,
			'changes_requested' => 0,
			'rejected'          => 0,
			'total'             => 0,
		);
		if ( ! $user_id || ( get_current_user_id() !== $user_id && ! current_user_can( 'edit_users' ) ) ) {
			return $summary;
		}
		$ids = get_posts(
			array(
				'post_type'      => 'analyst_post',
				'author'         => $user_id,
				'post_status'    => array( 'pending', 'publish', 'draft' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		foreach ( $ids as $post_id ) {
			$status = sanitize_key( get_post_meta( $post_id, '_revayat_review_status', true ) );
			if ( ! $status ) {
				$status = 'publish' === get_post_status( $post_id ) ? 'published' : ( 'pending' === get_post_status( $post_id ) ? 'pending' : 'draft' );
			}
			if ( 'approved' === $status ) {
				$status = 'published';
			}
			if ( isset( $summary[ $status ] ) ) {
				++$summary[ $status ];
			} else {
				++$summary['draft'];
			}
		}
		$summary['total'] = count( $ids );
		return $summary;
	}

	/** گزینه‌های حوزه تخصصی برای فرم پروفایل. */
	public static function get_profile_field_options() {
		if ( ! taxonomy_exists( 'analyst_field' ) ) {
			return array();
		}
		$terms = get_terms( array( 'taxonomy' => 'analyst_field', 'hide_empty' => false ) );
		return is_wp_error( $terms ) ? array() : $terms;
	}

	/** ورود با نام کاربری/ایمیل و رمز عبور. */
	public function handle_login() {
		if ( ! isset( $_POST['revayat_login_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['revayat_login_nonce'] ) ), 'revayat_login' ) ) {
			$this->redirect_auth( 'invalid_nonce' );
		}
		$login    = isset( $_POST['login'] ) ? self::resolve_login_identifier( wp_unslash( $_POST['login'] ) ) : '';
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
		$user     = wp_signon( array( 'user_login' => $login, 'user_password' => $password, 'remember' => ! empty( $_POST['remember'] ) ), is_ssl() );
		if ( is_wp_error( $user ) ) {
			$this->redirect_auth( 'account_suspended' === $user->get_error_code() ? 'account_suspended' : 'login_failed', 'password' );
		}
		$requested_redirect = isset( $_POST['redirect_to'] ) ? wp_unslash( $_POST['redirect_to'] ) : '';
		$redirect_to        = wp_validate_redirect( $requested_redirect, home_url( '/dashboard/' ) );
		wp_safe_redirect( $redirect_to );
		exit;
	}

	/** ورود یا ساخت حساب عادی پس از تأیید کد یک‌بارمصرف. */
	public function handle_otp_auth() {
		if ( ! isset( $_POST['revayat_otp_auth_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['revayat_otp_auth_nonce'] ) ), 'revayat_otp_auth' ) ) {
			$this->redirect_auth( 'invalid_nonce' );
		}
		$mobile = self::normalize_mobile( isset( $_POST['mobile'] ) ? wp_unslash( $_POST['mobile'] ) : '' );
		$otp    = isset( $_POST['otp_code'] ) ? preg_replace( '/\D+/', '', Revayat_Companion_OTP_Service::digits( wp_unslash( $_POST['otp_code'] ) ) ) : '';
		if ( ! preg_match( '/^09\d{9}$/', $mobile ) ) {
			$this->redirect_auth( 'invalid_mobile', 'login' );
		}
		$verified = Revayat_Companion_OTP_Service::verify( $mobile, $otp, 'auth' );
		if ( is_wp_error( $verified ) ) {
			$this->redirect_auth( $verified->get_error_code(), 'login', array( 'mobile' => $mobile ) );
		}
		$user = self::get_user_by_mobile( $mobile );
		if ( ! $user ) {
			$user = self::create_mobile_member( $mobile );
		}
		if ( is_wp_error( $user ) || ! $user instanceof WP_User ) {
			$this->redirect_auth( 'registration_failed', 'login', array( 'mobile' => $mobile ) );
		}
		if ( ! self::is_account_active( $user->ID ) ) {
			$this->redirect_auth( 'account_suspended', 'login' );
		}
		update_user_meta( $user->ID, '_revayat_mobile_verified', 1 );
		update_user_meta( $user->ID, '_revayat_mobile_verified_at', current_time( 'mysql', true ) );
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, ! empty( $_POST['remember'] ), is_ssl() );
		do_action( 'wp_login', $user->user_login, $user );
		$requested_redirect = isset( $_POST['redirect_to'] ) ? wp_unslash( $_POST['redirect_to'] ) : '';
		wp_safe_redirect( wp_validate_redirect( $requested_redirect, home_url( '/dashboard/' ) ) );
		exit;
	}

	/** وضعیت درخواست دسترسی ویژه برای نمایش در پیشخوان. */
	public static function get_special_access_status( $user_id ) {
		$user = get_user_by( 'id', absint( $user_id ) );
		if ( ! $user ) {
			return 'none';
		}
		if ( user_can( $user, 'revayat_read_situation_room' ) || user_can( $user, 'manage_options' ) ) {
			return 'approved';
		}
		$status = sanitize_key( get_user_meta( $user->ID, '_revayat_special_access_status', true ) );
		return in_array( $status, array( 'pending', 'rejected' ), true ) ? $status : 'none';
	}

	/** ثبت درخواست دسترسی ویژه توسط کاربر واردشده. */
	public function handle_request_special_access() {
        if ( ! is_user_logged_in() ) { auth_redirect(); }
        wp_safe_redirect( home_url( '/dashboard/?view=situation-access' ) ); exit;
    }

	/** ثبت درخواست عضویت تحلیلگر؛ حساب تا تایید مدیر subscriber باقی می‌ماند. */
	public function handle_register() {
		if ( ! isset( $_POST['revayat_register_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['revayat_register_nonce'] ) ), 'revayat_register' ) ) {
			$this->redirect_auth( 'invalid_nonce', 'register' );
		}
		$name     = isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '';
		$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
		$mobile   = self::normalize_mobile( isset( $_POST['mobile'] ) ? wp_unslash( $_POST['mobile'] ) : '' );
		$otp      = isset( $_POST['otp_code'] ) ? preg_replace( '/\D+/', '', Revayat_Companion_OTP_Service::digits( wp_unslash( $_POST['otp_code'] ) ) ) : '';
		$requested_role = isset( $_POST['requested_role'] ) ? sanitize_key( wp_unslash( $_POST['requested_role'] ) ) : 'analyst';
		if ( ! $name || ! is_email( $email ) || strlen( $password ) < 8 ) {
			$this->redirect_auth( 'invalid_registration', 'register' );
		}
		if ( ! preg_match( '/^09\d{9}$/', $mobile ) ) {
			$this->redirect_auth( 'invalid_mobile', 'register' );
		}
		if ( ! in_array( $requested_role, array( 'analyst', 'voter' ), true ) ) {
			$this->redirect_auth( 'invalid_role', 'register' );
		}
		$verified = Revayat_Companion_OTP_Service::verify( $mobile, $otp, 'register' );
		if ( is_wp_error( $verified ) ) {
			$this->redirect_auth( $verified->get_error_code(), 'register' );
		}
		if ( email_exists( $email ) ) {
			$this->redirect_auth( 'email_exists', 'register' );
		}
		$mobile_users = get_users( array( 'meta_key' => '_revayat_mobile', 'meta_value' => $mobile, 'number' => 1, 'fields' => 'ID' ) );
		if ( ! empty( $mobile_users ) ) {
			$this->redirect_auth( 'mobile_exists', 'register' );
		}
		$base = sanitize_user( current( explode( '@', $email ) ), true ) ?: $requested_role;
		$login = $base;
		$index = 1;
		while ( username_exists( $login ) ) {
			$login = $base . $index++;
		}
		$user_id = wp_insert_user( array( 'user_login' => $login, 'user_email' => $email, 'user_pass' => $password, 'display_name' => $name, 'role' => 'subscriber' ) );
		if ( is_wp_error( $user_id ) ) {
			$this->redirect_auth( 'registration_failed', 'register' );
		}
		update_user_meta( $user_id, '_revayat_requested_role', $requested_role );
		update_user_meta( $user_id, '_revayat_approval_status', 'pending' );
		update_user_meta( $user_id, '_revayat_mobile', $mobile );
		update_user_meta( $user_id, '_revayat_mobile_verified', 1 );
		update_user_meta( $user_id, '_revayat_mobile_verified_at', current_time( 'mysql', true ) );
		do_action( 'revayat_access_status_changed', $user_id, 'عضویت تحلیلگری', 'pending', wp_generate_uuid4() );
		$this->redirect_auth( 'registration_pending', 'login' );
	}

	/** درخواست ارسال OTP برای ثبت‌نام یا بازیابی حساب. */
	public function handle_request_otp() {
		if ( ! isset( $_POST['revayat_otp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['revayat_otp_nonce'] ) ), 'revayat_request_otp' ) ) {
			$this->redirect_auth( 'invalid_nonce', 'register' );
		}
		$mobile  = self::normalize_mobile( isset( $_POST['mobile'] ) ? wp_unslash( $_POST['mobile'] ) : '' );
		$context = isset( $_POST['otp_context'] ) ? sanitize_key( wp_unslash( $_POST['otp_context'] ) ) : 'auth';
		$context = 'recover' === $context ? 'recover' : 'auth';
		$tab     = 'recover' === $context ? 'recover' : 'login';
		$result  = Revayat_Companion_OTP_Service::request( $mobile, $context );
		$notice  = is_wp_error( $result ) ? $result->get_error_code() : 'otp_sent';
		if ( is_wp_error( $result ) && ! in_array( $notice, array( 'invalid_mobile', 'otp_rate_limited', 'otp_gateway_unconfigured', 'otp_gateway_rejected', 'invalid_otp_gateway' ), true ) ) {
			$notice = 'otp_send_failed';
		}
		$extra = array( 'mobile' => $mobile );
		if ( ! empty( $_POST['redirect_to'] ) ) {
			$extra['redirect_to'] = wp_validate_redirect( wp_unslash( $_POST['redirect_to'] ), '' );
		}
		$this->redirect_auth( $notice, $tab, $extra );
	}

	/** تعیین یا تغییر رمز اختیاری از داشبورد. */
	public function handle_set_password() {
		if ( ! is_user_logged_in() || ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز است.', 'revayat-companion' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'revayat_set_password', 'revayat_password_nonce' );
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
		$confirm  = isset( $_POST['password_confirm'] ) ? (string) wp_unslash( $_POST['password_confirm'] ) : '';
		if ( strlen( $password ) < 8 || $password !== $confirm ) {
			$this->redirect_dashboard( 'invalid_password' );
		}
		$user_id = get_current_user_id();
		wp_set_password( $password, $user_id );
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true, is_ssl() );
		update_user_meta( $user_id, '_revayat_password_enabled', 1 );
		$this->redirect_dashboard( 'password_updated' );
	}

	/** جلوگیری از ورود حساب معلق در تمام مسیرهای احراز هویت وردپرس. */
	public function prevent_suspended_authentication( $user ) {
		if ( $user instanceof WP_User && ! self::is_account_active( $user->ID ) ) {
			return new WP_Error( 'account_suspended', 'این حساب موقتاً غیرفعال است.' );
		}
		return $user;
	}

	/** نشست حسابی که بعداً معلق شده نیز ادامه پیدا نکند. */
	public function enforce_active_session() {
		if ( is_user_logged_in() && ! self::is_account_active( get_current_user_id() ) ) {
			wp_logout();
			if ( wp_doing_ajax() ) {
				wp_send_json_error( array( 'message' => 'این حساب موقتاً غیرفعال است.' ), 403 );
			}
			if ( wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
				return;
			}
			wp_safe_redirect( add_query_arg( array( 'tab' => 'login', 'notice' => 'account_suspended' ), home_url( '/auth/' ) ) );
			exit;
		}
	}

	/** بازیابی رمز با موبایل تأییدشده و OTP یک‌بارمصرف. */
	public function handle_recover_account() {
		if ( ! isset( $_POST['revayat_recover_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['revayat_recover_nonce'] ) ), 'revayat_recover_account' ) ) {
			$this->redirect_auth( 'invalid_nonce', 'recover' );
		}
		$mobile   = self::normalize_mobile( isset( $_POST['mobile'] ) ? wp_unslash( $_POST['mobile'] ) : '' );
		$otp      = isset( $_POST['otp_code'] ) ? preg_replace( '/\D+/', '', Revayat_Companion_OTP_Service::digits( wp_unslash( $_POST['otp_code'] ) ) ) : '';
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
		if ( strlen( $password ) < 8 ) {
			$this->redirect_auth( 'invalid_password', 'recover', array( 'mobile' => $mobile ) );
		}
		$verified = Revayat_Companion_OTP_Service::verify( $mobile, $otp, 'recover' );
		if ( is_wp_error( $verified ) ) {
			$this->redirect_auth( $verified->get_error_code(), 'recover', array( 'mobile' => $mobile ) );
		}
		$users = get_users( array( 'meta_key' => '_revayat_mobile', 'meta_value' => $mobile, 'number' => 1 ) );
		if ( ! $users ) {
			$this->redirect_auth( 'mobile_not_found', 'recover' );
		}
		wp_set_password( $password, $users[0]->ID );
		$this->redirect_auth( 'password_reset', 'login' );
	}

	/** ثبت یادداشت تحلیلگر در صف بررسی تحریریه. */
	public function handle_submit_analyst_post() {
        if ( ! is_user_logged_in() ) { auth_redirect(); }
        wp_safe_redirect( home_url( '/dashboard/?view=note-edit' ) ); exit;
    }

	/** داده یک یادداشت قابل ویرایش متعلق به کاربر. */
	public static function get_editable_submission( $post_id, $user_id ) {
		$post = get_post( absint( $post_id ) );
		$review_status = $post ? (string) get_post_meta( $post->ID, '_revayat_review_status', true ) : '';
		if ( ! $post || 'analyst_post' !== $post->post_type || (int) $post->post_author !== absint( $user_id ) || 'draft' !== $post->post_status || ! in_array( $review_status, array( 'draft', 'changes_requested' ), true ) ) {
			return null;
		}
		return $post;
	}

	/** بازخورد یک یادداشت فقط برای مالک همان یادداشت. */
	public static function get_submission_editorial_note( $post_id, $user_id ) {
		$post = get_post( absint( $post_id ) );
		if ( ! $post || 'analyst_post' !== $post->post_type || (int) $post->post_author !== absint( $user_id ) ) {
			return '';
		}
		return (string) get_post_meta( $post->ID, '_revayat_editorial_note', true );
	}

	/** وضعیت نمایشی گردش کار فقط برای مالک نوشته. */
	public static function get_submission_state( $post_id, $user_id ) {
		$post = get_post( absint( $post_id ) );
		if ( ! $post || 'analyst_post' !== $post->post_type || (int) $post->post_author !== absint( $user_id ) ) {
			return array();
		}
		$status = (string) get_post_meta( $post->ID, '_revayat_review_status', true );
		if ( ! $status ) {
			$status = 'publish' === $post->post_status ? 'approved' : ( 'pending' === $post->post_status ? 'pending' : 'draft' );
		}
		$labels = self::get_review_status_labels();
		return array(
			'slug'     => $status,
			'label'    => $labels[ $status ] ?? $status,
			'editable' => 'draft' === $post->post_status && in_array( $status, array( 'draft', 'changes_requested' ), true ),
		);
	}

	/** اعلان‌های تحریریه متعلق به کاربر جاری. */
	public static function get_user_notifications( $user_id, $limit = 10 ) {
		$user_id = absint( $user_id );
		if ( ! $user_id || ( get_current_user_id() !== $user_id && ! current_user_can( 'edit_users' ) ) ) {
			return array();
		}
		$stored = get_user_meta( $user_id, '_revayat_portal_notifications', true );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$notifications = array();
		foreach ( $stored as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) || empty( $item['message'] ) ) {
				continue;
			}
			$notifications[] = array(
				'id'         => sanitize_text_field( $item['id'] ),
				'post_id'    => absint( $item['post_id'] ?? 0 ),
				'status'     => sanitize_key( $item['status'] ?? '' ),
				'message'    => sanitize_text_field( $item['message'] ),
				'created_at' => sanitize_text_field( $item['created_at'] ?? '' ),
				'read'       => ! empty( $item['read'] ),
			);
		}
		return array_slice( $notifications, 0, max( 1, min( 30, absint( $limit ) ) ) );
	}

	/** ثبت اعلان محدود و ساختاریافته برای مالک یادداشت. */
	private static function add_editorial_notification( $post, $status ) {
		if ( ! $post instanceof WP_Post || ! $post->post_author ) {
			return;
		}
		$messages = array(
			'pending'           => 'یادداشت «%s» در صف بررسی تحریریه قرار گرفت.',
			'changes_requested' => 'تحریریه برای یادداشت «%s» درخواست اصلاح ثبت کرد.',
			'rejected'          => 'یادداشت «%s» در بررسی تحریریه رد شد.',
			'approved'          => 'یادداشت «%s» تأیید و منتشر شد.',
		);
		if ( ! isset( $messages[ $status ] ) ) {
			return;
		}
		$notifications = get_user_meta( (int) $post->post_author, '_revayat_portal_notifications', true );
		$notifications = is_array( $notifications ) ? $notifications : array();
		array_unshift(
			$notifications,
			array(
				'id'         => wp_generate_uuid4(),
				'post_id'    => (int) $post->ID,
				'status'     => $status,
				'message'    => sprintf( $messages[ $status ], wp_strip_all_tags( get_the_title( $post ) ) ),
				'created_at' => current_time( 'mysql', true ),
				'read'       => false,
			)
		);
		update_user_meta( (int) $post->post_author, '_revayat_portal_notifications', array_slice( $notifications, 0, 30 ) );
	}

	/** ارسال ایمیل تغییر وضعیت به نویسنده یادداشت. */
	private static function send_editorial_email( $post, $status, $note = '' ) {
		if ( ! $post instanceof WP_Post || ! $post->post_author ) {
			return false;
		}
		$user   = get_user_by( 'id', (int) $post->post_author );
		$labels = self::get_review_status_labels();
		if ( ! $user || ! is_email( $user->user_email ) || ! isset( $labels[ $status ] ) ) {
			return false;
		}
		if ( ! apply_filters( 'revayat_editorial_email_enabled', true, $post, $status, $user ) ) {
			return false;
		}

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$title     = wp_strip_all_tags( get_the_title( $post ) );
		$subject   = sprintf( '[%s] وضعیت یادداشت: %s', $site_name, $labels[ $status ] );
		$lines     = array(
			sprintf( 'سلام %s،', wp_strip_all_tags( $user->display_name ) ),
			'',
			sprintf( 'وضعیت یادداشت «%s» به «%s» تغییر کرد.', $title, $labels[ $status ] ),
		);
		if ( $note ) {
			$lines[] = '';
			$lines[] = 'بازخورد تحریریه:';
			$lines[] = sanitize_textarea_field( $note );
		}
		$lines[] = '';
		$lines[] = 'مشاهده پیشخوان: ' . home_url( '/dashboard/' );
		$mail = apply_filters(
			'revayat_editorial_email_args',
			array(
				'to'      => sanitize_email( $user->user_email ),
				'subject' => $subject,
				'message' => implode( "\n", $lines ),
				'headers' => array( 'Content-Type: text/plain; charset=UTF-8' ),
			),
			$post,
			$status,
			$user
		);
		if ( ! is_array( $mail ) || empty( $mail['to'] ) || empty( $mail['subject'] ) || empty( $mail['message'] ) ) {
			return false;
		}
		$failure_message = '';
		$failure_handler = static function ( $error ) use ( &$failure_message ) {
			if ( $error instanceof WP_Error ) {
				$failure_message = $error->get_error_message();
			}
		};
		add_action( 'wp_mail_failed', $failure_handler, 10, 1 );
		$result = wp_mail( $mail['to'], $mail['subject'], $mail['message'], $mail['headers'] ?? array() );
		remove_action( 'wp_mail_failed', $failure_handler, 10 );
		self::log_editorial_email( $post, $status, $mail['to'], $mail['subject'], $result, $failure_message );
		return $result;
	}

	/** نگهداری گزارش محدود ارسال ایمیل برای عیب‌یابی مدیر. */
	private static function log_editorial_email( $post, $status, $recipient, $subject, $result, $error = '' ) {
		$log = get_option( '_revayat_editorial_email_log', array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				'id'         => wp_generate_uuid4(),
				'post_id'    => (int) $post->ID,
				'user_id'    => (int) $post->post_author,
				'status'     => sanitize_key( $status ),
				'recipient'  => sanitize_email( $recipient ),
				'subject'    => sanitize_text_field( $subject ),
				'result'     => $result ? 'accepted' : 'failed',
				'error'      => $result ? '' : sanitize_text_field( $error ?: 'wp_mail نتیجه ناموفق برگرداند.' ),
				'created_at' => current_time( 'mysql', true ),
			)
		);
		update_option( '_revayat_editorial_email_log', array_slice( $log, 0, 100 ), false );
	}

	/** گزارش ارسال ایمیل فقط برای مدیر سامانه. */
	public static function get_email_delivery_log( $limit = 50 ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array();
		}
		$log = get_option( '_revayat_editorial_email_log', array() );
		return is_array( $log ) ? array_slice( $log, 0, max( 1, min( 100, absint( $limit ) ) ) ) : array();
	}

	/** اعمال SMTP فقط در صورت تعریف صریح ثابت‌های امن سرور. */
	public function configure_phpmailer( $phpmailer ) {
		if ( ! defined( 'REVAYAT_SMTP_HOST' ) || ! REVAYAT_SMTP_HOST ) {
			return;
		}
		$phpmailer->isSMTP();
		$phpmailer->Host       = sanitize_text_field( REVAYAT_SMTP_HOST );
		$phpmailer->Port       = defined( 'REVAYAT_SMTP_PORT' ) ? max( 1, min( 65535, absint( REVAYAT_SMTP_PORT ) ) ) : 587;
		$phpmailer->CharSet    = 'UTF-8';
		$phpmailer->SMTPAuth   = defined( 'REVAYAT_SMTP_USERNAME' ) && '' !== (string) REVAYAT_SMTP_USERNAME;
		$phpmailer->Username   = $phpmailer->SMTPAuth ? (string) REVAYAT_SMTP_USERNAME : '';
		$phpmailer->Password   = $phpmailer->SMTPAuth && defined( 'REVAYAT_SMTP_PASSWORD' ) ? (string) REVAYAT_SMTP_PASSWORD : '';
		$encryption            = defined( 'REVAYAT_SMTP_ENCRYPTION' ) ? strtolower( sanitize_key( REVAYAT_SMTP_ENCRYPTION ) ) : 'tls';
		$phpmailer->SMTPSecure = in_array( $encryption, array( 'tls', 'ssl' ), true ) ? $encryption : '';
		if ( defined( 'REVAYAT_SMTP_FROM_EMAIL' ) && is_email( REVAYAT_SMTP_FROM_EMAIL ) ) {
			$from_name = defined( 'REVAYAT_SMTP_FROM_NAME' ) ? sanitize_text_field( REVAYAT_SMTP_FROM_NAME ) : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			$phpmailer->setFrom( sanitize_email( REVAYAT_SMTP_FROM_EMAIL ), $from_name, false );
		}
	}

	/** علامت‌گذاری امن همه اعلان‌های کاربر جاری به‌عنوان خوانده‌شده. */
	public function handle_mark_notifications_read() {
		if ( ! is_user_logged_in() || ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز است.', 'revayat-companion' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'revayat_mark_notifications_read', 'revayat_notifications_nonce' );
		$user_id       = get_current_user_id();
		$notifications = get_user_meta( $user_id, '_revayat_portal_notifications', true );
		if ( is_array( $notifications ) ) {
			foreach ( $notifications as &$notification ) {
				if ( is_array( $notification ) ) {
					$notification['read'] = true;
				}
			}
			unset( $notification );
			update_user_meta( $user_id, '_revayat_portal_notifications', array_slice( $notifications, 0, 30 ) );
		}
		$this->redirect_dashboard( 'notifications_read' );
	}

	/** متاباکس بازخورد تحریریه. */
	public function register_editorial_note_metabox() {
		add_meta_box( 'revayat-editorial-note', 'بازخورد برای تحلیلگر', array( $this, 'render_editorial_note_metabox' ), 'analyst_post', 'side', 'high' );
	}

	public function render_editorial_note_metabox( $post ) {
		wp_nonce_field( 'revayat_save_editorial_note', 'revayat_editorial_note_nonce' );
		$note = (string) get_post_meta( $post->ID, '_revayat_editorial_note', true );
		$status = (string) get_post_meta( $post->ID, '_revayat_review_status', true ) ?: ( 'publish' === $post->post_status ? 'approved' : 'pending' );
		$labels = self::get_review_status_labels();
		?><p><label for="revayat-review-status"><strong><?php esc_html_e( 'وضعیت بررسی', 'revayat-companion' ); ?></strong></label></p><select id="revayat-review-status" name="revayat_review_status" style="width:100%"><?php foreach ( array( 'pending', 'changes_requested', 'rejected', 'approved' ) as $key ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $labels[ $key ] ); ?></option><?php endforeach; ?></select><p><label for="revayat-editorial-note-field"><?php esc_html_e( 'این متن در داشبورد تحلیلگر نمایش داده می‌شود.', 'revayat-companion' ); ?></label></p><textarea id="revayat-editorial-note-field" name="revayat_editorial_note" rows="7" style="width:100%"><?php echo esc_textarea( $note ); ?></textarea><?php
	}

	/** ذخیره امن بازخورد دبیر. */
	public function save_editorial_note( $post_id, $post ) {
		if ( self::$saving_review ) {
			return;
		}
		if ( ! $post instanceof WP_Post || 'analyst_post' !== $post->post_type || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['revayat_editorial_note_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['revayat_editorial_note_nonce'] ) ), 'revayat_save_editorial_note' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$previous_status = (string) get_post_meta( $post_id, '_revayat_review_status', true );
		if ( ! $previous_status ) {
			$previous_status = 'publish' === $post->post_status ? 'approved' : ( 'pending' === $post->post_status ? 'pending' : 'draft' );
		}
		$note = isset( $_POST['revayat_editorial_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['revayat_editorial_note'] ) ) : '';
		$status = isset( $_POST['revayat_review_status'] ) ? sanitize_key( wp_unslash( $_POST['revayat_review_status'] ) ) : 'pending';
		if ( ! in_array( $status, array( 'pending', 'changes_requested', 'rejected', 'approved' ), true ) ) {
			$status = 'pending';
		}
		if ( $note ) {
			update_post_meta( $post_id, '_revayat_editorial_note', $note );
		} else {
			delete_post_meta( $post_id, '_revayat_editorial_note' );
		}
		update_post_meta( $post_id, '_revayat_review_status', $status );
		$target_post_status = 'approved' === $status ? 'publish' : ( 'pending' === $status ? 'pending' : 'draft' );
		if ( $target_post_status !== $post->post_status ) {
			self::$saving_review = true;
			wp_update_post( array( 'ID' => $post_id, 'post_status' => $target_post_status ) );
			self::$saving_review = false;
		}
		if ( $previous_status !== $status ) {
			self::add_editorial_notification( $post, $status );
			self::send_editorial_email( $post, $status, $note );
			do_action( 'revayat_analyst_post_status_changed', $post_id, $status, wp_generate_uuid4() );
		}
	}

	/** بروزرسانی امن پروفایل حرفه‌ای تحلیلگر. */
	public function handle_update_profile() {
		if ( ! is_user_logged_in() || ! current_user_can( 'revayat_submit_analyst_post' ) ) {
			wp_die( esc_html__( 'شما اجازه ویرایش این پروفایل را ندارید.', 'revayat-companion' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'revayat_update_profile', 'revayat_profile_nonce' );
		$user_id   = get_current_user_id();
		$person_id = self::ensure_person_profile( $user_id );
		if ( is_wp_error( $person_id ) ) {
			$this->redirect_dashboard( 'profile_failed' );
		}

		$display_name = isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '';
		$role_title   = isset( $_POST['role_title'] ) ? sanitize_text_field( wp_unslash( $_POST['role_title'] ) ) : '';
		$organization = isset( $_POST['organization'] ) ? sanitize_text_field( wp_unslash( $_POST['organization'] ) ) : '';
		$expertise    = isset( $_POST['expertise'] ) ? sanitize_text_field( wp_unslash( $_POST['expertise'] ) ) : '';
		$bio          = isset( $_POST['bio'] ) ? wp_kses_post( wp_unslash( $_POST['bio'] ) ) : '';
		$field_id     = isset( $_POST['analyst_field'] ) ? absint( $_POST['analyst_field'] ) : 0;
		if ( mb_strlen( $display_name ) < 3 || mb_strlen( wp_strip_all_tags( $bio ) ) > 3000 ) {
			$this->redirect_dashboard( 'profile_invalid' );
		}

		wp_update_user( array( 'ID' => $user_id, 'display_name' => $display_name, 'description' => wp_strip_all_tags( $bio ) ) );
		$result = wp_update_post( array( 'ID' => $person_id, 'post_title' => $display_name, 'post_content' => $bio ), true );
		if ( is_wp_error( $result ) ) {
			$this->redirect_dashboard( 'profile_failed' );
		}
		update_post_meta( $person_id, '_revayat_role_title', $role_title );
		update_post_meta( $person_id, '_revayat_organization', $organization );
		update_post_meta( $person_id, '_revayat_expertise', $expertise );
		if ( $field_id && term_exists( $field_id, 'analyst_field' ) ) {
			wp_set_object_terms( $person_id, array( $field_id ), 'analyst_field', false );
		} else {
			wp_set_object_terms( $person_id, array(), 'analyst_field', false );
		}

		if ( is_wp_error( Revayat_Companion_Profile_Avatar::upload( $user_id ) ) ) {
			$this->redirect_dashboard( 'avatar_invalid' );
		}
		$this->redirect_dashboard( 'profile_updated' );
	}

	/** افزودن کارتابل بررسی درخواست‌ها زیر منوی کاربران. */
	public function register_approval_page() {
		add_users_page(
			'درخواست‌های شبکه تحلیلگران',
			'عضویت شبکه تحلیلگران',
			'revayat_manage_approvals',
			'revayat-analyst-approvals',
			array( $this, 'render_approval_page' )
		);
	}

	/** کارتابل مستقل بررسی دسترسی اتاق وضعیت. */
	public function register_special_access_page() {
		add_users_page(
			'درخواست‌های دسترسی ویژه',
			'دسترسی ویژه',
			'revayat_manage_approvals',
			'revayat-special-access',
			array( $this, 'render_special_access_page' )
		);
	}

	/** نمایش درخواست‌های ویژه بدون افشای محتوای اتاق وضعیت. */
	public function render_special_access_page() {
		if ( ! current_user_can( 'revayat_manage_approvals' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز است.', 'revayat-companion' ), '', array( 'response' => 403 ) );
		}
		$applicants = get_users(
			array(
				'meta_key'   => '_revayat_special_access_status',
				'meta_value' => 'pending',
				'orderby'    => 'registered',
				'order'      => 'ASC',
			)
		);
		$notice = isset( $_GET['reviewed'] ) ? sanitize_key( wp_unslash( $_GET['reviewed'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'درخواست‌های دسترسی ویژه اتاق وضعیت', 'revayat-companion' ); ?></h1>
			<p><?php esc_html_e( 'تأیید درخواست فقط capability لازم را به حساب اضافه می‌کند و نقش اصلی کاربر را تغییر نمی‌دهد.', 'revayat-companion' ); ?></p>
			<?php if ( $notice ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'وضعیت درخواست به‌روزرسانی شد.', 'revayat-companion' ); ?></p></div><?php endif; ?>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'نام', 'revayat-companion' ); ?></th><th><?php esc_html_e( 'ایمیل', 'revayat-companion' ); ?></th><th><?php esc_html_e( 'نقش فعلی', 'revayat-companion' ); ?></th><th><?php esc_html_e( 'زمان درخواست', 'revayat-companion' ); ?></th><th><?php esc_html_e( 'عملیات', 'revayat-companion' ); ?></th></tr></thead>
				<tbody>
				<?php if ( $applicants ) : foreach ( $applicants as $applicant ) :
					$base_url    = admin_url( 'admin-post.php' );
					$approve_url = wp_nonce_url( add_query_arg( array( 'action' => 'revayat_review_special_access', 'user_id' => $applicant->ID, 'decision' => 'approve' ), $base_url ), 'revayat_review_special_access_' . $applicant->ID );
					$reject_url  = wp_nonce_url( add_query_arg( array( 'action' => 'revayat_review_special_access', 'user_id' => $applicant->ID, 'decision' => 'reject' ), $base_url ), 'revayat_review_special_access_' . $applicant->ID );
					$roles       = array_map( 'translate_user_role', $applicant->roles );
				?>
				<tr><td><?php echo esc_html( $applicant->display_name ); ?></td><td><?php echo esc_html( $applicant->user_email ); ?></td><td><?php echo esc_html( implode( '، ', $roles ) ); ?></td><td><?php echo esc_html( get_user_meta( $applicant->ID, '_revayat_special_access_requested_at', true ) ); ?></td><td><a class="button button-primary" href="<?php echo esc_url( $approve_url ); ?>"><?php esc_html_e( 'تأیید', 'revayat-companion' ); ?></a> <a class="button" href="<?php echo esc_url( $reject_url ); ?>"><?php esc_html_e( 'رد', 'revayat-companion' ); ?></a></td></tr>
				<?php endforeach; else : ?><tr><td colspan="5"><?php esc_html_e( 'درخواستی در انتظار بررسی نیست.', 'revayat-companion' ); ?></td></tr><?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/** اعمال تصمیم مدیر به‌صورت مستقل از نقش اصلی کاربر. */
	public static function review_special_access( $user_id, $decision ) {
        if ( Revayat_Companion_Member_Applications::latest( $user_id, 'situation' ) ) { return new WP_Error( 'new_workflow', 'این درخواست را از صفحه درخواست‌های اعضا بررسی کنید.' ); }
		$user     = get_user_by( 'id', absint( $user_id ) );
		$decision = sanitize_key( $decision );
		if ( ! $user || ! in_array( $decision, array( 'approve', 'reject' ), true ) ) {
			return new WP_Error( 'invalid_special_access_request', 'درخواست معتبر نیست.' );
		}
		$previous = get_user_meta( $user->ID, '_revayat_special_access_status', true );
		if ( 'approve' === $decision ) {
			$user->add_cap( 'revayat_read_situation_room', true );
			update_user_meta( $user->ID, '_revayat_special_access_status', 'approved' );
		} else {
			$user->remove_cap( 'revayat_read_situation_room' );
			update_user_meta( $user->ID, '_revayat_special_access_status', 'rejected' );
		}
		update_user_meta( $user->ID, '_revayat_special_access_reviewed_at', current_time( 'mysql', true ) );
		$status = 'approve' === $decision ? 'approved' : 'rejected';
		if ( $previous !== $status ) { do_action( 'revayat_access_status_changed', $user->ID, 'اتاق وضعیت', $status, wp_generate_uuid4() ); }
		return true;
	}

	/** هندلر امن تصمیم مدیر برای درخواست ویژه. */
	public function handle_review_special_access() {
		if ( ! current_user_can( 'revayat_manage_approvals' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز است.', 'revayat-companion' ), '', array( 'response' => 403 ) );
		}
		$user_id  = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		$decision = isset( $_GET['decision'] ) ? sanitize_key( wp_unslash( $_GET['decision'] ) ) : '';
		check_admin_referer( 'revayat_review_special_access_' . $user_id );
		$result = self::review_special_access( $user_id, $decision );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 400 ) );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'revayat-special-access', 'reviewed' => $decision ), admin_url( 'users.php' ) ) );
		exit;
	}

	/** افزودن گزارش سلامت و ارسال ایمیل زیر منوی ابزارها. */
	public function register_email_report_page() {
		add_management_page(
			'گزارش ایمیل‌های تحریریه',
			'ایمیل‌های تحریریه',
			'manage_options',
			'revayat-editorial-email-log',
			array( $this, 'render_email_report_page' )
		);
	}

	/** نمایش گزارش بدون افشای رمز یا نام کاربری SMTP. */
	public function render_email_report_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز است.', 'revayat-companion' ), '', array( 'response' => 403 ) );
		}
		$log             = self::get_email_delivery_log( 100 );
		$smtp_configured = defined( 'REVAYAT_SMTP_HOST' ) && REVAYAT_SMTP_HOST;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'گزارش ایمیل‌های تحریریه', 'revayat-companion' ); ?></h1>
			<div class="notice <?php echo $smtp_configured ? 'notice-success' : 'notice-warning'; ?> inline"><p>
				<?php if ( $smtp_configured ) : ?>
					<?php printf( esc_html__( 'SMTP برای میزبان %1$s و پورت %2$d پیکربندی شده است.', 'revayat-companion' ), esc_html( REVAYAT_SMTP_HOST ), defined( 'REVAYAT_SMTP_PORT' ) ? absint( REVAYAT_SMTP_PORT ) : 587 ); ?>
				<?php else : ?>
					<?php esc_html_e( 'SMTP اختصاصی پیکربندی نشده و وردپرس از Mail پیش‌فرض سرور استفاده می‌کند.', 'revayat-companion' ); ?>
				<?php endif; ?>
			</p></div>
			<p><?php esc_html_e( 'وضعیت «پذیرفته‌شده» یعنی wp_mail پیام را بدون خطا پذیرفته است و به‌تنهایی تحویل نهایی به صندوق گیرنده را تضمین نمی‌کند.', 'revayat-companion' ); ?></p>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'زمان', 'revayat-companion' ); ?></th><th><?php esc_html_e( 'یادداشت', 'revayat-companion' ); ?></th><th><?php esc_html_e( 'گیرنده', 'revayat-companion' ); ?></th><th><?php esc_html_e( 'وضعیت محتوا', 'revayat-companion' ); ?></th><th><?php esc_html_e( 'نتیجه ارسال', 'revayat-companion' ); ?></th><th><?php esc_html_e( 'خطا', 'revayat-companion' ); ?></th></tr></thead>
				<tbody>
				<?php if ( $log ) : foreach ( $log as $item ) :
					$post_id    = absint( $item['post_id'] ?? 0 );
					$post_title = get_the_title( $post_id ) ?: '#' . $post_id;
					$status_key = sanitize_key( $item['status'] ?? '' );
					$labels     = self::get_review_status_labels();
					?>
					<tr><td><?php echo esc_html( get_date_from_gmt( $item['created_at'] ?? '', 'Y/m/d H:i' ) ); ?></td><td><?php echo esc_html( $post_title ); ?></td><td><?php echo esc_html( $item['recipient'] ?? '' ); ?></td><td><?php echo esc_html( $labels[ $status_key ] ?? $status_key ); ?></td><td><?php echo 'accepted' === ( $item['result'] ?? '' ) ? esc_html__( 'پذیرفته‌شده', 'revayat-companion' ) : esc_html__( 'ناموفق', 'revayat-companion' ); ?></td><td><?php echo esc_html( $item['error'] ?? '' ); ?></td></tr>
				<?php endforeach; else : ?><tr><td colspan="6"><?php esc_html_e( 'هنوز ایمیلی ثبت نشده است.', 'revayat-companion' ); ?></td></tr><?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/** نمایش درخواست‌های عضویت در انتظار بررسی. */
	public function render_approval_page() {
		if ( ! current_user_can( 'revayat_manage_approvals' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز است.', 'revayat-companion' ), '', array( 'response' => 403 ) );
		}
		$applicants = get_users(
			array(
				'meta_key'   => '_revayat_approval_status',
				'meta_value' => 'pending',
				'orderby'    => 'registered',
				'order'      => 'ASC',
			)
		);
		$notice = isset( $_GET['reviewed'] ) ? sanitize_key( wp_unslash( $_GET['reviewed'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'درخواست‌های عضویت تحلیلگران و ارزیابان', 'revayat-companion' ); ?></h1>
			<?php if ( $notice ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'وضعیت درخواست به‌روزرسانی شد.', 'revayat-companion' ); ?></p></div><?php endif; ?>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'نام', 'revayat-companion' ); ?></th><th><?php esc_html_e( 'ایمیل', 'revayat-companion' ); ?></th><th><?php esc_html_e( 'نوع درخواست', 'revayat-companion' ); ?></th><th><?php esc_html_e( 'موبایل تأییدشده', 'revayat-companion' ); ?></th><th><?php esc_html_e( 'تاریخ درخواست', 'revayat-companion' ); ?></th><th><?php esc_html_e( 'عملیات', 'revayat-companion' ); ?></th></tr></thead>
				<tbody>
				<?php if ( $applicants ) : foreach ( $applicants as $applicant ) :
					$base_url = admin_url( 'admin-post.php' );
					$approve_url = wp_nonce_url( add_query_arg( array( 'action' => 'revayat_review_user', 'user_id' => $applicant->ID, 'decision' => 'approve' ), $base_url ), 'revayat_review_user_' . $applicant->ID );
					$reject_url  = wp_nonce_url( add_query_arg( array( 'action' => 'revayat_review_user', 'user_id' => $applicant->ID, 'decision' => 'reject' ), $base_url ), 'revayat_review_user_' . $applicant->ID );
				?>
				<?php $requested_role = sanitize_key( get_user_meta( $applicant->ID, '_revayat_requested_role', true ) ); ?>
				<tr><td><?php echo esc_html( $applicant->display_name ); ?></td><td><?php echo esc_html( $applicant->user_email ); ?></td><td><?php echo esc_html( 'voter' === $requested_role ? 'ارزیاب' : 'تحلیلگر' ); ?></td><td><?php echo esc_html( get_user_meta( $applicant->ID, '_revayat_mobile', true ) ); ?></td><td><?php echo esc_html( get_date_from_gmt( $applicant->user_registered ) ); ?></td><td><a class="button button-primary" href="<?php echo esc_url( $approve_url ); ?>"><?php esc_html_e( 'تأیید', 'revayat-companion' ); ?></a> <a class="button" href="<?php echo esc_url( $reject_url ); ?>"><?php esc_html_e( 'رد', 'revayat-companion' ); ?></a></td></tr>
				<?php endforeach; else : ?><tr><td colspan="6"><?php esc_html_e( 'درخواستی در انتظار بررسی نیست.', 'revayat-companion' ); ?></td></tr><?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/** تأیید یا رد درخواست با nonce و capability مدیریتی. */
	public static function review_membership( $user_id, $decision, $reviewer_id = 0 ) {
		$user           = get_user_by( 'id', absint( $user_id ) );
		$decision       = sanitize_key( $decision );
		$current_status = $user ? sanitize_key( get_user_meta( $user->ID, '_revayat_approval_status', true ) ) : '';
		$requested_role = $user ? sanitize_key( get_user_meta( $user->ID, '_revayat_requested_role', true ) ) : '';
		if ( ! $user || 'pending' !== $current_status || ! in_array( $requested_role, array( 'analyst', 'voter' ), true ) || ! in_array( $decision, array( 'approve', 'reject' ), true ) ) {
			return new WP_Error( 'invalid_membership_transition', 'درخواست عضویت یا گذار وضعیت معتبر نیست.' );
		}
		if ( 'approve' === $decision ) {
			if ( 'analyst' === $requested_role ) {
				$person_id = self::ensure_person_profile( $user->ID );
				if ( is_wp_error( $person_id ) ) {
					return $person_id;
				}
			}
			$user->set_role( $requested_role );
			update_user_meta( $user->ID, '_revayat_approval_status', 'approved' );
		} else {
			$user->set_role( 'subscriber' );
			update_user_meta( $user->ID, '_revayat_approval_status', 'rejected' );
		}
		$history   = get_user_meta( $user->ID, '_revayat_approval_history', true );
		$history   = is_array( $history ) ? $history : array();
		$history[] = array( 'decision' => $decision, 'role' => $requested_role, 'reviewer' => absint( $reviewer_id ), 'created_at' => current_time( 'mysql', true ) );
		update_user_meta( $user->ID, '_revayat_approval_history', array_slice( $history, -20 ) );
		do_action( 'revayat_access_status_changed', $user->ID, 'analyst' === $requested_role ? 'تحلیلگری' : 'عضویت', 'approve' === $decision ? 'approved' : 'rejected', wp_generate_uuid4() );
		return true;
	}

	/** تأیید یا رد درخواست با nonce و capability مدیریتی. */
	public function handle_review_user() {
		if ( ! current_user_can( 'revayat_manage_approvals' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز است.', 'revayat-companion' ), '', array( 'response' => 403 ) );
		}
		$user_id  = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		$decision = isset( $_GET['decision'] ) ? sanitize_key( wp_unslash( $_GET['decision'] ) ) : '';
		check_admin_referer( 'revayat_review_user_' . $user_id );
		$result = self::review_membership( $user_id, $decision, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 400 ) );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'revayat-analyst-approvals', 'reviewed' => $decision ), admin_url( 'users.php' ) ) );
		exit;
	}

	/** جلوگیری از ورود کاربران عادی به مدیریت. */
	public function restrict_admin() {
		if ( wp_doing_ajax() || wp_doing_cron() || 'admin-post.php' === ( $GLOBALS['pagenow'] ?? '' ) || current_user_can( 'edit_posts' ) ) {
			return;
		}
		wp_safe_redirect( home_url( '/dashboard/' ) );
		exit;
	}

	public function hide_admin_bar( $show ) {
		return current_user_can( 'edit_posts' ) ? $show : false;
	}

	public function login_redirect( $redirect_to, $requested, $user ) {
		if ( $user instanceof WP_User && ! user_can( $user, 'edit_posts' ) ) {
			return home_url( '/dashboard/' );
		}
		return $redirect_to;
	}

	private function redirect_auth( $notice, $tab = 'login', $extra = array() ) {
		$args = array_merge( array( 'tab' => sanitize_key( $tab ), 'notice' => sanitize_key( $notice ) ), array_map( 'sanitize_text_field', $extra ) );
		wp_safe_redirect( add_query_arg( $args, home_url( '/auth/' ) ) );
		exit;
	}

	private function redirect_dashboard( $notice ) {

        $view = in_array( $notice, array( 'password_updated', 'invalid_password' ), true ) ? 'security' : ( 'notifications_read' === $notice ? 'notifications' : 'overview' );
        wp_safe_redirect( add_query_arg( array( 'notice' => sanitize_key( $notice ), 'view' => $view ), home_url( '/dashboard/' ) ) );
		exit;
	}
}
