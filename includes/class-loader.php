<?php
/**
 * ثبت و ارکستراسیون هوک‌ها و فیلترهای وردپرس (Hook Loader)
 *
 * چرا این فایل لازم است؟
 * جهت جلوگیری از پراکندگی فراخوانی add_action و add_filter و مدیریت متمرکز اجرای هوک‌ها در چرخه حیات افزونه.
 *
 * چه مسئولیتی دارد؟
 * نگهداری صف هوک‌ها (Actions & Filters) و ثبت هماهنگ آن‌ها در هسته وردپرس در زمان فراخوانی متد run().
 *
 * ارتباط با معماری:
 * لایه زیرساختی Loader مطابق با الگوی استاندارد WordPress Plugin Architecture جهت تفکیک مدیریت رجیستری از منطق تجاری.
 *
 * @package Revayat_Companion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Revayat_Companion_Loader' ) ) {

	/**
	 * کلاس مدیریت و ثبت هوک‌های افزونه مکمل روایت ایران
	 */
	class Revayat_Companion_Loader {

		/**
		 * آرایه اکشن‌های ثبت‌شده در صف
		 *
		 * @var array
		 */
		protected $actions = array();

		/**
		 * آرایه فیلترهای ثبت‌شده در صف
		 *
		 * @var array
		 */
		protected $filters = array();

		/**
		 * افزودن اکشن جدید به صف هوک‌ها
		 *
		 * @param string $hook          نام اکشن وردپرس.
		 * @param object $component     نمونه کلاس یا شیء هدف.
		 * @param string $callback      نام متد کال‌بک.
		 * @param int    $priority      اولویت اجرا (پیش‌فرض ۱۰).
		 * @param int    $accepted_args تعداد آرگومان‌های دریافتی (پیش‌فرض ۱).
		 */
		public function add_action( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
			$this->actions[] = array(
				'hook'          => $hook,
				'component'     => $component,
				'callback'      => $callback,
				'priority'      => $priority,
				'accepted_args' => $accepted_args,
			);
		}

		/**
		 * افزودن فیلتر جدید به صف هوک‌ها
		 *
		 * @param string $hook          نام فیلتر وردپرس.
		 * @param object $component     نمونه کلاس یا شیء هدف.
		 * @param string $callback      نام متد کال‌بک.
		 * @param int    $priority      اولویت اجرا (پیش‌فرض ۱۰).
		 * @param int    $accepted_args تعداد آرگومان‌های دریافتی (پیش‌فرض ۱).
		 */
		public function add_filter( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
			$this->filters[] = array(
				'hook'          => $hook,
				'component'     => $component,
				'callback'      => $callback,
				'priority'      => $priority,
				'accepted_args' => $accepted_args,
			);
		}

		/**
		 * ثبت نهایی کلیه فیلترها و اکشن‌های صف در هسته وردپرس
		 */
		public function run() {
			foreach ( $this->filters as $hook ) {
				add_filter(
					$hook['hook'],
					array( $hook['component'], $hook['callback'] ),
					$hook['priority'],
					$hook['accepted_args']
				);
			}

			foreach ( $this->actions as $hook ) {
				add_action(
					$hook['hook'],
					array( $hook['component'], $hook['callback'] ),
					$hook['priority'],
					$hook['accepted_args']
				);
			}
		}
	}
}
