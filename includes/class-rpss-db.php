<?php
/**
 * مدیریت جدول‌های دیتابیس برای ثبت اجراها (runs) و لاگ تغییرات (log)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RPSS_DB {

	public static function table_runs() {
		global $wpdb;
		return $wpdb->prefix . 'rpss_runs';
	}

	public static function table_log() {
		global $wpdb;
		return $wpdb->prefix . 'rpss_log';
	}

	/**
	 * ساخت جدول‌ها در زمان فعال‌سازی پلاگین
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$runs            = self::table_runs();
		$log             = self::table_log();

		$sql = "CREATE TABLE {$runs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			started_at DATETIME NOT NULL,
			finished_at DATETIME NULL,
			trigger_type VARCHAR(20) NOT NULL DEFAULT 'cron',
			total_items INT UNSIGNED NOT NULL DEFAULT 0,
			matched INT UNSIGNED NOT NULL DEFAULT 0,
			updated_count INT UNSIGNED NOT NULL DEFAULT 0,
			skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
			error_count INT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'running',
			message TEXT NULL,
			PRIMARY KEY  (id)
		) {$charset_collate};

		CREATE TABLE {$log} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			run_id BIGINT UNSIGNED NOT NULL,
			sku VARCHAR(100) NOT NULL,
			product_id BIGINT UNSIGNED NOT NULL,
			product_type VARCHAR(20) NOT NULL,
			field VARCHAR(20) NOT NULL,
			old_value VARCHAR(50) NULL,
			new_value VARCHAR(50) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY run_id (run_id),
			KEY sku (sku)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * ثبت شروع یک اجرا و بازگرداندن run_id
	 */
	public function start_run( $trigger ) {
		global $wpdb;
		$wpdb->insert(
			self::table_runs(),
			array(
				'started_at'   => current_time( 'mysql' ),
				'trigger_type' => $trigger,
				'status'       => 'running',
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * بستن یک اجرا با نتیجه نهایی
	 */
	public function finish_run( $run_id, $result, $status, $message = '' ) {
		global $wpdb;
		$wpdb->update(
			self::table_runs(),
			array(
				'finished_at'   => current_time( 'mysql' ),
				'total_items'   => isset( $result['total_items'] ) ? (int) $result['total_items'] : 0,
				'matched'       => isset( $result['matched'] ) ? (int) $result['matched'] : 0,
				'updated_count' => isset( $result['updated'] ) ? (int) $result['updated'] : 0,
				'skipped_count' => isset( $result['skipped'] ) ? (int) $result['skipped'] : 0,
				'error_count'   => isset( $result['errors'] ) ? (int) $result['errors'] : 0,
				'status'        => $status,
				'message'       => $message,
			),
			array( 'id' => $run_id )
		);
	}

	/**
	 * ثبت یک سطر تغییر (قیمت یا موجودی) برای یک محصول
	 */
	public function add_log( $run_id, $sku, $product_id, $product_type, $field, $old_value, $new_value ) {
		global $wpdb;
		$wpdb->insert(
			self::table_log(),
			array(
				'run_id'       => $run_id,
				'sku'          => $sku,
				'product_id'   => $product_id,
				'product_type' => $product_type,
				'field'        => $field,
				'old_value'    => $old_value,
				'new_value'    => $new_value,
				'created_at'   => current_time( 'mysql' ),
			)
		);
	}

	public static function get_runs( $limit = 20, $offset = 0 ) {
		global $wpdb;
		$table = self::table_runs();
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset )
		);
	}

	public static function count_runs() {
		global $wpdb;
		$table = self::table_runs();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	public static function get_run( $run_id ) {
		global $wpdb;
		$table = self::table_runs();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $run_id ) );
	}

	public static function get_logs_for_run( $run_id, $limit = 500 ) {
		global $wpdb;
		$table = self::table_log();
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %d ORDER BY id ASC LIMIT %d", $run_id, $limit )
		);
	}

	public static function get_last_run() {
		global $wpdb;
		$table = self::table_runs();
		return $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 1" );
	}
}
