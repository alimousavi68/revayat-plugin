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

			$normalized = array();
			foreach ( $query->posts as $post ) {
				$item = self::normalize_post( $post );
				if ( ! empty( $item ) ) {
					$normalized[] = $item;
				}
			}

			return $normalized;
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
		 * واکشی امن پست‌ها برای صفحات آرشیو همراه با متاداده‌های صفحه‌بندی
		 *
		 * @param string $post_type نوع پست‌تایپ.
		 * @param array  $args      آرگومان‌های اضافی WP_Query (نظیر paged, posts_per_page, tax_query).
		 * @return array ساختار [items, total_posts, max_pages, current_page].
		 */
		public static function get_archive_data( $post_type, $args = array() ) {
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
			);

			$query_args = wp_parse_args( $args, $defaults );

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

			$query = new WP_Query( $query_args );

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
			);

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
			$person_terms  = wp_get_object_terms( $id, 'person_author' );
			$person_author = array(
				'name'      => '',
				'role'      => '',
				'avatar'    => '',
				'term_id'   => 0,
				'person_id' => 0,
				'url'       => '',
			);
			if ( ! empty( $person_terms ) && ! is_wp_error( $person_terms ) ) {
				$term                      = $person_terms[0];
				$person_author['name']    = $term->name;
				$person_author['term_id'] = (int) $term->term_id;
				$term_link                 = get_term_link( $term );
				$person_author['url']     = ! is_wp_error( $term_link ) ? $term_link : '';

				// یافتن پروفایل CPT person متناظر با اسلاگ یا نام کارشناس
				$person_query = new WP_Query(
					array(
						'post_type'              => 'person',
						'name'                   => $term->slug,
						'posts_per_page'         => 1,
						'no_found_rows'          => true,
						'update_post_meta_cache' => true,
					)
				);
				if ( ! empty( $person_query->posts ) ) {
					$person_post                 = $person_query->posts[0];
					$person_author['person_id'] = (int) $person_post->ID;
					$person_author['role']      = (string) get_post_meta( $person_post->ID, '_revayat_role_title', true );
					$avatar_id                   = (int) get_post_meta( $person_post->ID, '_revayat_avatar_id', true );
					if ( ! $avatar_id && has_post_thumbnail( $person_post->ID ) ) {
						$avatar_id = get_post_thumbnail_id( $person_post->ID );
					}
					if ( $avatar_id ) {
						$person_author['avatar'] = (string) wp_get_attachment_image_url( $avatar_id, 'thumbnail' );
					}
				}
			}

			// ۶. گونه واکاوی دیده‌بان
			$obs_terms               = wp_get_object_terms( $id, 'observatory_badge' );
			$observatory_badge       = '';
			$observatory_badge_label = '';
			if ( ! empty( $obs_terms ) && ! is_wp_error( $obs_terms ) ) {
				$observatory_badge       = $obs_terms[0]->slug;
				$observatory_badge_label = $obs_terms[0]->name;
			}

			// ۷. قالب رسانه
			$fmt_terms    = wp_get_object_terms( $id, 'media_format', array( 'fields' => 'slugs' ) );
			$media_format = ( ! empty( $fmt_terms ) && ! is_wp_error( $fmt_terms ) ) ? $fmt_terms[0] : 'video';

			// ۸. محور پرونده
			$dossier_terms = wp_get_object_terms( $id, 'dossier_topic', array( 'fields' => 'names' ) );
			$dossier_topic = ( ! empty( $dossier_terms ) && ! is_wp_error( $dossier_terms ) ) ? $dossier_terms[0] : '';

			// ۹. رشته تخصصی تحلیلگر
			$field_terms   = wp_get_object_terms( $id, 'analyst_field', array( 'fields' => 'names' ) );
			$analyst_field = ( ! empty( $field_terms ) && ! is_wp_error( $field_terms ) ) ? $field_terms[0] : '';

			return array(
				'editorial_placement'     => $placement,
				'category'                => $category,
				'news_source'             => $news_source,
				'security_level'          => $security_level,
				'security_level_label'    => $security_level_label,
				'person_author'           => $person_author,
				'observatory_badge'       => $observatory_badge,
				'observatory_badge_label' => $observatory_badge_label,
				'media_format'            => $media_format,
				'dossier_topic'           => $dossier_topic,
				'analyst_field'           => $analyst_field,
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
				'analysis_type'      => (string) get_post_meta( $id, '_revayat_analysis_type', true ),
				'featured_quote'     => (string) get_post_meta( $id, '_revayat_featured_quote', true ),
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
				'role_title'         => (string) get_post_meta( $id, '_revayat_role_title', true ),
				'organization'       => (string) get_post_meta( $id, '_revayat_organization', true ),
				'expertise'          => (string) get_post_meta( $id, '_revayat_expertise', true ),
				'person_total_score' => (float) get_post_meta( $id, '_revayat_person_total_score', true ),
				'person_votes'       => (int) get_post_meta( $id, '_revayat_person_votes', true ),
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
