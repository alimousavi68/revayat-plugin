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

		/**
		 * تامین داده‌های سکشن ویترین اصلی (Hero)
		 *
		 * @return array ساختار [lead, side] یا آرایه خالی در صورت عدم وجود داده.
		 */
		public static function get_hero_data() {
			// ۱. واکشی تیتر یک (Lead)
			$lead_posts = Revayat_Companion_Content_Service::get_posts_by_type(
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
			$side_posts = Revayat_Companion_Content_Service::get_posts_by_type(
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
			$lead_posts = Revayat_Companion_Content_Service::get_posts_by_type(
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

			$side_posts = Revayat_Companion_Content_Service::get_posts_by_type(
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
			return Revayat_Companion_Content_Service::get_posts_by_type(
				'situation_room',
				array(
					'posts_per_page' => 4,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);
		}

		/**
		 * تامین داده‌های سکشن رصد اخبار (News Monitoring)
		 *
		 * @return array
		 */
		public static function get_news_monitoring_data() {
			return Revayat_Companion_Content_Service::get_posts_by_type(
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
			return Revayat_Companion_Content_Service::get_posts_by_type(
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
			return Revayat_Companion_Content_Service::get_posts_by_type(
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
			$posts = Revayat_Companion_Content_Service::get_posts_by_type(
				'analyst_post',
				array(
					'posts_per_page' => 8,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);

			$analysts = Revayat_Companion_Content_Service::get_posts_by_type(
				'person',
				array(
					'posts_per_page' => 5,
					'orderby'        => 'date',
					'order'          => 'ASC',
				)
			);

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
			return Revayat_Companion_Content_Service::get_posts_by_type(
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
	}
}
