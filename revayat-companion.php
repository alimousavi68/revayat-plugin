<?php
/**
 * Plugin Name:       روایت ایران - افزونه مکمل (Revayat Iran Companion)
 * Plugin URI:        https://revayatiran.com
 * Description:       افزونه مکمل پایگاه تحلیلی «روایت ایران»؛ مدیریت زیرساخت داده، پست‌تایپ‌های سفارشی، تاکسونومی‌ها، فیلدهای متا و لایه سرویس.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            تیم توسعه روایت ایران
 * Author URI:        https://revayatiran.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       revayat-companion
 * Domain Path:       /languages
 * 
 * چرا این فایل لازم است؟
 * این فایل نقطه ورود (Entry Point) و فایل اصلی افزونه وردپرس است که توسط هسته وردپرس شناسایی و بارگذاری می‌شود.
 *
 * چه مسئولیتی دارد؟
 * - بررسی دسترسی مستقیم و امنیت محیط وردپرس.
 * - تعریف ثابت‌های پایه و سراسری افزونه (Version, Path, URL, Basename).
 * - ثبت هوک‌های فعال‌سازی (Activation) و غیرفعال‌سازی (Deactivation).
 * - لود فایل‌های وابستگی هسته (`class-loader.php` و `class-plugin.php`).
 * - راه‌اندازی و اجرای نمونه ارکستراتور افزونه در هوک `plugins_loaded`.
 *
 * ارتباط با معماری:
 * این فایل اسکلت و مرز بنیادین افزونه `revayat-companion` را تشکیل می‌دهد و مطابق اصل Separation of Concerns، به عنوان بستر مستقل داده‌ها و منطق تجاری در کنار پوسته عمل خواهد کرد.
 *
 * @package Revayat_Companion
 */

// جلوگیری از دسترسی مستقیم خارج از بستر وردپرس
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * تعریف ثابت‌های سراسری افزونه با پیشوند اختصاصی revayat_
 */
define( 'REVAYAT_COMPANION_VERSION', '1.0.0' );
define( 'REVAYAT_COMPANION_FILE', __FILE__ );
define( 'REVAYAT_COMPANION_PATH', plugin_dir_path( __FILE__ ) );
define( 'REVAYAT_COMPANION_URL', plugin_dir_url( __FILE__ ) );
define( 'REVAYAT_COMPANION_BASENAME', plugin_basename( __FILE__ ) );

/**
 * کدهای اجراشونده در زمان فعال‌سازی افزونه
 *
 * @return void
 */
function revayat_companion_activate() {
	require_once REVAYAT_COMPANION_PATH . 'includes/taxonomies/class-taxonomies.php';
	require_once REVAYAT_COMPANION_PATH . 'includes/post-types/class-post-types.php';
	require_once REVAYAT_COMPANION_PATH . 'includes/users/class-user-portal.php';

	$taxonomies = new Revayat_Companion_Taxonomies();
	$taxonomies->register();

	$post_types = new Revayat_Companion_Post_Types();
	$post_types->register();

	Revayat_Companion_User_Portal::register_roles();
	Revayat_Companion_User_Portal::ensure_pages();

	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'revayat_companion_activate' );

/**
 * کدهای اجراشونده در زمان غیرفعال‌سازی افزونه
 *
 * @return void
 */
function revayat_companion_deactivate() {
	// اسکلت اولیه: بدون تخریب داده‌ها یا حذف جداول
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'revayat_companion_deactivate' );

/**
 * بارگذاری فایل‌های زیرساختی هسته افزونه
 */
require_once REVAYAT_COMPANION_PATH . 'includes/class-loader.php';
require_once REVAYAT_COMPANION_PATH . 'includes/class-plugin.php';

/**
 * راه‌اندازی و اجرای چرخه حیات هسته افزونه
 *
 * @return void
 */
function revayat_companion_run() {
	if ( class_exists( 'Revayat_Companion_Plugin' ) ) {
		$plugin = Revayat_Companion_Plugin::instance();
		$plugin->run();
	}
}
add_action( 'plugins_loaded', 'revayat_companion_run' );
