<?php
/**
 * Plugin Name: بروزرسانی کالا با نرم‌افزار حسابداری
 * Plugin URI: https://github.com/nimashadman/price-stock-sync
 * Description: بروزرسانی خودکار قیمت و موجودی کالاهای ووکامرس از طریق فایل JSON نرم‌افزار حسابداری. اتصال API در حال توسعه است و برای پیاده‌سازی اختصاصی می‌توانید با توسعه‌دهنده تماس بگیرید.
 * Version: 1.0.6
 * Author: نیما شادمان
 * Author URI: https://nimashadman.ir
 * Text Domain: rpss
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RPSS_VERSION', '1.0.6' );
define( 'RPSS_FILE', __FILE__ );
define( 'RPSS_DIR', plugin_dir_path( __FILE__ ) );
define( 'RPSS_URL', plugin_dir_url( __FILE__ ) );
define( 'RPSS_CRON_HOOK', 'rpss_cron_hook' );
define( 'RPSS_CRON_WATCHDOG_OPTION', 'rpss_cron_watchdog_checked_at' );

require_once RPSS_DIR . 'includes/class-rpss-db.php';
require_once RPSS_DIR . 'includes/class-rpss-core.php';
require_once RPSS_DIR . 'includes/class-rpss-admin.php';

/**
 * فعال‌سازی پلاگین: ساخت جدول‌های دیتابیس و تنظیم کران اولیه
 */
function rpss_activate() {
	RPSS_DB::install();

	if ( false === get_option( 'rpss_json_path', false ) ) {
		update_option( 'rpss_json_path', '/home/h353344/domains/pharmafit.ir/RainDataSenderExport.json' );
	}
	if ( false === get_option( 'rpss_cron_minutes', false ) ) {
		update_option( 'rpss_cron_minutes', 60 );
	}
	if ( false === get_option( 'rpss_price_divisor', false ) ) {
		update_option( 'rpss_price_divisor', 10 );
	}
	if ( false === get_option( 'rpss_enabled', false ) ) {
		update_option( 'rpss_enabled', '1' );
	}

	rpss_reschedule_cron();
}
register_activation_hook( __FILE__, 'rpss_activate' );

/**
 * غیرفعال‌سازی: پاک کردن رویداد کران (جدول‌های لاگ حفظ می‌شوند)
 */
function rpss_deactivate() {
	wp_clear_scheduled_hook( RPSS_CRON_HOOK );
}
register_deactivation_hook( __FILE__, 'rpss_deactivate' );

/**
 * تعریف بازه زمانی سفارشی کران بر اساس تنظیمات ادمین
 */
add_filter(
	'cron_schedules',
	function ( $schedules ) {
		$minutes = (int) get_option( 'rpss_cron_minutes', 60 );
		if ( $minutes < 5 ) {
			$minutes = 5;
		}
		$schedules['rpss_custom'] = array(
			'interval' => $minutes * 60,
			'display'  => sprintf( __( 'هر %d دقیقه (بروزرسانی کالا)', 'rpss' ), $minutes ),
		);
		return $schedules;
	}
);

/**
 * تنظیم مجدد رویداد کران؛ هر بار تنظیمات ذخیره می‌شود این تابع صدا زده می‌شود
 */
function rpss_reschedule_cron() {
	wp_clear_scheduled_hook( RPSS_CRON_HOOK );
	if ( '1' === get_option( 'rpss_enabled', '1' ) ) {
		wp_schedule_event( time() + 60, 'rpss_custom', RPSS_CRON_HOOK );
	}
}

/**
 * نگهبان کران: اگر رویداد زمان‌بندی‌شده به هر دلیلی حذف شده باشد، دوباره ساخته می‌شود.
 */
function rpss_ensure_cron_scheduled( $force = false ) {
	if ( ! $force && ! is_admin() && ! wp_doing_cron() ) {
		return;
	}

	if ( '1' !== get_option( 'rpss_enabled', '1' ) ) {
		return;
	}

	$last_checked = (int) get_option( RPSS_CRON_WATCHDOG_OPTION, 0 );
	if ( ! $force && ( time() - $last_checked ) < 5 * MINUTE_IN_SECONDS ) {
		return;
	}

	update_option( RPSS_CRON_WATCHDOG_OPTION, time(), false );

	if ( false === wp_next_scheduled( RPSS_CRON_HOOK ) ) {
		wp_schedule_event( time() + 60, 'rpss_custom', RPSS_CRON_HOOK );
	}
}
add_action( 'admin_init', 'rpss_ensure_cron_scheduled' );

add_action(
	RPSS_CRON_HOOK,
	function () {
		rpss_ensure_cron_scheduled( true );
		RPSS_Core::run( 'cron' );
	}
);

/**
 * راه‌اندازی بخش ادمین فقط در صورت فعال بودن ووکامرس
 */
add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'پلاگین «بروزرسانی کالا با نرم‌افزار حسابداری» نیاز به فعال بودن ووکامرس دارد.', 'rpss' ) . '</p></div>';
				}
			);
			return;
		}
		new RPSS_Admin();
	}
);
