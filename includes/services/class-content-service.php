<?php
/**
 * سرویس واکشی و نرمال‌سازی محتوا (Content Normalization Service)
 *
 * چرا این فایل لازم است؟
 * جهت متمرکزسازی کوئری‌های دیتابیس وردپرس و تبدیل اشیاء خام WP_Post به قرارداد داده‌ای هیبریدی استاندارد (Hybrid Contract).
 *
 * چه مسئولیتی دارد؟
 * - اجرای کوئری‌های امن WP_Query بر روی انواع پست‌تایپ‌ها با آرگومان‌های بهینه‌سازی‌شده.
 * - نرمال‌سازی فیلدهای متنی، رسانه‌ای، تاکسونومی‌ها و متادیتاها مطابق با فرمت تعیین‌شده در docs/architecture/provider-contract.md.
 * - پیاده‌سازی متدهای کمکی استخراج مدیا، تاکسونومی و فراداده‌ها بدون ایجاد N+1 Query.
 *
 * ارتباط با معماری:
 * هسته لایه سرویس داده (Data Service Layer) افزونه revayat-companion که مرز میان دیتابیس و مصرف‌کنندگان داده را شکل می‌دهد.
 *
 * @package Revayat_Companion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Revayat_Companion_Content_Service' ) ) {

	/**
	 * کلاس سرویس استخراج و تبدیل محتوا
	 */
	class Revayat_Companion_Content_Service {

		/**
		 * واکشی امن پست‌ها بر اساس پست‌تایپ و آرگومان‌های سفارشی
		 *
		 * @param string $post_type نوع پست‌تایپ.
		 * @param array  $args      آرگومان‌های اضافی WP_Query.
		 * @return array آرایه‌ای از پست‌های نرمال‌شده مطابق Hybrid Contract.
		 */
		public static function get_posts_by_type( $post_type, $args = array() ) {
			if ( 'situation_room' === $post_type && ! self::can_access_situation_room() ) {
				return array();
			}
			$defaults = array(
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'posts_per_page'         => 10,
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => true,
			);

			$query_args = wp_parse_args( $args, $defaults );
			$query      = new WP_Query( $query_args );

			if ( empty( $query->posts ) ) {
				return array();
			}
			self::prime_person_term_meta( $query->posts );

			$normalized = array();
			foreach ( $query->posts as $post ) {
				$item = self::normalize_post( $post );
				if ( ! empty( $item ) ) {
					$normalized[] = $item;
				}
			}

			return $normalized;
		}

		/** پیش‌بارگذاری term meta رابطه اشخاص برای جلوگیری از کوئری کارت‌به‌کارت. */
		private static function prime_person_term_meta( $posts ) {
			if ( ! taxonomy_exists( 'person_author' ) || ! $posts ) {
				return;
			}
			$ids      = array_map( 'intval', wp_list_pluck( $posts, 'ID' ) );
			$term_ids = wp_get_object_terms( $ids, 'person_author', array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $term_ids ) && $term_ids ) {
				update_meta_cache( 'term', array_map( 'intval', $term_ids ) );
			}
		}

		/**
		 * تشخیص سطح دسترسی امنیتی کاربر جاری یا کاربر مشخص‌شده
		 *
		 * @param int|WP_User|null $user شناسه یا شیء کاربر (پیش‌فرض: کاربر جاری).
		 * @return string 'guest' | 'normal' | 'high'
		 */
		public static function get_user_clearance( $user = null ) {
			if ( null === $user ) {
				if ( ! is_user_logged_in() ) {
					return 'guest';
				}
				$user_obj = wp_get_current_user();
			} elseif ( is_numeric( $user ) ) {
				$user_obj = get_user_by( 'id', (int) $user );
			} elseif ( $user instanceof WP_User ) {
				$user_obj = $user;
			} else {
				return 'guest';
			}

			if ( ! $user_obj || ! $user_obj->exists() ) {
				return 'guest';
			}

			// بررسی دسترسی سطح عالی (High): مدیران و دارندگان مجوز دسترسی بولتن‌های پدافندی
			if (
				user_can( $user_obj, 'administrator' ) ||
				user_can( $user_obj, 'manage_options' ) ||
				user_can( $user_obj, 'revayat_read_situation_room' ) ||
				in_array( 'vip_subscriber', (array) $user_obj->roles, true ) ||
				in_array( 'administrator', (array) $user_obj->roles, true )
			) {
				return 'high';
			}

			// کاربر عضو با دسترسی پایه / استاندارد
			return 'normal';
		}

		/** معیار کانونیکال دسترسی به کل بخش اتاق وضعیت. */
		public static function can_access_situation_room( $user = null ) {
			if ( null === $user ) {
				$user = wp_get_current_user();
			} elseif ( is_numeric( $user ) ) {
				$user = get_user_by( 'id', (int) $user );
			}
			return $user instanceof WP_User && $user->exists() && (
				user_can( $user, 'revayat_read_situation_room' ) ||
				user_can( $user, 'manage_options' ) ||
				user_can( $user, 'edit_others_posts' )
			);
		}

		/** گیت مسیر پیش از انتخاب و رندر تمپلیت. */
		public static function enforce_situation_room_route() {
			$is_protected_route = is_post_type_archive( 'situation_room' ) || is_singular( 'situation_room' ) || is_tax( 'security_level' );
			if ( ! $is_protected_route || self::can_access_situation_room() ) {
				return;
			}
			nocache_headers();
			if ( ! is_user_logged_in() ) {
				$request_path = isset( $GLOBALS['wp']->request ) ? '/' . trim( (string) $GLOBALS['wp']->request, '/' ) . '/' : '/situation-room/';
				$target       = wp_validate_redirect( home_url( $request_path ), home_url( '/situation-room/' ) );
				$login_url    = add_query_arg(
					array(
						'tab'         => 'login',
						'notice'      => 'restricted_area',
						'redirect_to' => rawurlencode( $target ),
					),
					home_url( '/auth/' )
				);
				wp_safe_redirect( $login_url, 302 );
				exit;
			}
			wp_die( esc_html__( 'حساب شما مجوز ویژه دسترسی به اتاق وضعیت را ندارد.', 'revayat-companion' ), esc_html__( 'دسترسی غیرمجاز', 'revayat-companion' ), array( 'response' => 403, 'back_link' => true ) );
		}

        /** Match the rendered media archive so WordPress calculates valid pages/404s. */
        public static function prepare_multimedia_archive_query( $query ): void {
            if ( is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() || ! $query->is_post_type_archive( 'multimedia' ) || $query->is_feed() ) { return; }
            $query->set( 'posts_per_page', 12 );
            $format = isset( $_GET['format'] ) && is_string( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : 'all';
            if ( 'all' !== $format ) {
                $tax = (array) $query->get( 'tax_query' );
                $tax[] = array( 'taxonomy' => 'media_format', 'field' => 'slug', 'terms' => $format );
                $query->set( 'tax_query', $tax );
            }
        }

		/** حذف داده محافظت‌شده از جستجو، feed و هر کوئری عمومی فاقد مجوز. */
		public static function filter_protected_queries( $query ) {
			$is_backoffice = is_admin() && ! wp_doing_ajax() && ! ( defined( 'WP_CLI' ) && WP_CLI );
			if ( $is_backoffice || ! $query instanceof WP_Query || self::can_access_situation_room() ) {
				return;
			}
			$post_type = $query->get( 'post_type' );
			if ( 'situation_room' === $post_type || ( is_array( $post_type ) && in_array( 'situation_room', $post_type, true ) ) ) {
				$query->set( 'post__in', array( 0 ) );
				return;
			}
			if ( $query->is_search() || $query->is_feed() ) {
				$post_types = ( ! $post_type || 'any' === $post_type ) ? get_post_types( array( 'public' => true ), 'names' ) : (array) $post_type;
				$query->set( 'post_type', array_values( array_diff( $post_types, array( 'situation_room', 'attachment' ) ) ) );
			}
		}

		/** بستن endpointهای REST اتاق وضعیت برای کاربران فاقد مجوز. */
		public static function protect_situation_room_rest( $result, $server, $request ) {
			$route = $request instanceof WP_REST_Request ? $request->get_route() : '';
			if ( 0 !== strpos( $route, '/wp/v2/situation_room' ) || self::can_access_situation_room() ) {
				return $result;
			}
			$status = is_user_logged_in() ? 403 : 401;
			return new WP_Error( 'revayat_situation_room_forbidden', 'دسترسی به داده‌های اتاق وضعیت نیازمند مجوز ویژه است.', array( 'status' => $status ) );
		}

		/** حذف اتاق وضعیت از sitemap عمومی برای جلوگیری از افشای URLها. */
		public static function filter_protected_sitemap_post_types( $post_types ) {
			if ( ! self::can_access_situation_room() && is_array( $post_types ) ) {
				unset( $post_types['situation_room'] );
			}
			return $post_types;
		}

		/**
		 * ارزیابی وضعیت دسترسی کاربر به یک بولتن وضعیت خاص
		 *
		 * @param int|WP_Post      $post شیء یا شناسه بولتن.
		 * @param int|WP_User|null $user شناسه یا شیء کاربر.
		 * @return array
		 */
		public static function evaluate_bulletin_access( $post, $user = null ) {
			$post_obj = get_post( $post );
			if ( ! $post_obj instanceof WP_Post ) {
				return array(
					'can_view_content'         => false,
					'can_download_attachments' => false,
					'can_view_gallery'         => false,
					'user_clearance'           => 'guest',
					'required_level'           => 'public',
					'security_level'           => 'public',
					'is_locked'                => true,
					'lock_reason'              => 'بولتن تحلیلی نامعتبر است.',
					'auth_url'                 => home_url( '/auth/?tab=login' ),
					'login_url'                => home_url( '/auth/?tab=login' ),
					'register_url'             => home_url( '/auth/?tab=register' ),
					'upgrade_url'              => home_url( '/auth/?tab=upgrade' ),
				);
			}

			$id             = (int) $post_obj->ID;
			$user_clearance = self::get_user_clearance( $user );
			if ( ! self::can_access_situation_room( $user ) ) {
				$is_guest = 'guest' === $user_clearance;
				return array(
					'can_view_content'         => false,
					'can_download_attachments' => false,
					'can_view_gallery'         => false,
					'user_clearance'           => $user_clearance,
					'required_level'           => 'high',
					'security_level'           => 'classified',
					'is_locked'                => true,
					'lock_reason'              => $is_guest ? 'ورود و تأیید دسترسی ویژه برای مشاهده اتاق وضعیت الزامی است.' : 'حساب شما هنوز مجوز ویژه اتاق وضعیت را ندارد.',
					'auth_url'                 => home_url( $is_guest ? '/auth/?tab=login' : '/dashboard/?notice=access_denied' ),
					'login_url'                => home_url( '/auth/?tab=login' ),
					'register_url'             => home_url( '/auth/?tab=register' ),
					'upgrade_url'              => home_url( '/dashboard/?notice=access_denied' ),
				);
			}

			// واکشی سطح امنیتی از تاکسونومی security_level
			$sec_terms      = wp_get_object_terms( $id, 'security_level', array( 'fields' => 'slugs' ) );
			$security_level = ( ! empty( $sec_terms ) && ! is_wp_error( $sec_terms ) ) ? $sec_terms[0] : 'public';

			// نگاشت اسلاگ‌های هم‌ارز به مقادیر کانونیکال
			if ( in_array( $security_level, array( 'classified', 'high', 'secret' ), true ) ) {
				$canonical_level = 'classified';
				$required_level  = 'high';
			} elseif ( in_array( $security_level, array( 'restricted', 'normal', 'member' ), true ) ) {
				$canonical_level = 'restricted';
				$required_level  = 'normal';
			} else {
				$canonical_level = 'public';
				$required_level  = 'public';
			}

			$is_explicitly_locked = (bool) get_post_meta( $id, '_revayat_sr_is_locked', true );
			$custom_auth_url      = (string) get_post_meta( $id, '_revayat_sr_auth_url', true );
			$custom_notice        = (string) get_post_meta( $id, '_revayat_sr_redacted_notice', true );

			$can_view_content         = false;
			$can_download_attachments = false;
			$can_view_gallery         = false;
			$lock_reason              = '';
			$auth_url                 = $custom_auth_url;

			if ( 'high' === $required_level ) {
				if ( 'high' === $user_clearance ) {
					$can_view_content         = true;
					$can_download_attachments = true;
					$can_view_gallery         = true;
				} else {
					$lock_reason = $custom_notice ?: ( 'normal' === $user_clearance
						? 'دسترسی به اسناد پدافندی و راهبردی تنها با احراز هویت دو مرحله‌ای و ارتقای سطح ۱ (عالی) امکان‌پذیر است.'
						: 'مشاهده اسناد طبقه‌بندی‌شده نیازمند ورود به حساب کاربری و تایید صلاحیت امنیتی است.' );
					$auth_url    = $auth_url ?: home_url( 'normal' === $user_clearance ? '/auth/?tab=upgrade' : '/auth/?tab=login' );
				}
			} elseif ( 'normal' === $required_level ) {
				if ( in_array( $user_clearance, array( 'normal', 'high' ), true ) ) {
					$can_view_content         = true;
					$can_download_attachments = true;
					$can_view_gallery         = true;
				} else {
					$lock_reason = $custom_notice ?: 'برای مشاهده محتوای کامل این بولتن تحلیلی، عضویت و ورود به حساب کاربری الزامی است.';
					$auth_url    = $auth_url ?: home_url( '/auth/?tab=login' );
				}
			} else {
				// بولتن‌های سطح علنی (Public)
				if ( $is_explicitly_locked && 'guest' === $user_clearance ) {
					$can_view_content         = false;
					$can_download_attachments = false;
					$can_view_gallery         = false;
					$lock_reason              = $custom_notice ?: 'این گزارش موقتاً محدود شده است. لطفاً وارد حساب خود شوید.';
					$auth_url                 = $auth_url ?: home_url( '/auth/?tab=login' );
				} else {
					$can_view_content         = true;
					$can_download_attachments = true;
					$can_view_gallery         = true;
				}
			}

			return array(
				'can_view_content'         => $can_view_content,
				'can_download_attachments' => $can_download_attachments,
				'can_view_gallery'         => $can_view_gallery,
				'user_clearance'           => $user_clearance,
				'required_level'           => $required_level,
				'security_level'           => $canonical_level,
				'is_locked'                => ! $can_view_content,
				'lock_reason'              => $lock_reason,
				'auth_url'                 => $auth_url,
				'login_url'                => home_url( '/auth/?tab=login' ),
				'register_url'             => home_url( '/auth/?tab=register' ),
				'upgrade_url'              => home_url( '/auth/?tab=upgrade' ),
			);
		}

		/**
		 * سانسور محتوای بولتن در صورت عدم احراز صلاحیت دسترسی
		 *
		 * @param string      $content متن اصلی خام یا فیلترشده.
		 * @param array       $access  آرایه وضعیت دسترسی.
		 * @param WP_Post|int $post    شیء یا شناسه پست.
		 * @return string متن ایمن، سانسور شده و دارای پرده احراز هویت.
		 */
		public static function redact_bulletin_content( string $content, array $access, $post ) {
			if ( ! empty( $access['can_view_content'] ) ) {
				return $content;
			}

			$post_obj = get_post( $post );
			$excerpt  = '';
			if ( $post_obj instanceof WP_Post ) {
				$excerpt = wp_strip_all_tags( get_the_excerpt( $post_obj ) );
				if ( empty( $excerpt ) ) {
					$excerpt = wp_trim_words( wp_strip_all_tags( $post_obj->post_content ), 35, '...' );
				}
			}

			$lock_reason = ! empty( $access['lock_reason'] ) ? $access['lock_reason'] : 'دسترسی به این بخش محدود شده است.';
			$auth_url    = ! empty( $access['auth_url'] ) ? $access['auth_url'] : home_url( '/auth/?tab=login' );
			$action_text = ( isset( $access['user_clearance'] ) && 'normal' === $access['user_clearance'] ) ? 'درخواست ارتقای سطح دسترسی' : 'ورود به حساب / ثبت‌نام';

			$html  = '<div class="sr-content-redacted-wrapper" data-reveal="up">';
			if ( ! empty( $excerpt ) ) {
				$html .= '<p class="sr-redacted-excerpt sr-gated-item__excerpt--blurred">' . esc_html( $excerpt ) . '</p>';
			}
			$html .= '<div class="tactical-lock-block">';
			$html .= '<p class="lock-msg"><i class="ph-fill ph-lock-key"></i> ' . esc_html( $lock_reason ) . '</p>';
			$html .= '<a href="' . esc_url( $auth_url ) . '" class="upgrade-action-btn">';
			$html .= '<i class="ph ph-shield-check"></i>';
			$html .= '<span>' . esc_html( $action_text ) . '</span>';
			$html .= '</a>';
			$html .= '</div>';
			$html .= '</div>';

			return $html;
		}

		/**
		 * فیلتراسیون امن پیوست‌ها و حذف لینک‌های دانلود برای کاربران غیرمجاز
		 *
		 * @param array $raw_attachments لیست خام پیوست‌ها از متادیتا.
		 * @param array $access          وضعیت دسترسی.
		 * @return array
		 */
		public static function filter_bulletin_attachments( array $raw_attachments, array $access ) {
			if ( empty( $raw_attachments ) || ! is_array( $raw_attachments ) ) {
				return array();
			}

			$user_clearance = $access['user_clearance'] ?? 'guest';
			$can_download   = ! empty( $access['can_download_attachments'] );

			$filtered = array();
			foreach ( $raw_attachments as $att ) {
				if ( ! is_array( $att ) ) {
					continue;
				}

				$min_level    = $att['min_security_level'] ?? 'public';
				$is_view_only = ! empty( $att['is_view_only'] );

				// ارزیابی صلاحیت نسبت به حداقل سطح فایل
				$has_item_clearance = true;
				if ( 'high' === $min_level && 'high' !== $user_clearance ) {
					$has_item_clearance = false;
				} elseif ( 'normal' === $min_level && 'guest' === $user_clearance ) {
					$has_item_clearance = false;
				}

				$is_item_accessible = $can_download && $has_item_clearance;

				$item_url      = $is_item_accessible ? (string) ( $att['url'] ?? '' ) : '';
				$security_note = '';
				if ( ! $is_item_accessible ) {
					$security_note = ( 'guest' === $user_clearance ) ? 'نیازمند ورود به حساب کاربری' : 'نیازمند سطح دسترسی ۱ (عالی)';
				} elseif ( $is_view_only ) {
					$security_note = 'فقط مشاهده برخط (واترمارک شده)';
				}

				$filtered[] = array(
					'id'              => (int) ( $att['id'] ?? 0 ),
					'title'           => (string) ( $att['title'] ?? ( $att['name'] ?? '' ) ),
					'file_name'       => (string) ( $att['file_name'] ?? ( $att['title'] ?? '' ) ),
					'file_size'       => (string) ( $att['file_size'] ?? '' ),
					'file_type'       => (string) ( $att['file_type'] ?? 'pdf' ),
					'url'             => $item_url,
					'is_view_only'    => $is_view_only,
					'is_locked'       => ! $is_item_accessible,
					'is_downloadable' => $is_item_accessible && ! $is_view_only,
					'security_note'   => $security_note,
				);
			}

			return $filtered;
		}

		/**
		 * فیلتراسیون گالری سنسورها جهت جلوگیری از نشت تصاویر برای کاربران غیرمجاز
		 *
		 * @param array $raw_gallery لیست خام تصاویر سنسور.
		 * @param array $access      وضعیت دسترسی.
		 * @return array
		 */
		public static function filter_sensor_gallery( array $raw_gallery, array $access ) {
			if ( empty( $raw_gallery ) || ! is_array( $raw_gallery ) ) {
				return array(
					'total_count' => 0,
					'is_locked'   => false,
					'items'       => array(),
				);
			}

			$user_clearance = $access['user_clearance'] ?? 'guest';
			$can_view       = ! empty( $access['can_view_gallery'] );
			$total_count    = count( $raw_gallery );

			$items = array();
			foreach ( $raw_gallery as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$min_level          = $item['min_security_level'] ?? 'normal';
				$has_item_clearance = true;
				if ( 'high' === $min_level && 'high' !== $user_clearance ) {
					$has_item_clearance = false;
				} elseif ( 'normal' === $min_level && 'guest' === $user_clearance ) {
					$has_item_clearance = false;
				}

				$is_item_accessible = $can_view && $has_item_clearance;

				$items[] = array(
					'id'           => (int) ( $item['id'] ?? 0 ),
					'sensor_label' => (string) ( $item['sensor_label'] ?? 'SENSOR' ),
					'url'          => $is_item_accessible ? (string) ( $item['url'] ?? '' ) : '',
					'thumb_url'    => $is_item_accessible ? (string) ( $item['thumb_url'] ?? ( $item['url'] ?? '' ) ) : '',
					'caption'      => $is_item_accessible ? (string) ( $item['caption'] ?? '' ) : 'تصویر طبقه‌بندی شده',
					'timestamp'    => (string) ( $item['timestamp'] ?? '' ),
					'coordinates'  => (string) ( $item['coordinates'] ?? '' ),
					'is_locked'    => ! $is_item_accessible,
				);
			}

			return array(
				'total_count' => $total_count,
				'is_locked'   => ! $can_view,
				'items'       => $items,
			);
		}

		/**
		 * فیلتر هسته وردپرس روی the_content جهت حفاظت قطعی از بولتن‌های اتاق وضعیت
		 *
		 * @param string $content متن خام یا فیلترشده پست.
		 * @return string
		 */
		public static function protect_the_content( $content ) {
			if ( is_singular( 'situation_room' ) || ( 'situation_room' === get_post_type() && is_main_query() ) ) {
				$post_id = get_the_ID();
				if ( $post_id ) {
					$access = self::evaluate_bulletin_access( $post_id );
					if ( empty( $access['can_view_content'] ) ) {
						return self::redact_bulletin_content( $content, $access, $post_id );
					}
				}
			}
			return $content;
		}

		/**
		 * واکشی گزینه‌های فیلتر گونه‌های واکاوی دیده‌بان رسانه (Media Observatory Badge Options)
		 *
		 * @return array لیست گزینه‌های فیلتر شامل کلید، عنوان، کلاس و تعداد پست‌ها.
		 */
		public static function get_observatory_badge_options(): array {
			$archive_url = get_post_type_archive_link( 'media_observatory' );
			if ( ! $archive_url || is_wp_error( $archive_url ) ) {
				$archive_url = home_url( '/observatory/' );
			}

			// نگاشت مقادیر استاندارد و کانونیکال بر اساس معماری پایگاه
			$canonical_map = array(
				'discourse' => array(
					'slug'      => 'discourse',
					'label'     => 'تحلیل گفتمان',
					'css_class' => 'obs-badge--discourse',
					'count'     => 0,
					'url'       => add_query_arg( 'badge', 'discourse', $archive_url ),
				),
				'framing'   => array(
					'slug'      => 'framing',
					'label'     => 'مقایسه فریمینگ',
					'css_class' => 'obs-badge--framing',
					'count'     => 0,
					'url'       => add_query_arg( 'badge', 'framing', $archive_url ),
				),
				'campaign'  => array(
					'slug'      => 'campaign',
					'label'     => 'کمپین‌شناسی',
					'css_class' => 'obs-badge--campaign',
					'count'     => 0,
					'url'       => add_query_arg( 'badge', 'campaign', $archive_url ),
				),
			);

			// واکشی ترم‌های ثبت‌شده از دیتابیس
			$terms = get_terms(
				array(
					'taxonomy'   => 'observatory_badge',
					'hide_empty' => false,
				)
			);

			$badges = $canonical_map;

			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$slug      = $term->slug;
					$css_class = 'obs-badge--' . sanitize_html_class( $slug );
					$badges[ $slug ] = array(
						'slug'      => $slug,
						'label'     => $term->name,
						'css_class' => $css_class,
						'count'     => (int) $term->count,
						'url'       => add_query_arg( 'badge', $slug, $archive_url ),
					);
				}
			}

			// محاسبه تعداد کل پست‌های منتشرشده دیده‌بان
			$post_counts = wp_count_posts( 'media_observatory' );
			$total_count = isset( $post_counts->publish ) ? (int) $post_counts->publish : 0;

			$options = array(
				array(
					'slug'      => 'all',
					'label'     => 'همه واکاوی‌ها',
					'css_class' => 'obs-badge--all',
					'count'     => $total_count,
					'url'       => $archive_url,
				),
			);

			foreach ( $badges as $b ) {
				$options[] = $b;
			}

			return $options;
		}

		/**
		 * واکشی گزینه‌های فیلتر رشته‌های تخصصی شبکه تحلیلگران (Analyst Field Options)
		 *
		 * @return array لیست گزینه‌های فیلتر شامل کلید، عنوان، تعداد و لینک.
		 */
		public static function get_analyst_field_options(): array {
			$archive_url = get_post_type_archive_link( 'analyst_post' );
			if ( ! $archive_url || is_wp_error( $archive_url ) ) {
				$archive_url = home_url( '/analysts/' );
			}

			// نگاشت گزینه‌های کانونیکال معماری
			$canonical_map = array(
				'geopolitics'       => array(
					'slug'  => 'geopolitics',
					'label' => 'ژئوپلیتیک',
					'count' => 0,
					'url'   => add_query_arg( 'field', 'geopolitics', $archive_url ),
				),
				'political-economy' => array(
					'slug'  => 'political-economy',
					'label' => 'اقتصاد سیاسی',
					'count' => 0,
					'url'   => add_query_arg( 'field', 'political-economy', $archive_url ),
				),
				'security'          => array(
					'slug'  => 'security',
					'label' => 'امنیت ملی',
					'count' => 0,
					'url'   => add_query_arg( 'field', 'security', $archive_url ),
				),
				'media'             => array(
					'slug'  => 'media',
					'label' => 'رسانه و روایت',
					'count' => 0,
					'url'   => add_query_arg( 'field', 'media', $archive_url ),
				),
			);

			$terms = get_terms(
				array(
					'taxonomy'   => 'analyst_field',
					'hide_empty' => false,
				)
			);

			$fields = $canonical_map;

			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$slug = $term->slug;
					$fields[ $slug ] = array(
						'slug'  => $slug,
						'label' => $term->name,
						'count' => (int) $term->count,
						'url'   => add_query_arg( 'field', $slug, $archive_url ),
					);
				}
			}

			$post_counts = wp_count_posts( 'analyst_post' );
			$total_count = isset( $post_counts->publish ) ? (int) $post_counts->publish : 0;

			$options = array(
				array(
					'slug'  => 'all',
					'label' => 'همه حوزه‌ها',
					'count' => $total_count,
					'url'   => $archive_url,
				),
			);

			foreach ( $fields as $f ) {
				$options[] = $f;
			}

			return $options;
		}

		/**
		 * گزینه‌های فیلتر آرشیو چندرسانه‌ای.
		 */
		public static function get_media_format_options(): array {
			$archive_url = (string) get_post_type_archive_link( 'multimedia' );
            if ( ! $archive_url ) { return array(); }
			$labels      = array( 'video' => 'ویدئو', 'audio' => 'پادکست', 'gallery' => 'گالری تصویری' );
			$options     = array(
				array( 'slug' => 'all', 'label' => 'همه قالب‌ها', 'count' => (int) ( wp_count_posts( 'multimedia' )->publish ?? 0 ), 'url' => $archive_url ),
			);
			$terms = get_terms( array( 'taxonomy' => 'media_format', 'hide_empty' => false ) );
			$by_slug = array();
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$by_slug[ $term->slug ] = $term;
				}
			}
			foreach ( $labels as $slug => $label ) {
				$options[] = array(
					'slug'  => $slug,
					'label' => isset( $by_slug[ $slug ] ) && $by_slug[ $slug ]->name !== $slug ? $by_slug[ $slug ]->name : $label,
					'count' => isset( $by_slug[ $slug ] ) ? (int) $by_slug[ $slug ]->count : 0,
					'url'   => add_query_arg( 'format', $slug, $archive_url ),
				);
			}
			return $options;
		}

		/** گزینه‌های فیلتر حوزه تخصصی پروفایل‌ها. */
		public static function get_person_field_options(): array {
			$archive_url = get_post_type_archive_link( 'person' ) ?: home_url( '/experts/' );
			$options = array(
				array( 'slug' => 'all', 'label' => 'همه حوزه‌ها', 'count' => (int) ( wp_count_posts( 'person' )->publish ?? 0 ), 'url' => $archive_url ),
			);
			$terms = get_terms( array( 'taxonomy' => 'analyst_field', 'hide_empty' => false ) );
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$options[] = array( 'slug' => $term->slug, 'label' => $term->name, 'count' => (int) $term->count, 'url' => add_query_arg( 'field', $term->slug, $archive_url ) );
				}
			}
			return $options;
		}

		/** ثبت endpoint عمومی و فقط‌خواندنی فید شبکه تحلیلگران. */
		public static function register_rest_routes() {
			register_rest_route(
				'revayat/v1',
				'/analyst-posts',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_analyst_posts_rest' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'page'   => array( 'sanitize_callback' => 'absint', 'default' => 1 ),
						'field'  => array( 'sanitize_callback' => 'sanitize_key', 'default' => 'all' ),
						'sort'   => array( 'sanitize_callback' => 'sanitize_key', 'default' => 'date' ),
						'search' => array( 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
					),
				)
			);
		}

		/**
		 * پاسخ صفحه‌بندی‌شده فید؛ فقط قرارداد عمومی موردنیاز کارت را منتشر می‌کند.
		 *
		 * @param WP_REST_Request $request درخواست REST.
		 * @return WP_REST_Response
		 */
		public static function get_analyst_posts_rest( $request ) {
			$page = min( 250, max( 1, absint( $request->get_param( 'page' ) ) ) );
			$data = self::get_archive_data(
				'analyst_post',
				array(
					'paged'          => $page,
					'posts_per_page' => 6,
					'field'          => sanitize_key( (string) $request->get_param( 'field' ) ),
					'sort'           => sanitize_key( (string) $request->get_param( 'sort' ) ),
					'search'         => mb_substr( sanitize_text_field( (string) $request->get_param( 'search' ) ), 0, 100 ),
				)
			);

			$items = array_map(
				static function ( $item ) {
					$author = is_array( $item['author'] ?? null ) ? $item['author'] : array();
					return array(
						'id'            => absint( $item['id'] ?? 0 ),
						'title'         => wp_strip_all_tags( (string) ( $item['title'] ?? '' ) ),
						'url'           => esc_url_raw( (string) ( $item['url'] ?? $item['permalink'] ?? '' ) ),
						'date'          => sanitize_text_field( (string) ( $item['date'] ?? '' ) ),
						'excerpt'       => wp_strip_all_tags( (string) ( $item['excerpt'] ?? $item['summary'] ?? '' ) ),
						'thumbnail_url' => esc_url_raw( (string) ( $item['thumbnail_url'] ?? $item['media']['url'] ?? '' ) ),
						'analysis_type' => sanitize_text_field( (string) ( $item['analysis_type'] ?? $item['meta']['analysis_type'] ?? 'یادداشت تحلیلی' ) ),
						'field_name'    => sanitize_text_field( (string) ( $item['field_name'] ?? '' ) ),
						'field_url'     => esc_url_raw( (string) ( $item['field_url'] ?? '' ) ),
						'author_name'   => sanitize_text_field( (string) ( $item['author_name'] ?? $author['name'] ?? '' ) ),
						'author_avatar' => esc_url_raw( (string) ( $item['author_avatar'] ?? $author['avatar'] ?? '' ) ),
						'author_url'    => esc_url_raw( (string) ( $item['author_url'] ?? $author['url'] ?? '' ) ),
						'author_role'   => sanitize_text_field( (string) ( $item['author_role'] ?? $author['role'] ?? '' ) ),
						'score'         => (float) ( $item['score'] ?? $item['analyst_score'] ?? 0 ),
						'votes_count'   => absint( $item['votes_count'] ?? $item['analyst_votes'] ?? 0 ),
						'comments_count'=> absint( $item['id'] ?? 0 ) ? (int) get_comments_number( absint( $item['id'] ) ) : 0,
					);
				},
				(array) ( $data['items'] ?? array() )
			);

			$response = rest_ensure_response(
				array(
					'items'        => $items,
					'current_page' => absint( $data['current_page'] ?? $page ),
					'max_pages'    => absint( $data['max_pages'] ?? 0 ),
					'total_posts'  => absint( $data['total_posts'] ?? 0 ),
				)
			);
			$response->header( 'Cache-Control', 'public, max-age=60' );
			return $response;
		}

		/**
		 * واکشی امن پست‌ها برای صفحات آرشیو همراه با متاداده‌های صفحه‌بندی
		 *
		 * @param string $post_type نوع پست‌تایپ.
		 * @param array  $args      آرگومان‌های اضافی WP_Query (نظیر paged, posts_per_page, tax_query).
		 * @return array ساختار [items, total_posts, max_pages, current_page].
		 */
		public static function get_archive_data( $post_type, $args = array() ) {
			if ( 'situation_room' === $post_type && ! self::can_access_situation_room() ) {
				return array(
					'items'         => array(),
					'total_posts'   => 0,
					'max_pages'     => 0,
					'current_page'  => 1,
					'access_denied' => true,
				);
			}
			$paged    = isset( $args['paged'] ) ? max( 1, (int) $args['paged'] ) : ( get_query_var( 'paged' ) ? (int) get_query_var( 'paged' ) : 1 );
			$defaults = array(
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'posts_per_page'         => (int) get_option( 'posts_per_page', 9 ),
				'paged'                  => $paged,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'update_post_meta_cache' => true,
				'update_post_term_cache' => true,
				'ignore_sticky_posts'    => true,
			);

			$query_args = wp_parse_args( $args, $defaults );
			$search_query = sanitize_text_field( $args['search'] ?? ( isset( $_GET['q'] ) ? wp_unslash( $_GET['q'] ) : '' ) );
			$search_query = mb_substr( $search_query, 0, 100 );
			if ( $search_query ) {
				if ( preg_match( '/^(SIT|DOS)-[A-Z0-9-]+$/i', $search_query ) ) {
					$query_args['meta_query'][] = array( 'key' => '_revayat_dossier_code', 'value' => $search_query, 'compare' => 'LIKE' );
				} else {
					$query_args['s'] = $search_query;
				}
			}
			foreach ( array( 'search', 'field', 'format', 'sort', 'topic', 'contributor', 'person_author', 'security_level', 'bulletin_type', 'urgency_level', 'observatory_badge', 'badge' ) as $custom_arg ) {
				unset( $query_args[ $custom_arg ] );
			}
			$active_format = 'all';
			$active_person_field = 'all';
			$active_topic = 'all';
			$active_dossier_sort = 'date';

			if ( 'special_dossier' === $post_type ) {
				$active_topic = sanitize_key( $args['topic'] ?? ( get_query_var( 'dossier_topic' ) ?: 'all' ) );
				if ( 'all' !== $active_topic ) {
					$query_args['tax_query'][] = array( 'taxonomy' => 'dossier_topic', 'field' => 'slug', 'terms' => $active_topic );
				}
				$active_dossier_sort = sanitize_key( $args['sort'] ?? ( isset( $_GET['sort'] ) ? wp_unslash( $_GET['sort'] ) : 'date' ) );
				if ( ! in_array( $active_dossier_sort, array( 'date', 'modified', 'title' ), true ) ) {
					$active_dossier_sort = 'date';
				}
				$query_args['orderby'] = $active_dossier_sort;
				$query_args['order']   = 'title' === $active_dossier_sort ? 'ASC' : 'DESC';
			}

			if ( 'multimedia' === $post_type ) {
				$active_format = sanitize_key( $args['format'] ?? ( isset( $_GET['format'] ) ? wp_unslash( $_GET['format'] ) : 'all' ) );
				if ( 'all' !== $active_format ) {
					$query_args['tax_query'][] = array( 'taxonomy' => 'media_format', 'field' => 'slug', 'terms' => $active_format );
				}
			}

			if ( 'person' === $post_type ) {
				$active_person_field = sanitize_key( $args['field'] ?? ( isset( $_GET['field'] ) ? wp_unslash( $_GET['field'] ) : 'all' ) );
				if ( 'all' !== $active_person_field ) {
					$query_args['tax_query'][] = array( 'taxonomy' => 'analyst_field', 'field' => 'slug', 'terms' => $active_person_field );
				}
			}

			// فیلترهای اختصاصی اتاق وضعیت
			if ( 'situation_room' === $post_type ) {
				// فیلتر بر اساس سطح امنیت
				if ( ! empty( $args['security_level'] ) && 'all' !== $args['security_level'] ) {
					$sec_slug = sanitize_key( $args['security_level'] );
					$query_args['tax_query'] = array(
						array(
							'taxonomy' => 'security_level',
							'field'    => 'slug',
							'terms'    => $sec_slug,
						),
					);
				}

				// فیلتر بر اساس گونه بولتن
				if ( ! empty( $args['bulletin_type'] ) && 'all' !== $args['bulletin_type'] ) {
					if ( ! isset( $query_args['meta_query'] ) ) {
						$query_args['meta_query'] = array();
					}
					$query_args['meta_query'][] = array(
						'key'     => '_revayat_bulletin_type',
						'value'   => sanitize_text_field( $args['bulletin_type'] ),
						'compare' => '=',
					);
				}

				// فیلتر بر اساس سطح فوریت
				if ( ! empty( $args['urgency_level'] ) && 'all' !== $args['urgency_level'] ) {
					if ( ! isset( $query_args['meta_query'] ) ) {
						$query_args['meta_query'] = array();
					}
					$query_args['meta_query'][] = array(
						'key'     => '_revayat_urgency_level',
						'value'   => sanitize_text_field( $args['urgency_level'] ),
						'compare' => '=',
					);
				}
			}

			// فیلترهای اختصاصی دیده‌بان رسانه
			$active_badge = 'all';
			if ( 'media_observatory' === $post_type ) {
				if ( ! empty( $args['badge'] ) && 'all' !== $args['badge'] ) {
					$active_badge = sanitize_key( $args['badge'] );
				} elseif ( ! empty( $args['observatory_badge'] ) && 'all' !== $args['observatory_badge'] ) {
					$active_badge = sanitize_key( $args['observatory_badge'] );
				} elseif ( get_query_var( 'observatory_badge' ) ) {
					$active_badge = sanitize_key( get_query_var( 'observatory_badge' ) );
				} elseif ( isset( $_GET['badge'] ) && ! empty( $_GET['badge'] ) && 'all' !== $_GET['badge'] ) {
					$active_badge = sanitize_key( wp_unslash( $_GET['badge'] ) );
				}

				if ( 'all' !== $active_badge ) {
					if ( ! isset( $query_args['tax_query'] ) ) {
						$query_args['tax_query'] = array();
					}
					$query_args['tax_query'][] = array(
						'taxonomy' => 'observatory_badge',
						'field'    => 'slug',
						'terms'    => $active_badge,
					);
				}
			}

			// فیلترهای اختصاصی یادداشت‌های تحلیلی
			$active_field  = 'all';
			$active_author = '';
			$active_sort   = 'date';
			if ( 'analyst_post' === $post_type ) {
				// فیلتر بر اساس حوزه تحلیلی
				if ( ! empty( $args['field'] ) && 'all' !== $args['field'] ) {
					$active_field = sanitize_key( $args['field'] );
				} elseif ( ! empty( $args['analyst_field'] ) && 'all' !== $args['analyst_field'] ) {
					$active_field = sanitize_key( $args['analyst_field'] );
				} elseif ( get_query_var( 'analyst_field' ) ) {
					$active_field = sanitize_key( get_query_var( 'analyst_field' ) );
				} elseif ( isset( $_GET['field'] ) && ! empty( $_GET['field'] ) && 'all' !== $_GET['field'] ) {
					$active_field = sanitize_key( wp_unslash( $_GET['field'] ) );
				}

				if ( 'all' !== $active_field ) {
					if ( ! isset( $query_args['tax_query'] ) ) {
						$query_args['tax_query'] = array();
					}
					$query_args['tax_query'][] = array(
						'taxonomy' => 'analyst_field',
						'field'    => 'slug',
						'terms'    => $active_field,
					);
				}

				// فیلتر بر اساس کارشناس / پدیدآورنده (Shadow Taxonomy)
				if ( ! empty( $args['author'] ) && 'all' !== $args['author'] && ! is_numeric( $args['author'] ) ) {
					$active_author = sanitize_title( $args['author'] );
				} elseif ( ! empty( $args['contributor'] ) && 'all' !== $args['contributor'] ) {
					$active_author = sanitize_title( $args['contributor'] );
				} elseif ( ! empty( $args['person_author'] ) && 'all' !== $args['person_author'] ) {
					$active_author = sanitize_title( $args['person_author'] );
				} elseif ( get_query_var( 'person_author' ) ) {
					$active_author = sanitize_title( get_query_var( 'person_author' ) );
				} elseif ( isset( $_GET['contributor'] ) && ! empty( $_GET['contributor'] ) ) {
					$active_author = sanitize_title( wp_unslash( $_GET['contributor'] ) );
				} elseif ( isset( $_GET['author'] ) && ! empty( $_GET['author'] ) && ! is_numeric( $_GET['author'] ) ) {
					$active_author = sanitize_title( wp_unslash( $_GET['author'] ) );
				}

				if ( ! empty( $active_author ) ) {
					if ( ! isset( $query_args['tax_query'] ) ) {
						$query_args['tax_query'] = array();
					}
					$query_args['tax_query'][] = array(
						'taxonomy' => 'person_author',
						'field'    => 'slug',
						'terms'    => $active_author,
					);
				}

				// مرتب‌سازی دوگانه: امتیاز (score) در برابر تاریخ (date)
				if ( ! empty( $args['sort'] ) ) {
					$active_sort = sanitize_key( $args['sort'] );
				} elseif ( ! empty( $args['orderby'] ) && in_array( $args['orderby'], array( 'score', 'meta_value_num' ), true ) ) {
					$active_sort = 'score';
				} elseif ( isset( $_GET['sort'] ) && in_array( $_GET['sort'], array( 'date', 'score' ), true ) ) {
					$active_sort = sanitize_key( wp_unslash( $_GET['sort'] ) );
				}

				if ( 'score' === $active_sort ) {
					$query_args['meta_key'] = '_revayat_analyst_score';
					$query_args['orderby']  = 'meta_value_num';
					$query_args['order']    = 'DESC';
				}
			}

			$query = new WP_Query( $query_args );
			self::prime_person_term_meta( $query->posts );

			$normalized = array();
			if ( ! empty( $query->posts ) ) {
				foreach ( $query->posts as $post ) {
					$item = self::normalize_post( $post );
					if ( ! empty( $item ) ) {
						$normalized[] = $item;
					}
				}
			}

			$result = array(
				'items'        => $normalized,
				'total_posts'  => (int) $query->found_posts,
				'max_pages'    => (int) $query->max_num_pages,
				'current_page' => $paged,
				'active_search'=> $search_query,
			);

			if ( 'special_dossier' === $post_type ) {
				$result['active_topic'] = $active_topic;
				$result['active_sort']  = $active_dossier_sort;
			}

			if ( 'situation_room' === $post_type ) {
				$result['user_clearance'] = self::get_user_clearance();
				$result['filter_options'] = array(
					'security_levels' => array(
						array( 'slug' => 'all',        'label' => 'همه سطوح' ),
						array( 'slug' => 'public',     'label' => 'علنی (سطح ۳)' ),
						array( 'slug' => 'restricted', 'label' => 'محدود (سطح ۲)' ),
						array( 'slug' => 'classified', 'label' => 'طبقه‌بندی‌شده (سطح ۱)' ),
					),
				);
				$result['gated_meta']     = array(
					'auth_url'    => home_url( '/auth/?tab=register' ),
					'login_url'   => home_url( '/auth/?tab=login' ),
					'upgrade_url' => home_url( '/auth/?tab=upgrade' ),
					'notice'      => 'مشاهده بولتن‌های راهبردی و پدافندی منوط به عضویت و احراز هویت است.',
				);
			}

			if ( 'media_observatory' === $post_type ) {
				$result['active_badge']   = $active_badge;
				$result['filter_options'] = array(
					'badges' => self::get_observatory_badge_options(),
				);
			}

			if ( 'multimedia' === $post_type ) {
				$result['active_format'] = $active_format;
				$result['filter_options'] = array( 'formats' => self::get_media_format_options() );
			}

			if ( 'person' === $post_type ) {
				$result['active_field'] = $active_person_field;
				$result['filter_options'] = array( 'fields' => self::get_person_field_options() );
			}

			if ( 'analyst_post' === $post_type ) {
				$archive_url   = get_post_type_archive_link( 'analyst_post' ) ?: home_url( '/analysts/' );
				$base_sort_url = ( 'all' !== $active_field ) ? add_query_arg( 'field', $active_field, $archive_url ) : $archive_url;

				$result['active_field']   = $active_field;
				$result['active_sort']    = $active_sort;
				$result['active_author']  = $active_author;
				$result['filter_options'] = array(
					'fields' => self::get_analyst_field_options(),
					'sorts'  => array(
						array(
							'slug'      => 'date',
							'label'     => 'تازه‌ترین‌ها',
							'is_active' => ( 'date' === $active_sort ),
							'url'       => add_query_arg( 'sort', 'date', $base_sort_url ),
						),
						array(
							'slug'      => 'score',
							'label'     => 'برترین امتیاز',
							'is_active' => ( 'score' === $active_sort ),
							'url'       => add_query_arg( 'sort', 'score', $base_sort_url ),
						),
					),
				);
			}

			return $result;
		}

		/**
		 * نرمال‌سازی شیء WP_Post به ساختار استاندارد قرارداد داده‌ای ترکیبی (Hybrid Contract)
		 *
		 * @param WP_Post|int $post شیء یا شناسه پست.
		 * @return array|null خروجی ساختاریافته یا null در صورت نامعتبر بودن پست.
		 */
		public static function normalize_post( $post ) {
			$post = get_post( $post );
			if ( ! $post instanceof WP_Post ) {
				return null;
			}

			$id = (int) $post->ID;

			// محاسبه زمان تقریبی مطالعه
			$content_length = mb_strlen( wp_strip_all_tags( $post->post_content ) );
			$reading_min    = max( 1, (int) ceil( $content_length / 800 ) );
			$reading_time   = $reading_min . ' دقیقه';

			$taxonomies = self::normalize_taxonomies( $post );
			$meta       = self::normalize_meta( $post );
			$media      = self::normalize_media( $post );
			$post_type  = $post->post_type;

			if ( 'situation_room' === $post_type ) {
				$access           = self::evaluate_bulletin_access( $post );
				$raw_attachments  = $meta['attachments'] ?? array();
				$safe_attachments = self::filter_bulletin_attachments( $raw_attachments, $access );
				$raw_gallery      = $meta['sensor_gallery'] ?? array();
				$safe_gallery     = self::filter_sensor_gallery( $raw_gallery, $access );

				if ( ! empty( $access['can_view_content'] ) ) {
					$rendered_content = apply_filters( 'the_content', $post->post_content );
					$raw_content      = $post->post_content;
				} else {
					$rendered_content = self::redact_bulletin_content( $post->post_content, $access, $post );
					$raw_content      = '';
				}

				// همگام‌سازی پیوست‌ها و گالری امن در متا
				$meta['attachments']    = $safe_attachments;
				$meta['sensor_gallery'] = $safe_gallery;
				$meta['is_locked']      = $access['is_locked'];

				return array(
					// ۱. شناسه هویت
					'id'             => $id,

					// ۲. ارجاع بومی شیء وردپرس
					'entity'         => $post,

					// ۳. فیلدهای نرمال‌سازی‌شده محتوا
					'title'          => (string) get_the_title( $post ),
					'excerpt'        => (string) wp_strip_all_tags( get_the_excerpt( $post ) ),
					'content'        => (string) $rendered_content,
					'raw_content'    => (string) $raw_content,
					'permalink'      => (string) get_permalink( $post ),
					'date'           => (string) get_the_date( '', $post ),
					'time_ago'       => (string) human_time_diff( get_the_time( 'U', $post ), current_time( 'timestamp' ) ),
					'reading_time'   => $reading_time,

					// ۴. شیء رسانه منتزع‌شده
					'media'          => $media,

					// ۵. داده‌های ساختاریافته تاکسونومی
					'taxonomies'     => $taxonomies,

					// ۶. فراداده‌های تمیز و تایپ‌شده
					'meta'           => $meta,

					// ۷. دسترسی امنیتی، پیوست‌ها و گالری اتاق وضعیت
					'access'         => $access,
					'attachments'    => $safe_attachments,
					'sensor_gallery' => $safe_gallery,
				);
			}

			if ( 'media_observatory' === $post_type ) {
				$badge_css   = ! empty( $taxonomies['observatory_badge_css'] ) ? $taxonomies['observatory_badge_css'] : 'obs-badge--discourse';
				$badge_slug  = ! empty( $taxonomies['observatory_badge'] ) ? $taxonomies['observatory_badge'] : 'discourse';
				$badge_label = ! empty( $taxonomies['observatory_badge_label'] ) ? $taxonomies['observatory_badge_label'] : 'تحلیل گفتمان';

				return array(
					// ۱. شناسه هویت
					'id'                 => $id,

					// ۲. ارجاع بومی شیء وردپرس
					'entity'             => $post,

					// ۳. فیلدهای نرمال‌سازی‌شده محتوا
					'title'              => (string) get_the_title( $post ),
					'excerpt'            => (string) wp_strip_all_tags( get_the_excerpt( $post ) ),
					'content'            => (string) apply_filters( 'the_content', $post->post_content ),
					'raw_content'        => (string) $post->post_content,
					'permalink'          => (string) get_permalink( $post ),
					'url'                => (string) get_permalink( $post ),
					'date'               => (string) get_the_date( '', $post ),
					'time_ago'           => (string) human_time_diff( get_the_time( 'U', $post ), current_time( 'timestamp' ) ),
					'reading_time'       => $reading_time,

					// ۴. شیء رسانه منتزع‌شده
					'media'              => $media,

					// ۵. داده‌های ساختاریافته تاکسونومی
					'taxonomies'         => $taxonomies,

					// ۶. فراداده‌های تمیز و تایپ‌شده
					'meta'               => $meta,

					// ۷. فیلدهای کمکی اختصاصی دیده‌بان رسانه (Flat DX Helpers)
					'frame_a'            => $meta['frames']['frame_a'] ?? array( 'source' => '', 'text' => '' ),
					'frame_b'            => $meta['frames']['frame_b'] ?? array( 'source' => '', 'text' => '' ),
					'frames'             => $meta['frames'] ?? array(),
					'summary'            => ! empty( $meta['narrative_summary'] ) ? $meta['narrative_summary'] : (string) wp_strip_all_tags( get_the_excerpt( $post ) ),
					'narrative_summary'  => $meta['narrative_summary'] ?? '',
					'monitoring_source'  => ! empty( $meta['monitoring_source'] ) ? $meta['monitoring_source'] : ( $taxonomies['news_source'] ?? '' ),
					'badge_slug'         => $badge_slug,
					'badge_label'        => $badge_label,
					'badge_css'          => $badge_css,
					'badge_class'        => $badge_css,
					'infographic'        => $meta['infographic'] ?? array( 'id' => 0, 'url' => '', 'thumb_url' => '', 'alt' => '', 'has_media' => false ),
					'framing_technique'  => $meta['framing_technique'] ?? '',
					'target_outlets'     => $meta['target_outlets'] ?? '',
					'time_period'        => $meta['time_period'] ?? '',
					'focal_quote'        => $meta['focal_quote'] ?? '',

					// ۸. ساختار دسترسی پیش‌فرض
					'access'             => array(
						'can_view_content'         => true,
						'can_download_attachments' => true,
						'can_view_gallery'         => true,
						'user_clearance'           => self::get_user_clearance(),
						'required_level'           => 'public',
						'security_level'           => 'public',
						'is_locked'                => false,
						'lock_reason'              => '',
						'auth_url'                 => '',
						'login_url'                => home_url( '/auth/?tab=login' ),
						'register_url'             => home_url( '/auth/?tab=register' ),
						'upgrade_url'              => home_url( '/auth/?tab=upgrade' ),
					),
					'attachments'        => array(),
					'sensor_gallery'     => array( 'total_count' => 0, 'is_locked' => false, 'items' => array() ),
				);
			}

			if ( 'analyst_post' === $post_type ) {
				$person_author = $taxonomies['person_author'] ?? array();
				$author_name   = ! empty( $person_author['name'] ) ? $person_author['name'] : (string) get_the_author_meta( 'display_name', $post->post_author );
				$author_role   = ! empty( $person_author['role'] ) ? $person_author['role'] : (string) ( get_the_author_meta( 'headline', $post->post_author ) ?: 'تحلیلگر اندیشکده' );
				$author_avatar = ! empty( $person_author['avatar'] ) ? $person_author['avatar'] : (string) get_avatar_url( $post->post_author, array( 'size' => 96 ) );
				$author_url    = ! empty( $person_author['url'] ) ? $person_author['url'] : (string) get_author_posts_url( $post->post_author );
				$author_org    = ! empty( $person_author['organization'] ) ? $person_author['organization'] : '';
				$author_score  = ! empty( $person_author['person_score'] ) ? (float) $person_author['person_score'] : 0.0;

				$vote_summary  = class_exists( 'Revayat_Companion_User_Portal' ) ? Revayat_Companion_User_Portal::get_analyst_vote_summary( $post->ID ) : array( 'count' => 0, 'average' => 0.0 );
				$raw_score     = $vote_summary['average'];
				if ( $raw_score > 5.0 ) {
					$raw_score = round( $raw_score / 2, 1 );
				}
				$score_val     = $vote_summary['count'] ? max( 1.0, min( 5.0, $raw_score ) ) : 0.0;
				$score_str     = $vote_summary['count'] ? number_format( $score_val, 1 ) : '—';

				$featured_quote = ! empty( $meta['featured_quote'] ) ? $meta['featured_quote'] : ( ! empty( $meta['analyst_quote'] ) ? $meta['analyst_quote'] : (string) wp_strip_all_tags( get_the_excerpt( $post ) ) );

				return array(
					// ۱. شناسه هویت
					'id'                 => $id,

					// ۲. ارجاع بومی شیء وردپرس
					'entity'             => $post,

					// ۳. فیلدهای نرمال‌سازی‌شده محتوا
					'title'              => (string) get_the_title( $post ),
					'excerpt'            => (string) wp_strip_all_tags( get_the_excerpt( $post ) ),
					'content'            => (string) apply_filters( 'the_content', $post->post_content ),
					'raw_content'        => (string) $post->post_content,
					'permalink'          => (string) get_permalink( $post ),
					'url'                => (string) get_permalink( $post ),
					'date'               => (string) get_the_date( '', $post ),
					'time_ago'           => (string) human_time_diff( get_the_time( 'U', $post ), current_time( 'timestamp' ) ),
					'reading_time'       => $reading_time,

					// ۴. شیء رسانه منتزع‌شده
					'media'              => $media,

					// ۵. داده‌های ساختاریافته تاکسونومی
					'taxonomies'         => $taxonomies,

					// ۶. فراداده‌های تمیز و تایپ‌شده
					'meta'               => $meta,

					// ۷. فیلدهای کمکی اختصاصی شبکه تحلیلگران (Flat DX Helpers)
					'author'             => $person_author,
					'author_name'        => $author_name,
					'author_role'        => $author_role,
					'author_meta'        => $author_role ?: $author_org,
					'author_avatar'      => $author_avatar,
					'author_url'         => $author_url,
					'author_score'       => $author_score,
					'author_org'         => $author_org,
					'score'              => $score_str,
					'analyst_score'      => $score_val,
					'featured_quote'     => $featured_quote,
					'analyst_quote'      => $featured_quote,
					'desc'               => $featured_quote,
					'analysis_type'      => $meta['analysis_type'] ?? 'تحلیل راهبردی',
					'reading_depth'      => $meta['reading_depth'] ?? 'تحلیل تخصصی',
					'key_takeaways'      => $meta['key_takeaways'] ?? '',
					'references'         => $meta['references'] ?? '',
					'analyst_votes'      => $vote_summary['count'],
					'votes_count'        => $vote_summary['count'],
					'field_slug'         => $taxonomies['analyst_field_slug'] ?? '',
					'field_name'         => $taxonomies['analyst_field'] ?? '',
					'field_label'        => $taxonomies['analyst_field'] ?? '',
					'field_url'          => $taxonomies['analyst_field_url'] ?? '',

					// ۸. ساختار دسترسی پیش‌فرض
					'access'             => array(
						'can_view_content'         => true,
						'can_download_attachments' => true,
						'can_view_gallery'         => true,
						'user_clearance'           => self::get_user_clearance(),
						'required_level'           => 'public',
						'security_level'           => 'public',
						'is_locked'                => false,
						'lock_reason'              => '',
						'auth_url'                 => '',
						'login_url'                => home_url( '/auth/?tab=login' ),
						'register_url'             => home_url( '/auth/?tab=register' ),
						'upgrade_url'              => home_url( '/auth/?tab=upgrade' ),
					),
					'attachments'        => array(),
					'sensor_gallery'     => array( 'total_count' => 0, 'is_locked' => false, 'items' => array() ),
				);
			}

			// سایر پست‌تایپ‌ها
			return array(
				// ۱. شناسه هویت
				'id'             => $id,

				// ۲. ارجاع بومی شیء وردپرس
				'entity'         => $post,

				// ۳. فیلدهای نرمال‌سازی‌شده محتوا
				'title'          => (string) get_the_title( $post ),
				'excerpt'        => (string) wp_strip_all_tags( get_the_excerpt( $post ) ),
				'content'        => (string) apply_filters( 'the_content', $post->post_content ),
				'raw_content'    => (string) $post->post_content,
				'permalink'      => (string) get_permalink( $post ),
				'date'           => (string) get_the_date( '', $post ),
				'time_ago'       => (string) human_time_diff( get_the_time( 'U', $post ), current_time( 'timestamp' ) ),
				'reading_time'   => $reading_time,

				// ۴. شیء رسانه منتزع‌شده
				'media'          => $media,

				// ۵. داده‌های ساختاریافته تاکسونومی
				'taxonomies'     => $taxonomies,

				// ۶. فراداده‌های تمیز و تایپ‌شده
				'meta'           => $meta,

				// ۷. ساختار دسترسی پیش‌فرض
				'access'         => array(
					'can_view_content'         => true,
					'can_download_attachments' => true,
					'can_view_gallery'         => true,
					'user_clearance'           => self::get_user_clearance(),
					'required_level'           => 'public',
					'security_level'           => 'public',
					'is_locked'                => false,
					'lock_reason'              => '',
					'auth_url'                 => '',
					'login_url'                => home_url( '/auth/?tab=login' ),
					'register_url'             => home_url( '/auth/?tab=register' ),
					'upgrade_url'              => home_url( '/auth/?tab=upgrade' ),
				),
				'attachments'    => array(),
				'sensor_gallery' => array( 'total_count' => 0, 'is_locked' => false, 'items' => array() ),
			);
		}

		/**
		 * نرمال‌سازی داده‌های رسانه‌ای پست
		 *
		 * @param WP_Post $post شیء پست.
		 * @return array
		 */
		public static function normalize_media( $post ) {
			$thumb_id  = get_post_thumbnail_id( $post );
			$has_media = ! empty( $thumb_id );

			if ( $has_media ) {
				$url    = (string) wp_get_attachment_image_url( $thumb_id, 'large' );
				$alt    = (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );
				$srcset = (string) wp_get_attachment_image_srcset( $thumb_id );

				return array(
					'id'        => (int) $thumb_id,
					'url'       => $url,
					'alt'       => ! empty( $alt ) ? $alt : get_the_title( $post ),
					'srcset'    => $srcset,
					'has_media' => true,
				);
			}

			return array(
				'id'        => 0,
				'url'       => '',
				'alt'       => '',
				'srcset'    => '',
				'has_media' => false,
			);
		}

		/**
		 * نرمال‌سازی اطلاعات تاکسونومی‌های مرتبط با پست
		 *
		 * @param WP_Post $post شیء پست.
		 * @return array
		 */
		public static function normalize_taxonomies( $post ) {
			$id = (int) $post->ID;

			// ۱. جایگاه تحریریه
			$placement_terms = wp_get_object_terms( $id, 'editorial_placement', array( 'fields' => 'slugs' ) );
			$placement       = ( ! empty( $placement_terms ) && ! is_wp_error( $placement_terms ) ) ? $placement_terms[0] : '';

			// ۲. دسته‌بندی موضوعی
			$cats     = get_the_category( $id );
			$category = array(
				'name' => '',
				'slug' => '',
				'url'  => '',
			);
			if ( ! empty( $cats ) && ! is_wp_error( $cats ) ) {
				$category = array(
					'name' => $cats[0]->name,
					'slug' => $cats[0]->slug,
					'url'  => get_category_link( $cats[0]->term_id ),
				);
			}

			// ۳. منبع خبری
			$source_terms = wp_get_object_terms( $id, 'news_source', array( 'fields' => 'names' ) );
			$news_source  = ( ! empty( $source_terms ) && ! is_wp_error( $source_terms ) ) ? $source_terms[0] : '';

			// ۴. سطح امنیت
			$sec_terms            = wp_get_object_terms( $id, 'security_level' );
			$security_level       = 'public';
			$sec_label_map        = array(
				'classified' => 'طبقه‌بندی‌شده (سطح ۱)',
				'restricted' => 'محدود (سطح ۲)',
				'public'     => 'علنی (سطح ۳)',
				'high'       => 'طبقه‌بندی‌شده (سطح ۱)',
				'normal'     => 'محدود (سطح ۲)',
			);
			$security_level_label = 'علنی (سطح ۳)';

			if ( ! empty( $sec_terms ) && ! is_wp_error( $sec_terms ) ) {
				$security_level       = $sec_terms[0]->slug;
				$security_level_label = $sec_terms[0]->name;
			}

			if ( ! empty( $sec_label_map[ $security_level ] ) && ( $security_level === $security_level_label || empty( $security_level_label ) || ! preg_match( '/[\x{0600}-\x{06FF}]/u', $security_level_label ) ) ) {
				$security_level_label = $sec_label_map[ $security_level ];
			}

			// ۵. پدیدآورنده / شخص (مطابق الگوی Shadow Taxonomy در ADR-004)
			$person_terms  = get_the_terms( $id, 'person_author' );
			$person_author = array(
				'name'         => '',
				'role'         => '',
				'avatar'       => '',
				'organization' => '',
				'expertise'    => '',
				'person_score' => 0.0,
				'person_votes' => 0,
				'analyses_count' => 0,
				'term_id'      => 0,
				'person_id'    => 0,
				'url'          => '',
			);
			if ( ! empty( $person_terms ) && ! is_wp_error( $person_terms ) ) {
				$term                      = $person_terms[0];
				$person_author['name']    = $term->name;
				$person_author['term_id'] = (int) $term->term_id;
				$term_link                 = get_term_link( $term );
				$person_author['url']     = ! is_wp_error( $term_link ) ? $term_link : '';

				$person_id = class_exists( 'Revayat_Companion_Person_Identity' ) ? Revayat_Companion_Person_Identity::get_person_id_for_term( $term ) : 0;
				$person_post = $person_id ? get_post( $person_id ) : null;
				if ( $person_post instanceof WP_Post ) {
					$person_author['person_id']    = (int) $person_post->ID;
					$person_author['role']         = (string) get_post_meta( $person_post->ID, '_revayat_role_title', true );
					$person_author['organization'] = (string) get_post_meta( $person_post->ID, '_revayat_organization', true );
					$person_author['expertise']    = (string) get_post_meta( $person_post->ID, '_revayat_expertise', true );
					$p_score                       = (float) get_post_meta( $person_post->ID, '_revayat_person_total_score', true );
					if ( $p_score > 0 ) {
						$person_author['person_score'] = $p_score;
					}
					$p_votes                       = (int) get_post_meta( $person_post->ID, '_revayat_person_votes', true );
					$person_author['person_votes'] = $p_votes;
					$person_author['analyses_count'] = (int) get_post_meta( $person_post->ID, '_revayat_person_analyses_count', true );
					$person_author['url'] = (string) get_permalink( $person_post->ID );
					$avatar_id                     = (int) get_post_meta( $person_post->ID, '_revayat_avatar_id', true );
					if ( ! $avatar_id && has_post_thumbnail( $person_post->ID ) ) {
						$avatar_id = get_post_thumbnail_id( $person_post->ID );
					}
					if ( $avatar_id ) {
						$person_author['avatar'] = (string) wp_get_attachment_image_url( $avatar_id, 'thumbnail' );
					}
				}
			}

			// فال‌بک تمیز به نویسنده بومی وردپرس در صورت عدم انتساب ترم
			if ( empty( $person_author['name'] ) && ! empty( $post->post_author ) ) {
				$author_id                     = (int) $post->post_author;
				$person_author['name']         = (string) get_the_author_meta( 'display_name', $author_id );
				$person_author['role']         = (string) ( get_the_author_meta( 'headline', $author_id ) ?: 'تحلیلگر اندیشکده' );
				$person_author['avatar']       = (string) get_avatar_url( $author_id, array( 'size' => 96 ) );
				$person_author['url']          = (string) get_author_posts_url( $author_id );
				$person_author['organization'] = (string) ( get_the_author_meta( 'organization', $author_id ) ?: '' );
			}

			// ۶. گونه واکاوی دیده‌بان
			$obs_terms               = wp_get_object_terms( $id, 'observatory_badge' );
			$observatory_badge       = '';
			$observatory_badge_label = '';
			$observatory_badge_css   = '';
			if ( ! empty( $obs_terms ) && ! is_wp_error( $obs_terms ) ) {
				$observatory_badge       = $obs_terms[0]->slug;
				$observatory_badge_label = $obs_terms[0]->name;
			}

			$obs_badge_labels = array(
				'discourse' => 'تحلیل گفتمان',
				'framing'   => 'مقایسه فریمینگ',
				'campaign'  => 'کمپین‌شناسی',
				'narrative' => 'جنگ روایت‌ها',
			);

			if ( ! empty( $observatory_badge ) ) {
				if ( empty( $observatory_badge_label ) && isset( $obs_badge_labels[ $observatory_badge ] ) ) {
					$observatory_badge_label = $obs_badge_labels[ $observatory_badge ];
				}
				$observatory_badge_css = 'obs-badge--' . sanitize_html_class( $observatory_badge );
			}

			// ۷. قالب رسانه
			$fmt_terms    = wp_get_object_terms( $id, 'media_format', array( 'fields' => 'slugs' ) );
			$media_format = ( ! empty( $fmt_terms ) && ! is_wp_error( $fmt_terms ) ) ? $fmt_terms[0] : 'video';

			// ۸. محور پرونده
			$dossier_terms = wp_get_object_terms( $id, 'dossier_topic', array( 'fields' => 'names' ) );
			$dossier_topic = ( ! empty( $dossier_terms ) && ! is_wp_error( $dossier_terms ) ) ? $dossier_terms[0] : '';

			// ۹. رشته تخصصی تحلیلگر
			$field_terms        = wp_get_object_terms( $id, 'analyst_field' );
			$analyst_field      = '';
			$analyst_field_slug = '';
			$analyst_field_url  = '';
			if ( ! empty( $field_terms ) && ! is_wp_error( $field_terms ) ) {
				$analyst_field      = $field_terms[0]->name;
				$analyst_field_slug = $field_terms[0]->slug;
				$field_link         = get_term_link( $field_terms[0] );
				$analyst_field_url  = ! is_wp_error( $field_link ) ? $field_link : '';
			}

			return array(
				'editorial_placement'     => $placement,
				'category'                => $category,
				'news_source'             => $news_source,
				'security_level'          => $security_level,
				'security_level_label'    => $security_level_label,
				'person_author'           => $person_author,
				'observatory_badge'       => $observatory_badge,
				'observatory_badge_label' => $observatory_badge_label,
				'observatory_badge_css'   => $observatory_badge_css,
				'media_format'            => $media_format,
				'dossier_topic'           => $dossier_topic,
				'analyst_field'           => $analyst_field,
				'analyst_field_slug'      => $analyst_field_slug,
				'analyst_field_url'       => $analyst_field_url,
			);
		}

		/**
		 * نرمال‌سازی فراداده‌های اختصاصی پست
		 *
		 * @param WP_Post $post شیء پست.
		 * @return array
		 */
		public static function normalize_meta( $post ) {
			$id = (int) $post->ID;

			$infographic_id = (int) ( get_post_meta( $id, '_revayat_obs_infographic_id', true ) ?: get_post_meta( $id, '_revayat_infographic_id', true ) );
			$infographic    = array(
				'id'        => 0,
				'url'       => '',
				'thumb_url' => '',
				'alt'       => '',
				'has_media' => false,
			);

			if ( $infographic_id > 0 ) {
				$info_url   = (string) wp_get_attachment_image_url( $infographic_id, 'full' );
				$info_thumb = (string) wp_get_attachment_image_url( $infographic_id, 'large' );
				$info_alt   = (string) get_post_meta( $infographic_id, '_wp_attachment_image_alt', true );

				$infographic = array(
					'id'        => $infographic_id,
					'url'       => $info_url,
					'thumb_url' => $info_thumb ?: $info_url,
					'alt'       => ! empty( $info_alt ) ? $info_alt : get_the_title( $post ),
					'has_media' => ! empty( $info_url ),
				);
			}

			return array(
				'is_locked'          => (bool) get_post_meta( $id, '_revayat_sr_is_locked', true ),
				'auth_url'           => (string) get_post_meta( $id, '_revayat_sr_auth_url', true ),
				'bulletin_type'      => (string) get_post_meta( $id, '_revayat_bulletin_type', true ),
				'urgency_level'      => (string) get_post_meta( $id, '_revayat_urgency_level', true ),
				'verification_status'=> (string) get_post_meta( $id, '_revayat_verification_status', true ),
				'update_time'        => (string) get_post_meta( $id, '_revayat_update_time', true ),
				'duration'           => (string) ( get_post_meta( $id, '_revayat_duration', true ) ?: get_post_meta( $id, '_revayat_media_duration', true ) ),
				'video_url'          => (string) get_post_meta( $id, '_revayat_video_url', true ),
				'media_type'         => (string) get_post_meta( $id, '_revayat_media_type', true ),
				'analyst_score'      => (float) get_post_meta( $id, '_revayat_analyst_score', true ),
				'analysis_type'      => (string) ( get_post_meta( $id, '_revayat_analysis_type', true ) ?: 'تحلیل راهبردی' ),
				'featured_quote'     => (string) ( get_post_meta( $id, '_revayat_featured_quote', true ) ?: get_post_meta( $id, '_revayat_analyst_quote', true ) ),
				'analyst_quote'      => (string) ( get_post_meta( $id, '_revayat_analyst_quote', true ) ?: get_post_meta( $id, '_revayat_featured_quote', true ) ),
				'reading_depth'      => (string) ( get_post_meta( $id, '_revayat_reading_depth', true ) ?: 'تحلیل تخصصی' ),
				'key_takeaways'      => (string) get_post_meta( $id, '_revayat_key_takeaways', true ),
				'references'         => (string) get_post_meta( $id, '_revayat_references', true ),
				'analyst_votes'      => (int) get_post_meta( $id, '_revayat_analyst_votes', true ),
				'lead_status'        => (string) get_post_meta( $id, '_revayat_lead_status', true ),
				'dossier_code'       => (string) get_post_meta( $id, '_revayat_dossier_code', true ),
				'is_live'            => (bool) get_post_meta( $id, '_revayat_dossier_is_live', true ),
				'last_update'        => (string) get_post_meta( $id, '_revayat_dossier_last_update', true ),
				'editor_lead'        => (string) get_post_meta( $id, '_revayat_dossier_editor_lead', true ),
				'stats'              => array(
					'docs'   => (int) get_post_meta( $id, '_revayat_stats_documents', true ),
					'frames' => (int) get_post_meta( $id, '_revayat_stats_frames', true ),
					'notes'  => (int) get_post_meta( $id, '_revayat_stats_notes', true ),
				),
				'frames'             => array(
					'frame_a' => array(
						'source' => (string) ( get_post_meta( $id, '_revayat_obs_frame_a_source', true ) ?: 'منبع اول' ),
						'text'   => (string) ( get_post_meta( $id, '_revayat_frame_a', true ) ?: get_post_meta( $id, '_revayat_obs_frame_a_text', true ) ),
					),
					'frame_b' => array(
						'source' => (string) ( get_post_meta( $id, '_revayat_obs_frame_b_source', true ) ?: 'منبع دوم' ),
						'text'   => (string) ( get_post_meta( $id, '_revayat_frame_b', true ) ?: get_post_meta( $id, '_revayat_obs_frame_b_text', true ) ),
					),
				),
				'narrative_summary'  => (string) ( get_post_meta( $id, '_revayat_narrative_summary', true ) ?: get_post_meta( $id, '_revayat_obs_summary', true ) ),
				'monitoring_source'  => (string) get_post_meta( $id, '_revayat_monitoring_source', true ),
				'framing_technique'  => (string) ( get_post_meta( $id, '_revayat_obs_technique', true ) ?: get_post_meta( $id, '_revayat_framing_technique', true ) ),
				'target_outlets'     => (string) ( get_post_meta( $id, '_revayat_obs_target_outlets', true ) ?: get_post_meta( $id, '_revayat_target_outlets', true ) ),
				'time_period'        => (string) ( get_post_meta( $id, '_revayat_obs_time_period', true ) ?: get_post_meta( $id, '_revayat_time_period', true ) ),
				'focal_quote'        => (string) ( get_post_meta( $id, '_revayat_obs_focal_quote', true ) ?: get_post_meta( $id, '_revayat_focal_quote', true ) ),
				'infographic_id'     => $infographic_id,
				'infographic'        => $infographic,
				'role_title'         => (string) get_post_meta( $id, '_revayat_role_title', true ),
				'organization'       => (string) get_post_meta( $id, '_revayat_organization', true ),
				'expertise'          => (string) get_post_meta( $id, '_revayat_expertise', true ),
				'person_total_score' => (float) get_post_meta( $id, '_revayat_person_total_score', true ),
				'person_votes'       => (int) get_post_meta( $id, '_revayat_person_votes', true ),
				'analyses_count'     => (int) get_post_meta( $id, '_revayat_person_analyses_count', true ),
				'timeline_events'    => (array) ( get_post_meta( $id, '_revayat_timeline_events', true ) ?: array() ),
				'source_org'         => (string) get_post_meta( $id, '_revayat_sr_source_org', true ),
				'redacted_notice'    => (string) get_post_meta( $id, '_revayat_sr_redacted_notice', true ),
				'attachments'        => (array) ( get_post_meta( $id, '_revayat_sr_attachments', true ) ?: array() ),
				'sensor_gallery'     => (array) ( get_post_meta( $id, '_revayat_sr_sensor_gallery', true ) ?: array() ),
			);
		}

		/**
		 * واکشی مطالب یا پرونده‌های مرتبط بر اساس پست فعلی
		 *
		 * @param int $post_id شناسه پست مبدا.
		 * @param int $count   تعداد پست‌های درخواستی.
		 * @return array
		 */
		public static function get_related_posts( $post_id = 0, $count = 3 ) {
			$post_id = (int) ( $post_id ?: get_the_ID() );
			if ( ! $post_id ) {
				return array();
			}

			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post ) {
				return array();
			}

			$post_type = $post->post_type;

			$args = array(
				'post_type'      => $post_type,
				'posts_per_page' => (int) $count,
				'post__not_in'   => array( $post_id ),
				'orderby'        => 'date',
				'order'          => 'DESC',
			);

			if ( 'special_dossier' === $post_type ) {
				$topics = wp_get_object_terms( $post_id, 'dossier_topic', array( 'fields' => 'ids' ) );
				if ( ! empty( $topics ) && ! is_wp_error( $topics ) ) {
					$args['tax_query'] = array(
						array(
							'taxonomy' => 'dossier_topic',
							'field'    => 'term_id',
							'terms'    => $topics,
						),
					);
				}
			}

			$results = self::get_posts_by_type( $post_type, $args );

			if ( empty( $results ) && isset( $args['tax_query'] ) ) {
				unset( $args['tax_query'] );
				$results = self::get_posts_by_type( $post_type, $args );
			}

			return $results;
		}
	}
}
