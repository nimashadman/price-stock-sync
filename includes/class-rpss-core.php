<?php
/**
 * منطق اصلی همگام‌سازی:
 * خواندن فایل JSON باران، ساخت map بر اساس Barcode،
 * یافتن محصولات/تنوع‌های ووکامرس بر اساس SKU منطبق با Barcode،
 * و بروزرسانی فقط در صورت مغایرت قیمت یا موجودی.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RPSS_Core {

	/**
	 * نقطه ورود اجرای همگام‌سازی
	 *
	 * @param string $trigger 'cron' یا 'manual'
	 * @return array نتیجه شامل run_id و خلاصه آمار یا خطا
	 */
	public static function run( $trigger = 'cron' ) {
		// اگر اجرای دیگری از قبل در حال انجام است، اجرای موازی را متوقف کن
		if ( get_transient( 'rpss_running' ) ) {
			return array(
				'success' => false,
				'message' => __( 'یک اجرای همگام‌سازی دیگر در حال انجام است.', 'rpss' ),
			);
		}
		$lock_ttl = max( 30 * MINUTE_IN_SECONDS, ( (int) get_option( 'rpss_cron_minutes', 60 ) * 3 * MINUTE_IN_SECONDS ) );
		set_transient( 'rpss_running', time(), $lock_ttl );
		delete_transient( 'rpss_json_file_time' );
		update_option( 'rpss_last_started_at', time(), false );

		@set_time_limit( 0 );
		@ini_set( 'memory_limit', '512M' );

		$db     = new RPSS_DB();
		$run_id = $db->start_run( $trigger );

		$result = array(
			'total_items' => 0,
			'matched'     => 0,
			'updated'     => 0,
			'skipped'     => 0,
			'errors'      => 0,
		);

		try {
			$path = trim( (string) get_option( 'rpss_json_path', '' ) );

			if ( '' === $path ) {
				throw new Exception( __( 'مسیر فایل JSON تنظیم نشده است.', 'rpss' ) );
			}
			if ( ! is_readable( $path ) ) {
				throw new Exception(
					sprintf( __( 'فایل در مسیر زیر یافت نشد یا قابل خواندن نیست: %s', 'rpss' ), $path )
				);
			}

			$raw = file_get_contents( $path );
			if ( false === $raw ) {
				throw new Exception( __( 'خطا در خواندن محتوای فایل.', 'rpss' ) );
			}

			$items = json_decode( $raw, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $items ) ) {
				throw new Exception(
					sprintf( __( 'خطا در parse کردن JSON: %s', 'rpss' ), json_last_error_msg() )
				);
			}

			$result['total_items'] = count( $items );

			// قیمت در فایل باران به ریال است؛ سایت به تومان کار می‌کند.
			// ضریب تبدیل پیش‌فرض ۱۰ است (ریال / ۱۰ = تومان) و از تنظیمات قابل تغییر است.
			$price_divisor = (float) get_option( 'rpss_price_divisor', 10 );
			if ( $price_divisor <= 0 ) {
				$price_divisor = 1;
			}

			// --- ساخت map بر اساس Barcode (کلید مچ با SKU ووکامرس) ---
			$map              = array();
			$duplicate_count  = 0;
			$empty_barcode    = 0;

			foreach ( $items as $item ) {
				$barcode = isset( $item['Barcode'] ) ? trim( (string) $item['Barcode'] ) : '';
				if ( '' === $barcode ) {
					$empty_barcode++;
					continue;
				}
				if ( isset( $map[ $barcode ] ) ) {
					$duplicate_count++;
				}

				$price = null;
				if ( isset( $item['Price'] ) && is_numeric( $item['Price'] ) ) {
					$price = ( (float) $item['Price'] ) / $price_divisor;
				}

				$map[ $barcode ] = array(
					'price' => $price,
					'stock' => isset( $item['StockQuantity'] ) && is_numeric( $item['StockQuantity'] ) ? (float) $item['StockQuantity'] : null,
				);
			}

			// --- یافتن همه محصولات/تنوع‌های ووکامرس که SKU دارند ---
			global $wpdb;
			$rows = $wpdb->get_results(
				"SELECT pm.post_id AS post_id, pm.meta_value AS sku, p.post_type AS post_type
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = '_sku'
				   AND pm.meta_value != ''
				   AND p.post_type IN ('product','product_variation')
				   AND p.post_status != 'trash'"
			);

			$chunks = array_chunk( $rows, 200 );

			foreach ( $chunks as $chunk ) {
				foreach ( $chunk as $row ) {
					$sku = trim( (string) $row->sku );
					if ( '' === $sku || ! isset( $map[ $sku ] ) ) {
						continue;
					}

					$result['matched']++;

					$outcome = self::update_product( (int) $row->post_id, $row->post_type, $sku, $map[ $sku ], $db, $run_id );

					if ( true === $outcome ) {
						$result['updated']++;
					} elseif ( 'error' === $outcome ) {
						$result['errors']++;
					} else {
						$result['skipped']++;
					}
				}
				// آزاد کردن کش آبجکت بین دسته‌ها برای کنترل مصرف حافظه روی کاتالوگ‌های بزرگ
				wp_cache_flush();
			}

			$messages = array();
			if ( $duplicate_count > 0 ) {
				$messages[] = sprintf( __( '%d بارکد تکراری در فایل پیدا شد (آخرین مقدار استفاده شد).', 'rpss' ), $duplicate_count );
			}
			if ( $empty_barcode > 0 ) {
				$messages[] = sprintf( __( '%d آیتم بدون Barcode نادیده گرفته شد.', 'rpss' ), $empty_barcode );
			}

			$db->finish_run( $run_id, $result, 'completed', implode( ' ', $messages ) );

			delete_transient( 'rpss_running' );
			update_option( 'rpss_last_finished_at', time(), false );
			update_option( 'rpss_last_success_finished_at', time(), false );
			update_option( 'rpss_last_status', 'completed', false );

			return array(
				'success' => true,
				'run_id'  => $run_id,
				'result'  => $result,
			);

		} catch ( Exception $e ) {
			$db->finish_run( $run_id, $result, 'failed', $e->getMessage() );
			delete_transient( 'rpss_running' );
			update_option( 'rpss_last_finished_at', time(), false );
			update_option( 'rpss_last_status', 'failed', false );

			return array(
				'success' => false,
				'run_id'  => $run_id,
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * بروزرسانی یک محصول یا تنوع بر اساس داده‌های مچ‌شده
	 *
	 * @return true|false|'error'
	 */
	private static function update_product( $post_id, $post_type, $sku, $data, $db, $run_id ) {
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return 'error';
		}

		$self_changed   = false;
		$parent_changed = false;

		// --- قیمت ---
		if ( null !== $data['price'] ) {
			$new_price     = wc_format_decimal( $data['price'] );
			$current_price = wc_format_decimal( $product->get_regular_price() );

			if ( $new_price !== $current_price ) {
				$product->set_regular_price( $new_price );
				$db->add_log( $run_id, $sku, $post_id, $post_type, 'price', $current_price, $new_price );
				$self_changed = true;
			}
		}

		// --- موجودی ---
		if ( null !== $data['stock'] ) {
			$stock_target = $product;

			// اگر تنوع، خودش موجودی را مدیریت نمی‌کند ولی محصول والد مدیریت می‌کند،
			// موجودی روی محصول والد بروزرسانی می‌شود.
			if ( 'product_variation' === $post_type && ! $product->get_manage_stock() ) {
				$parent_id = $product->get_parent_id();
				$parent    = $parent_id ? wc_get_product( $parent_id ) : false;
				if ( $parent && $parent->get_manage_stock() ) {
					$stock_target = $parent;
				}
			}

			if ( $stock_target->get_manage_stock() ) {
				$new_stock     = (int) round( $data['stock'] );
				$current_stock = (int) $stock_target->get_stock_quantity();

				if ( $new_stock !== $current_stock ) {
					$stock_target->set_stock_quantity( $new_stock );
					$stock_target->set_stock_status( $new_stock > 0 ? 'instock' : 'outofstock' );

					$db->add_log(
						$run_id,
						$sku,
						$stock_target->get_id(),
						$stock_target->get_id() === $product->get_id() ? $post_type : 'product',
						'stock',
						$current_stock,
						$new_stock
					);

					if ( $stock_target->get_id() === $product->get_id() ) {
						$self_changed = true;
					} else {
						$stock_target->save();
						$parent_changed = true;
					}
				}
			}
		}

		if ( $self_changed ) {
			$product->save();
		}

		return ( $self_changed || $parent_changed ) ? true : false;
	}
}
