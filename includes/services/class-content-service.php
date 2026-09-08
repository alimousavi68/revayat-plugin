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
			$query      = new WP_Query( $query_args );

			$normalized = array();
			if ( ! empty( $query->posts ) ) {
				foreach ( $query->posts as $post ) {
					$item = self::normalize_post( $post );
					if ( ! empty( $item ) ) {
						$normalized[] = $item;
					}
				}
			}

			return array(
				'items'        => $normalized,
				'total_posts'  => (int) $query->found_posts,
				'max_pages'    => (int) $query->max_num_pages,
				'current_page' => $paged,
			);
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

			return array(
				// ۱. شناسه هویت
				'id'           => $id,

				// ۲. ارجاع بومی شیء وردپرس
				'entity'       => $post,

				// ۳. فیلدهای نرمال‌سازی‌شده محتوا
				'title'        => (string) get_the_title( $post ),
				'excerpt'      => (string) wp_strip_all_tags( get_the_excerpt( $post ) ),
				'permalink'    => (string) get_permalink( $post ),
				'date'         => (string) get_the_date( '', $post ),
				'time_ago'     => (string) human_time_diff( get_the_time( 'U', $post ), current_time( 'timestamp' ) ),
				'reading_time' => $reading_time,

				// ۴. شیء رسانه منتزع‌شده
				'media'        => self::normalize_media( $post ),

				// ۵. داده‌های ساختاریافته تاکسونومی
				'taxonomies'   => self::normalize_taxonomies( $post ),

				// ۶. فراداده‌های تمیز و تایپ‌شده
				'meta'         => self::normalize_meta( $post ),
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
			$sec_terms      = wp_get_object_terms( $id, 'security_level', array( 'fields' => 'slugs' ) );
			$security_level = ( ! empty( $sec_terms ) && ! is_wp_error( $sec_terms ) ) ? $sec_terms[0] : 'public';

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
