<?php
/**
 * مدیریت نقش‌های تحریریه (سردبیر، دبیر سرویس، خبرنگار) و ایزوله‌سازی سطح دسترسی بر اساس سرویس
 *
 * چرا این فایل لازم است؟
 * تفکیک کامل منطق نقش‌های تحریریه، محدودسازی دسته‌بندی/سرویس و فیلد پروفایل از کلاس پرتال کاربران
 * جهت نگهداری آسان و جلوگیری از پیچیدگی بیش از حد.
 *
 * مسئولیت:
 * ۱. ثبت و همگام‌سازی نقش‌های سردبیر (chief_editor)، دبیر سرویس (service_editor) و خبرنگار (reporter).
 * ۲. افزودن انتخابگر چندسرویسه در پروفایل کاربران (فقط قابل ویرایش توسط مدیر مسئول).
 * ۳. اعمال محدودیت دسترسی سرویس در لیست نوشته‌ها، ویرایشگر کلاسیک، گوتنبرگ (REST)، ذخیره و مدیریت برگه سرویس.
 *
 * @package RevayatCompanion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Revayat_Companion_Editorial_Access {
	const ROLES_VERSION = '1.0.0';
	const META_SERVICES = '_rv_assigned_services';

	private static $filtering_terms = false;

	/**
	 * ثبت هوک‌های کنترل دسترسی تحریریه و سرویس‌ها.
	 */
	public static function hooks() {
		add_action( 'init', array( __CLASS__, 'maybe_sync_roles' ), 2 );

		// پروفایل کاربر برای تخصیص سرویس‌های مجاز (فقط مدیر کل)
		add_action( 'show_user_profile', array( __CLASS__, 'render_user_services_field' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_user_services_field' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_user_services_field' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_user_services_field' ) );
		add_filter( 'editable_roles', array( __CLASS__, 'filter_editable_roles' ) );

		// ایزوله‌سازی محتوا، دسته‌بندی‌ها و قابلیت‌ها
		add_action( 'pre_get_posts', array( __CLASS__, 'scope_admin_posts_query' ), 20 );
		add_filter( 'map_meta_cap', array( __CLASS__, 'enforce_service_meta_caps' ), 20, 4 );
		add_filter( 'get_terms_args', array( __CLASS__, 'scope_category_terms_args' ), 20, 2 );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'enforce_post_data_on_save' ), 20, 2 );
		add_action( 'set_object_terms', array( __CLASS__, 'enforce_assigned_services_on_terms' ), 20, 6 );

		// کنترل در REST API (گوتنبرگ)
		add_filter( 'rest_post_query', array( __CLASS__, 'scope_rest_post_query' ), 20, 2 );
		add_filter( 'rest_category_query', array( __CLASS__, 'scope_rest_category_query' ), 20, 2 );

		// پاک‌سازی منوهای اضافی پیشخوان و اعلان نبود سرویس
		add_action( 'admin_menu', array( __CLASS__, 'prune_admin_menus' ), 999 );
		add_action( 'admin_notices', array( __CLASS__, 'render_missing_service_notice' ) );
	}

	/**
	 * همگام‌سازی خودکار نقش‌ها در صورت تغییر نسخه.
	 */
	public static function maybe_sync_roles() {
		if ( self::ROLES_VERSION !== get_option( 'revayat_editorial_roles_ver', '' ) ) {
			self::sync_roles();
			update_option( 'revayat_editorial_roles_ver', self::ROLES_VERSION, false );
		}
	}

	/**
	 * ثبت و به‌روزرسانی دقیق قابلیت‌های نقش‌های تحریریه.
	 */
	public static function sync_roles() {
		$chief_caps = array(
			'read'                         => true,
			'upload_files'                 => true,
			'edit_posts'                   => true,
			'edit_others_posts'            => true,
			'edit_published_posts'         => true,
			'edit_private_posts'           => true,
			'publish_posts'                => true,
			'delete_posts'                 => true,
			'delete_others_posts'          => true,
			'delete_published_posts'       => true,
			'delete_private_posts'         => true,
			'read_private_posts'           => true,
			'edit_pages'                   => true,
			'edit_others_pages'            => true,
			'edit_published_pages'         => true,
			'publish_pages'                => true,
			'delete_pages'                 => true,
			'manage_categories'            => true,
			'moderate_comments'            => true,
			'edit_theme_options'           => true,
			'revayat_manage_approvals'     => true,
			'revayat_submit_analyst_post'  => true,
			'revayat_rate_analyst_post'    => true,
			'revayat_manage_pins'          => true,
			'revayat_read_situation_room'  => true,
		);

		$service_editor_caps = array(
			'read'                         => true,
			'upload_files'                 => true,
			'edit_posts'                   => true,
			'edit_others_posts'            => true,
			'edit_published_posts'         => true,
			'publish_posts'                => true,
			'delete_posts'                 => true,
			'delete_published_posts'       => true,
			'manage_categories'            => true,
			'moderate_comments'            => true,
			'revayat_manage_approvals'     => true,
			'revayat_submit_analyst_post'  => true,
			'revayat_rate_analyst_post'    => true,
			'revayat_read_situation_room'  => true,
		);

		$reporter_caps = array(
			'read'                         => true,
			'upload_files'                 => true,
			'edit_posts'                   => true,
			'delete_posts'                 => true,
			'revayat_rate_analyst_post'    => true,
		);

		self::upsert_role( 'chief_editor', 'سردبیر', $chief_caps );
		self::upsert_role( 'service_editor', 'دبیر سرویس', $service_editor_caps );
		self::upsert_role( 'reporter', 'خبرنگار', $reporter_caps );
	}

	/**
	 * ایجاد یا بازنشانی قابلیت‌های یک نقش.
	 */
	private static function upsert_role( string $slug, string $label, array $caps ) {
		remove_role( $slug );
		add_role( $slug, $label, $caps );
	}

	/**
	 * بررسی مدیر کل (مدیر مسئول) بدون فراخوانی بازگشتی map_meta_cap.
	 */
	public static function is_admin_manager( int $user_id = 0 ): bool {
		$user_id = $user_id ?: get_current_user_id();
		$user    = $user_id > 0 ? get_userdata( $user_id ) : null;
		if ( ! $user ) {
			return false;
		}
		$roles = (array) $user->roles;
		return in_array( 'administrator', $roles, true ) || ! empty( $user->allcaps['manage_options'] );
	}

	/**
	 * بررسی نقش سردبیر.
	 */
	public static function is_chief_editor( int $user_id = 0 ): bool {
		$user_id = $user_id ?: get_current_user_id();
		$user    = $user_id > 0 ? get_userdata( $user_id ) : null;
		if ( ! $user || self::is_admin_manager( $user->ID ) ) {
			return false;
		}
		return in_array( 'chief_editor', (array) $user->roles, true );
	}

	/**
	 * بررسی نقش دبیر سرویس (در صورت نداشتن نقش بالاتر).
	 */
	public static function is_service_editor( int $user_id = 0 ): bool {
		$user_id = $user_id ?: get_current_user_id();
		$user    = $user_id > 0 ? get_userdata( $user_id ) : null;
		if ( ! $user || self::is_admin_manager( $user->ID ) ) {
			return false;
		}
		$roles = (array) $user->roles;
		return ! in_array( 'chief_editor', $roles, true ) && in_array( 'service_editor', $roles, true );
	}

	/**
	 * بررسی نقش خبرنگار (در صورت نداشتن نقش بالاتر).
	 */
	public static function is_reporter( int $user_id = 0 ): bool {
		$user_id = $user_id ?: get_current_user_id();
		$user    = $user_id > 0 ? get_userdata( $user_id ) : null;
		if ( ! $user || self::is_admin_manager( $user->ID ) ) {
			return false;
		}
		$roles = (array) $user->roles;
		return ! in_array( 'chief_editor', $roles, true )
			&& ! in_array( 'service_editor', $roles, true )
			&& in_array( 'reporter', $roles, true );
	}

	/**
	 * آیا کاربر محدود به سرویس(های) تعیین‌شده است؟
	 */
	public static function is_service_scoped( int $user_id = 0 ): bool {
		return self::is_service_editor( $user_id ) || self::is_reporter( $user_id );
	}

	/**
	 * دریافت لیست شناسه‌های سرویس‌های مجاز (category term IDs) برای کاربر.
	 *
	 * @return array<int, int>
	 */
	public static function user_service_ids( int $user_id = 0 ): array {
		$user_id = $user_id ?: get_current_user_id();
		if ( $user_id <= 0 ) {
			return array();
		}

		$raw = get_user_meta( $user_id, self::META_SERVICES, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );
		return array_values(
			array_filter(
				$ids,
				static function ( $term_id ) {
					return (bool) term_exists( $term_id, 'category' );
				}
			)
		);
	}

	/**
	 * بررسی مجوز دسترسی به صف تأیید شبکه تحلیلگران و اتاق وضعیت.
	 */
	public static function can_review_approvals( int $user_id = 0 ): bool {
		$user_id = $user_id ?: get_current_user_id();
		$user    = $user_id > 0 ? get_userdata( $user_id ) : null;
		if ( ! $user || ! Revayat_Companion_Member_Policy::active( $user->ID ) ) {
			return false;
		}
		if ( in_array( 'administrator', (array) $user->roles, true ) && user_can( $user, 'manage_options' ) && user_can( $user, 'revayat_manage_approvals' ) ) {
			return true;
		}
		if ( self::is_chief_editor( $user->ID ) && user_can( $user, 'revayat_manage_approvals' ) ) {
			return true;
		}
		if ( self::is_service_editor( $user->ID ) && user_can( $user, 'revayat_manage_approvals' ) ) {
			return ! empty( self::user_service_ids( $user->ID ) );
		}
		return false;
	}

	/**
	 * بررسی مجوز دسترسی دبیر سرویس/سردبیر/مدیر به یک درخواست عضویت مشخص.
	 */
	public static function can_access_application( int $user_id, $application_post ): bool {
		if ( ! self::can_review_approvals( $user_id ) ) {
			return false;
		}
		if ( self::is_admin_manager( $user_id ) || self::is_chief_editor( $user_id ) ) {
			return true;
		}
		$post = get_post( $application_post );
		if ( ! $post || 'rv_application' !== $post->post_type ) {
			return false;
		}
		$services       = self::user_service_ids( $user_id );
		$app_service_id = (int) get_post_meta( $post->ID, '_rv_service_id', true );
		// اگر درخواست به سرویس خاصی متصل شده باشد، فقط دبیر همان سرویس اجازه بررسی دارد.
		if ( $app_service_id > 0 ) {
			return in_array( $app_service_id, $services, true );
		}
		// درخواست‌های قدیمی بدون شناسه سرویس، برای دبیر سرویس دارای سرویس فعال نیز قابل بررسی است.
		return ! empty( $services );
	}

	/**
	 * دریافت تمام سرویس‌های دسته‌بندی (به‌جز دسته پیش‌فرض uncategorized در صورت وجود سایر دسته‌ها).
	 *
	 * @return array<int, WP_Term>
	 */
	public static function available_services(): array {
		self::$filtering_terms = true;
		$terms                 = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		self::$filtering_terms = false;

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$terms,
				static function ( $term ) {
					return 'uncategorized' !== $term->slug;
				}
			)
		);
	}

	/**
	 * نمایش فیلد انتخاب سرویس‌های مجاز در صفحه پروفایل کاربر (فقط مدیر مسئول).
	 */
	public static function render_user_services_field( $user ) {
		if ( ! current_user_can( 'manage_options' ) || ! ( $user instanceof WP_User ) ) {
			return;
		}
		$assigned = self::user_service_ids( $user->ID );
		$services = self::available_services();
		?>
		<h2>تنظیمات سرویس‌های تحریریه (دبیر سرویس و خبرنگار)</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label>سرویس‌های مجاز</label></th>
				<td>
					<p class="description" style="margin-bottom: 10px;">
						برای نقش‌های <strong>«دبیر سرویس»</strong> و <strong>«خبرنگار»</strong> می‌توانید یک یا چند سرویس را انتخاب کنید.
						کاربر فقط به سرویس‌های انتخاب‌شده در این بخش دسترسی خواهد داشت.
					</p>
					<?php wp_nonce_field( 'rv_save_user_services', 'rv_user_services_nonce' ); ?>
					<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 8px 16px; max-width: 720px; padding: 12px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
						<?php foreach ( $services as $term ) : ?>
							<label style="display: flex; align-items: center; gap: 6px; margin: 0;">
								<input type="checkbox" name="rv_assigned_services[]" value="<?php echo esc_attr( $term->term_id ); ?>" <?php checked( in_array( (int) $term->term_id, $assigned, true ) ); ?>>
								<span><?php echo esc_html( $term->name ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * ذخیره سرویس‌های مجاز کاربر توسط مدیر مسئول.
	 */
	public static function save_user_services_field( $user_id ) {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		if ( empty( $_POST['rv_user_services_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rv_user_services_nonce'] ) ), 'rv_save_user_services' ) ) {
			return;
		}
		$raw      = isset( $_POST['rv_assigned_services'] ) ? (array) wp_unslash( $_POST['rv_assigned_services'] ) : array();
		$clean    = array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );
		$verified = array_values(
			array_filter(
				$clean,
				static function ( $term_id ) {
					return (bool) term_exists( $term_id, 'category' );
				}
			)
		);
		update_user_meta( $user_id, self::META_SERVICES, $verified );
	}

	/**
	 * جلوگیری از ارتقای نقش توسط کاربران غیرادمین.
	 */
	public static function filter_editable_roles( $roles ) {
		if ( current_user_can( 'manage_options' ) ) {
			return $roles;
		}
		unset( $roles['administrator'], $roles['chief_editor'] );
		return $roles;
	}

	/**
	 * محدودسازی لیست نوشته‌ها در پیشخوان برای دبیر سرویس و خبرنگار.
	 */
	public static function scope_admin_posts_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( ! self::is_service_scoped( $user_id ) ) {
			return;
		}

		$post_type = $query->get( 'post_type' ) ?: 'post';
		if ( 'post' !== $post_type ) {
			return;
		}

		$services = self::user_service_ids( $user_id );
		if ( self::is_reporter( $user_id ) ) {
			$query->set( 'author', $user_id );
		}

		if ( empty( $services ) ) {
			$query->set( 'post__in', array( 0 ) );
			return;
		}

		$tax_query   = (array) $query->get( 'tax_query' );
		$tax_query[] = array(
			'taxonomy' => 'category',
			'field'    => 'term_id',
			'terms'    => $services,
		);
		$query->set( 'tax_query', $tax_query );
	}

	/**
	 * کنترل دسترسی‌های سطح نوشته و دسته‌بندی با map_meta_cap.
	 */
	public static function enforce_service_meta_caps( $caps, $cap, $user_id, $args ) {
		$user_id = (int) $user_id;
		if ( ! self::is_service_scoped( $user_id ) ) {
			return $caps;
		}

		$services = self::user_service_ids( $user_id );

		// کنترل سطح نوشته
		if ( in_array( $cap, array( 'edit_post', 'delete_post', 'publish_post', 'read_post' ), true ) ) {
			$post_id = isset( $args[0] ) ? (int) $args[0] : 0;
			$post    = $post_id ? get_post( $post_id ) : null;
			if ( ! $post ) {
				return $caps;
			}

			// اجازه مدیریت فایل‌های رسانه‌ای آپلودشده توسط خود کاربر
			if ( 'attachment' === $post->post_type ) {
				if ( self::is_reporter( $user_id ) && (int) $post->post_author !== $user_id ) {
					return array( 'do_not_allow' );
				}
				return $caps;
			}

			// محدودسازی نوشته‌های دبیر سرویس و خبرنگار به پست‌تایپ post
			if ( 'post' !== $post->post_type ) {
				if ( 'analyst_post' === $post->post_type && self::is_service_editor( $user_id ) ) {
					return $caps;
				}
				return array( 'do_not_allow' );
			}

			if ( empty( $services ) ) {
				return array( 'do_not_allow' );
			}

			if ( self::is_reporter( $user_id ) ) {
				if ( 'publish_post' === $cap ) {
					return array( 'do_not_allow' );
				}
				if ( (int) $post->post_author !== $user_id ) {
					return array( 'do_not_allow' );
				}
				if ( 'publish' === $post->post_status ) {
					return array( 'do_not_allow' );
				}
			}

			if ( 'auto-draft' !== $post->post_status ) {
				$post_cats = wp_get_post_categories( $post->ID );
				if ( ! empty( $post_cats ) && empty( array_intersect( $post_cats, $services ) ) ) {
					return array( 'do_not_allow' );
				}
			}
		}

		// کنترل سطح دسته‌بندی (مدیریت صفحه سرویس مربوطه برای دبیر سرویس و جلوگیری از دستکاری سایر سرویس‌ها)
		if ( in_array( $cap, array( 'edit_term', 'delete_term', 'assign_term' ), true ) ) {
			$term_id = isset( $args[0] ) ? (int) $args[0] : 0;
			$term    = $term_id ? get_term( $term_id ) : null;
			if ( $term && 'category' === $term->taxonomy ) {
				if ( 'delete_term' === $cap || self::is_reporter( $user_id ) && 'edit_term' === $cap ) {
					return array( 'do_not_allow' );
				}
				if ( empty( $services ) || ! in_array( $term_id, $services, true ) ) {
					return array( 'do_not_allow' );
				}
			}
		}

		return $caps;
	}

	/**
	 * فیلتر کردن لیست دسته‌بندی‌ها در پیشخوان و ویرایشگر تا کاربر فقط سرویس‌های مجازش را ببیند.
	 */
	public static function scope_category_terms_args( $args, $taxonomies ) {
		if ( self::$filtering_terms ) {
			return $args;
		}
		if ( ! is_admin() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $args;
		}
		if ( ! in_array( 'category', (array) $taxonomies, true ) ) {
			return $args;
		}
		$user_id = get_current_user_id();
		if ( ! self::is_service_scoped( $user_id ) ) {
			return $args;
		}

		$services        = self::user_service_ids( $user_id );
		$args['include'] = ! empty( $services ) ? $services : array( 0 );
		return $args;
	}

	/**
	 * جلوگیری از انتشار مستقیم توسط خبرنگار و الزام وجود سرویس هنگام ذخیره نوشته.
	 */
	public static function enforce_post_data_on_save( $data, $postarr ) {
		if ( 'post' !== ( $data['post_type'] ?? '' ) || 'auto-draft' === ( $data['post_status'] ?? '' ) ) {
			return $data;
		}
		$user_id = get_current_user_id();
		if ( ! self::is_service_scoped( $user_id ) ) {
			return $data;
		}

		if ( empty( self::user_service_ids( $user_id ) ) ) {
			$data['post_status'] = 'draft';
			return $data;
		}

		if ( self::is_reporter( $user_id ) && in_array( $data['post_status'], array( 'publish', 'future' ), true ) ) {
			$data['post_status'] = 'pending';
		}

		return $data;
	}

	/**
	 * پاک‌سازی خودکار دسته‌بندی‌های غیرمجاز در هنگام ذخیره یا REST (گوتنبرگ).
	 */
	public static function enforce_assigned_services_on_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
		if ( 'category' !== $taxonomy || 'post' !== get_post_type( $object_id ) ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( ! self::is_service_scoped( $user_id ) ) {
			return;
		}

		$services = self::user_service_ids( $user_id );
		if ( empty( $services ) ) {
			return;
		}

		$current_cats = wp_get_post_categories( $object_id );
		$allowed_cats = array_values( array_intersect( array_map( 'intval', $current_cats ), $services ) );

		if ( count( $allowed_cats ) !== count( $current_cats ) || empty( $allowed_cats ) ) {
			if ( empty( $allowed_cats ) ) {
				$allowed_cats = array( (int) $services[0] );
			}
			remove_action( 'set_object_terms', array( __CLASS__, 'enforce_assigned_services_on_terms' ), 20 );
			wp_set_post_categories( $object_id, $allowed_cats, false );
			add_action( 'set_object_terms', array( __CLASS__, 'enforce_assigned_services_on_terms' ), 20, 6 );
		}
	}

	/**
	 * محدودسازی کوئری نوشته‌ها در گوتنبرگ (REST API).
	 */
	public static function scope_rest_post_query( $args, $request ) {
		$user_id = get_current_user_id();
		if ( ! self::is_service_scoped( $user_id ) ) {
			return $args;
		}
		$services = self::user_service_ids( $user_id );
		if ( self::is_reporter( $user_id ) ) {
			$args['author'] = $user_id;
		}
		if ( empty( $services ) ) {
			$args['post__in'] = array( 0 );
			return $args;
		}
		$args['category__in'] = $services;
		return $args;
	}

	/**
	 * محدودسازی لیست دسته‌بندی‌ها در REST API برای گوتنبرگ.
	 */
	public static function scope_rest_category_query( $args, $request ) {
		$user_id = get_current_user_id();
		if ( ! self::is_service_scoped( $user_id ) ) {
			return $args;
		}
		$services        = self::user_service_ids( $user_id );
		$args['include'] = ! empty( $services ) ? $services : array( 0 );
		return $args;
	}

	/**
	 * پنهان‌سازی منوهای غیرمرتبط در پیشخوان برای هر نقش.
	 */
	public static function prune_admin_menus() {
		$user_id = get_current_user_id();
		if ( self::is_admin_manager( $user_id ) ) {
			return;
		}

		if ( self::is_chief_editor( $user_id ) ) {
			remove_submenu_page( 'themes.php', 'themes.php' );
			remove_submenu_page( 'themes.php', 'widgets.php' );
			remove_submenu_page( 'themes.php', 'theme-editor.php' );
			remove_menu_page( 'tools.php' );
			return;
		}

		if ( self::is_service_scoped( $user_id ) ) {
			remove_menu_page( 'edit.php?post_type=page' );
			remove_menu_page( 'edit.php?post_type=situation_room' );
			remove_menu_page( 'edit.php?post_type=special_dossier' );
			remove_menu_page( 'edit.php?post_type=media_observatory' );
			remove_menu_page( 'edit.php?post_type=multimedia' );
			remove_menu_page( 'edit.php?post_type=person' );
			remove_menu_page( 'tools.php' );
			if ( self::is_reporter( $user_id ) ) {
				remove_menu_page( 'edit-comments.php' );
				remove_menu_page( 'edit.php?post_type=analyst_post' );
			}
		}
	}

	/**
	 * نمایش هشدار در پیشخوان اگر برای دبیر سرویس یا خبرنگار هنوز سرویسی تعیین نشده باشد.
	 */
	public static function render_missing_service_notice() {
		$user_id = get_current_user_id();
		if ( ! self::is_service_scoped( $user_id ) || ! empty( self::user_service_ids( $user_id ) ) ) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<p><strong>تنظیم نشده:</strong> هنوز هیچ سرویسی از طرف مدیر مسئول برای حساب شما تعیین نشده است؛ تا زمان اختصاص سرویس، امکان مشاهده یا ثبت مطلب نخواهید داشت.</p>
		</div>
		<?php
	}
}
