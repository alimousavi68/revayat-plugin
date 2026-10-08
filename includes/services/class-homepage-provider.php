<?php
/**
 * تامین‌کننده داده‌های صفحه نخست پایگاه (Homepage Data Provider)
 *
 * چرا این فایل لازم است؟
 * جهت تفکیک منطق کوئری‌های اختصاصی ۸ سکشن صفحه نخست پایگاه از پوسته و تولید ساختار قرارداد داده (Data Contract).
 *
 * چه مسئولیتی دارد؟
 * - تامین داده‌های ۸ سکشن صفحه اصلی (Hero, Daily Narrative, Situation Room, News, Dossiers, Observatory, Analysts, Multimedia).
 * - هدایت کوئری‌ها بر اساس تاکسونومی‌های تحریریه (editorial_placement و...).
 * - بازگرداندن آرایه خالی استاندارد در غیاب داده‌های منتشرشده بدون وابستگی به demo-data (جهت فعال‌سازی فال‌بک امن پوسته).
 *
 * ارتباط با معماری:
 * لایه Provider Adapter در افزونه مکمل که مطابق با سند docs/architecture/provider-contract.md و اصل Plugin Optional Dependency در ADR-005 عمل می‌کند.
 *
 * @package Revayat_Companion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Revayat_Companion_Homepage_Provider' ) ) {

	/**
	 * کلاس تامین‌کننده داده‌های صفحه اصلی پایگاه
	 */
	class Revayat_Companion_Homepage_Provider {

        /** Apply bounded Customizer preferences only to homepage queries. */
        public static function section_query_args( string $section, string $post_type, array $args ): array {
            $taxonomies = array(
                'hero' => 'category', 'daily-narrative' => 'category',
                'situation-room' => 'security_level', 'news-monitoring' => 'news_source',
                'special-dossiers' => 'dossier_topic', 'media-observatory' => 'observatory_badge',
                'analysts-network' => 'analyst_field', 'multimedia' => 'media_format',
            );
            if ( ! isset( $taxonomies[ $section ] ) ) {
                return $args;
            }
            $read = static fn( $key, $default ) => get_theme_mod( 'revayat_' . $section . '_' . $key, $default );
            $people = 'person' === $post_type;
            $placement = $args['tax_query'][0]['terms'] ?? '';
            $lead = in_array( $placement, array( 'hero_lead', 'daily_lead' ), true );
            if ( in_array( $section, array( 'hero', 'daily-narrative' ), true ) ) {
                $role = $lead ? 'lead' : 'side';
                $slug = sanitize_title( (string) $read( $role . '_placement', $placement ) );
                $args['tax_query'] = $slug ? array( array( 'taxonomy' => 'editorial_placement', 'field' => 'slug', 'terms' => $slug ) ) : array();
            }
            $args['posts_per_page'] = $lead ? 1 : max( 1, min( 16, (int) $read( $people ? 'people_count' : 'count', $args['posts_per_page'] ?? 8 ) ) );
            $orderby = $people ? 'date' : $read( 'orderby', $args['orderby'] ?? 'date' );
            $args['orderby'] = in_array( $orderby, array( 'date', 'modified', 'title', 'menu_order' ), true ) ? $orderby : 'date';
            $order = $read( $people ? 'people_order' : 'order', $args['order'] ?? 'DESC' );
            $args['order'] = 'ASC' === $order ? 'ASC' : 'DESC';
            $args['offset'] = $people ? 0 : max( 0, min( 100, (int) $read( 'offset', 0 ) ) );
            if ( ! $people ) { $args = Revayat_Companion_Homepage_Query::apply( $section, $args ); }
            elseif ( in_array( 'person', Revayat_Companion_Homepage_Query::csv( $read( 'excluded_post_types', '' ) ), true ) ) { $args['post__in'] = array( 0 ); }
            return $args;
        }

        private static function get_section_posts( string $section, string $post_type, array $args ): array {
            return Revayat_Companion_Content_Service::get_posts_by_type( $post_type, self::section_query_args( $section, $post_type, $args ) );
        }

		/**
		 * تامین داده‌های سکشن ویترین اصلی (Hero)
		 *
		 * @return array ساختار [lead, side] یا آرایه خالی در صورت عدم وجود داده.
		 */
		public static function get_hero_data() {
			// ۱. واکشی تیتر یک (Lead)
			$lead_posts = self::get_section_posts( 'hero',
				'post',
				array(
					'posts_per_page' => 1,
					'tax_query'      => array(
						array(
							'taxonomy' => 'editorial_placement',
							'field'    => 'slug',
							'terms'    => 'hero_lead',
						),
					),
				)
			);

			// ۲. واکشی ۴ خبر جانبی ویترین (Side Items)
			$side_posts = self::get_section_posts( 'hero',
				'post',
				array(
					'posts_per_page' => 4,
					'tax_query'      => array(
						array(
							'taxonomy' => 'editorial_placement',
							'field'    => 'slug',
							'terms'    => 'hero_side',
						),
					),
				)
			);

			// در صورت عدم وجود هیچ‌یک از پست‌های تحریریه، خروجی خالی برگردانده می‌شود تا پوسته از فال‌بک امن استفاده کند
			if ( empty( $lead_posts ) && empty( $side_posts ) ) {
				return array();
			}

			return array(
				'lead' => ! empty( $lead_posts ) ? $lead_posts[0] : null,
				'side' => $side_posts,
			);
		}

		/**
		 * تامین داده‌های سکشن روایت روز (Daily Narrative)
		 *
		 * @return array
		 */
		public static function get_daily_narrative_data() {
			$lead_posts = self::get_section_posts( 'daily-narrative',
				'post',
				array(
					'posts_per_page' => 1,
					'tax_query'      => array(
						array(
							'taxonomy' => 'editorial_placement',
							'field'    => 'slug',
							'terms'    => 'daily_lead',
						),
					),
				)
			);

			$side_posts = self::get_section_posts( 'daily-narrative',
				'post',
				array(
					'posts_per_page' => 3,
					'tax_query'      => array(
						array(
							'taxonomy' => 'editorial_placement',
							'field'    => 'slug',
							'terms'    => 'daily_side',
						),
					),
				)
			);

			if ( empty( $lead_posts ) && empty( $side_posts ) ) {
				return array();
			}

			return array(
				'lead'  => ! empty( $lead_posts ) ? $lead_posts[0] : null,
				'items' => $side_posts,
			);
		}

		/**
		 * تامین داده‌های سکشن اتاق وضعیت (Situation Room)
		 *
		 * @return array
		 */
		public static function get_situation_room_data() {
			return self::get_section_posts( 'situation-room',
				'situation_room',
				array(
					'posts_per_page' => 4,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);
		}

		/** تیترهای عمومی و بدون پیوند اتاق وضعیت برای نمای گیت‌شده صفحه اصلی. */
		public static function get_situation_room_teasers() {
			return Revayat_Companion_Content_Service::get_situation_room_teasers( 4 );
		}

		/**
		 * تامین داده‌های سکشن رصد اخبار (News Monitoring)
		 *
		 * @return array
		 */
		public static function get_news_monitoring_data() {
			return self::get_section_posts( 'news-monitoring',
				'post',
				array(
					'posts_per_page' => 8,
					'tax_query'      => array(
						array(
							'taxonomy' => 'news_source',
							'operator' => 'EXISTS',
						),
					),
				)
			);
		}

		/**
		 * تامین داده‌های سکشن پرونده‌های ویژه (Special Dossiers)
		 *
		 * @return array
		 */
		public static function get_special_dossiers_data() {
			return self::get_section_posts( 'special-dossiers',
				'special_dossier',
				array(
					'posts_per_page' => 3,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);
		}

		/**
		 * تامین داده‌های سکشن دیده‌بان رسانه (Media Observatory)
		 *
		 * @return array
		 */
		public static function get_media_observatory_data() {
			return self::get_section_posts( 'media-observatory',
				'media_observatory',
				array(
					'posts_per_page' => 3,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);
		}

		/**
		 * تامین داده‌های سکشن شبکه تحلیلگران (Analysts Network)
		 *
		 * @return array
		 */
		public static function get_analysts_network_data() {
			$posts = self::get_section_posts( 'analysts-network',
				'analyst_post',
				array(
					'posts_per_page' => 8,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);

			$analysts = array();
			if ( class_exists( 'Revayat_Companion_Analyst_Ratings' ) ) {
				$people_args = self::section_query_args( 'analysts-network', 'person', array( 'posts_per_page' => 5 ) );
				$ranked_people = isset( $people_args['post__in'] ) && array( 0 ) === $people_args['post__in'] ? array() : Revayat_Companion_Analyst_Ratings::get_leaderboard( $people_args['posts_per_page'] ?? 5 );
				foreach ( $ranked_people as $person ) {
					$item = Revayat_Companion_Content_Service::normalize_post( $person );
					if ( $item ) {
						$analysts[] = $item;
					}
				}
			} else {
				$analysts = self::get_section_posts( 'analysts-network', 'person', array( 'posts_per_page' => 5 ) );
			}

			if ( empty( $posts ) && empty( $analysts ) ) {
				return array();
			}

			return array(
				'posts'    => $posts,
				'analysts' => $analysts,
			);
		}

		/**
		 * تامین داده‌های سکشن چندرسانه‌ای (Multimedia)
		 *
		 * @return array
		 */
		public static function get_multimedia_data() {
			return self::get_section_posts( 'multimedia',
				'multimedia',
				array(
					'posts_per_page' => 8,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);
		}
	}
}

/**
 * کلاس نما / آداپتور رسمی Revayat_Data_Service جهت تطابق ۱۰۰٪ با قرارداد provider-contract.md
 */
if ( ! class_exists( 'Revayat_Data_Service' ) ) {

	/**
	 * Facade عمومی لایه داده افزونه مکمل روایت ایران
	 */
	class Revayat_Data_Service {

		public static function get_hero_data() {
			return Revayat_Companion_Homepage_Provider::get_hero_data();
		}

		public static function get_daily_narrative_data() {
			return Revayat_Companion_Homepage_Provider::get_daily_narrative_data();
		}

		public static function get_situation_room_data() {
			return Revayat_Companion_Homepage_Provider::get_situation_room_data();
		}

		public static function get_situation_room_teasers() {
			return Revayat_Companion_Homepage_Provider::get_situation_room_teasers();
		}

		public static function get_news_monitoring_data() {
			return Revayat_Companion_Homepage_Provider::get_news_monitoring_data();
		}

		public static function get_special_dossiers_data() {
			return Revayat_Companion_Homepage_Provider::get_special_dossiers_data();
		}

		public static function get_media_observatory_data() {
			return Revayat_Companion_Homepage_Provider::get_media_observatory_data();
		}

		public static function get_analysts_network_data() {
			return Revayat_Companion_Homepage_Provider::get_analysts_network_data();
		}

		public static function get_multimedia_data() {
			return Revayat_Companion_Homepage_Provider::get_multimedia_data();
		}

		public static function get_single_data( $post = null ) {
			return Revayat_Companion_Content_Service::normalize_post( $post ?: get_the_ID() );
		}

		public static function get_related_posts( $post_id = 0, $count = 3 ) {
			return Revayat_Companion_Content_Service::get_related_posts( $post_id ?: get_the_ID(), $count );
		}

		public static function get_archive_data( $post_type, $args = array() ) {
			return Revayat_Companion_Content_Service::get_archive_data( $post_type, $args );
		}

		public static function get_user_clearance( $user = null ) {
			return Revayat_Companion_Content_Service::get_user_clearance( $user );
		}

		public static function can_access_situation_room( $user = null ) {
			return Revayat_Companion_Content_Service::can_access_situation_room( $user );
		}

		public static function evaluate_bulletin_access( $post, $user = null ) {
			return Revayat_Companion_Content_Service::evaluate_bulletin_access( $post, $user );
		}

		public static function get_observatory_badge_options(): array {
			return Revayat_Companion_Content_Service::get_observatory_badge_options();
		}

		public static function get_analyst_field_options(): array {
			return Revayat_Companion_Content_Service::get_analyst_field_options();
		}

		public static function get_media_format_options(): array {
			return Revayat_Companion_Content_Service::get_media_format_options();
		}

		public static function get_person_field_options(): array {
			return Revayat_Companion_Content_Service::get_person_field_options();
		}

		public static function get_analyst_vote_summary( $post_id ): array {
			return Revayat_Companion_Analyst_Ratings::get_summary( $post_id );
		}

		public static function get_analyst_leaderboard( $limit = 5 ): array {
			$items = array();
			foreach ( Revayat_Companion_Analyst_Ratings::get_leaderboard( $limit ) as $person ) {
				$normalized = Revayat_Companion_Content_Service::normalize_post( $person );
				if ( $normalized ) {
					$items[] = $normalized;
				}
			}
			return $items;
		}

		public static function get_person_posts( $person_id, $page = 1 ): array {
			return Revayat_Companion_Content_Service::get_person_posts( $person_id, $page );
		}

		public static function get_person_avatar_url( $person_id ): string {
			return Revayat_Companion_Profile_Avatar::person_url( $person_id );
		}

		public static function get_person_contributor_slug( $person_id ): string {
			$term = Revayat_Companion_Person_Identity::get_term_for_person( $person_id );
			return $term instanceof WP_Term ? (string) $term->slug : '';
		}
	}
}
