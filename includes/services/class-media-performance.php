<?php
/**
 * سیاست سبک و قابل‌حمل بهینه‌سازی رسانه‌های تازه.
 *
 * @package Revayat_Companion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Revayat_Companion_Media_Performance' ) ) {
	class Revayat_Companion_Media_Performance {

		/**
		 * تولید اندازه‌های مشتق JPEG به‌صورت WebP در صورت پشتیبانی سرور.
		 * PNG برای حفظ شفافیت و جلوگیری از افزایش حجم ناخواسته تغییر نمی‌کند.
		 *
		 * @param array $formats نگاشت MIME ورودی به خروجی.
		 * @return array
		 */
		public static function prefer_webp_for_jpeg( $formats ) {
			if ( function_exists( 'wp_image_editor_supports' ) && wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
				$formats['image/jpeg'] = 'image/webp';
			}

			return $formats;
		}
	}
}
