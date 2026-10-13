<?php
/**
 * صفحات ادمین: تنظیمات، اجرای دستی همگام‌سازی، و نمایش لاگ تغییرات
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RPSS_Admin {

	const CAP = 'manage_woocommerce';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_rpss_save_settings', array( $this, 'save_settings' ) );
		add_action( 'wp_ajax_rpss_manual_run', array( $this, 'ajax_manual_run' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_status' ), 90 );
	}

	public function enqueue_assets( $hook ) {
		if ( strpos( $hook, 'rpss' ) === false ) {
			return;
		}
		wp_enqueue_style( 'rpss-admin', RPSS_URL . 'assets/admin.css', array(), RPSS_VERSION );
		wp_enqueue_script( 'rpss-admin', RPSS_URL . 'assets/admin.js', array( 'jquery' ), RPSS_VERSION, true );
		wp_localize_script(
			'rpss-admin',
			'RPSS',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'rpss_manual_run' ),
				'i18n'    => array(
					'running' => __( 'در حال اجرا... ممکن است چند دقیقه طول بکشد.', 'rpss' ),
					'error'   => __( 'خطایی رخ داد. لاگ‌ها را بررسی کنید.', 'rpss' ),
				),
			)
		);
	}

	public function add_menu() {
		add_menu_page(
			__( 'بروزرسانی کالا', 'rpss' ),
			__( 'بروزرسانی کالا', 'rpss' ),
			self::CAP,
			'rpss-settings',
			array( $this, 'render_settings_page' ),
			'dashicons-update'
		);

		add_submenu_page(
			'rpss-settings',
			__( 'تنظیمات', 'rpss' ),
			__( 'تنظیمات', 'rpss' ),
			self::CAP,
			'rpss-settings',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'rpss-settings',
			__( 'لاگ اجراها', 'rpss' ),
			__( 'لاگ اجراها', 'rpss' ),
			self::CAP,
			'rpss-logs',
			array( $this, 'render_logs_page' )
		);
	}

	public function save_settings() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'rpss' ) );
		}
		check_admin_referer( 'rpss_save_settings' );

		$path    = isset( $_POST['rpss_json_path'] ) ? sanitize_text_field( wp_unslash( $_POST['rpss_json_path'] ) ) : '';
		$minutes = isset( $_POST['rpss_cron_minutes'] ) ? max( 5, (int) $_POST['rpss_cron_minutes'] ) : 60;
		$enabled = isset( $_POST['rpss_enabled'] ) ? '1' : '0';
		$divisor = isset( $_POST['rpss_price_divisor'] ) ? (float) $_POST['rpss_price_divisor'] : 10;
		if ( $divisor <= 0 ) {
			$divisor = 1;
		}

		update_option( 'rpss_json_path', $path );
		update_option( 'rpss_cron_minutes', $minutes );
		update_option( 'rpss_enabled', $enabled );
		update_option( 'rpss_price_divisor', $divisor );
		delete_transient( 'rpss_json_file_time' );

		rpss_reschedule_cron();

		$notice = 'saved';
		if ( '' !== $path && ! is_readable( $path ) ) {
			$notice = 'saved_unreadable';
		}

		wp_safe_redirect( admin_url( 'admin.php?page=rpss-settings&rpss_notice=' . $notice ) );
		exit;
	}

	public function add_admin_bar_status( $wp_admin_bar ) {
		if ( ! is_admin_bar_showing() || ! current_user_can( self::CAP ) ) {
			return;
		}

		$status = $this->get_sync_status();
		$color  = 'ok' === $status['state'] ? '#00a32a' : '#d63638';
		$title  = sprintf(
			'<span class="ab-icon" style="background:%1$s;border-radius:50%%;width:10px;height:10px;margin-top:11px;"></span><span class="ab-label">%2$s</span>',
			esc_attr( $color ),
			esc_html( $status['label'] )
		);

		$wp_admin_bar->add_node(
			array(
				'id'    => 'rpss-status',
				'title' => $title,
				'href'  => admin_url( 'admin.php?page=rpss-settings' ),
				'meta'  => array(
					'title' => $status['message'],
				),
			)
		);
	}

	private function get_sync_status() {
		$time_summary = $this->get_time_summary();

		if ( '1' !== get_option( 'rpss_enabled', '1' ) ) {
			return array(
				'state'   => 'error',
				'label'   => __( 'بروزرسانی کالا: خاموش', 'rpss' ),
				'message' => __( 'اجرای زمان‌بندی‌شده غیرفعال است.', 'rpss' ) . ' ' . $time_summary,
			);
		}

		if ( get_transient( 'rpss_running' ) ) {
			return array(
				'state'   => 'ok',
				'label'   => __( 'بروزرسانی کالا: در حال اجرا', 'rpss' ),
				'message' => __( 'همگام‌سازی اکنون در حال اجراست.', 'rpss' ) . ' ' . $time_summary,
			);
		}

		$next_run = wp_next_scheduled( RPSS_CRON_HOOK );
		if ( false === $next_run ) {
			return array(
				'state'   => 'error',
				'label'   => __( 'بروزرسانی کالا: کران قطع است', 'rpss' ),
				'message' => __( 'رویداد زمان‌بندی‌شده پیدا نشد. نگهبان پلاگین آن را دوباره ثبت می‌کند.', 'rpss' ) . ' ' . $time_summary,
			);
		}

		$last_status = get_option( 'rpss_last_status', '' );
		if ( 'failed' === $last_status ) {
			return array(
				'state'   => 'error',
				'label'   => __( 'بروزرسانی کالا: خطا', 'rpss' ),
				'message' => __( 'آخرین اجرای همگام‌سازی ناموفق بوده است. لاگ‌ها را بررسی کنید.', 'rpss' ) . ' ' . $time_summary,
			);
		}

		$minutes          = max( 5, (int) get_option( 'rpss_cron_minutes', 60 ) );
		$last_finished_at = (int) get_option( 'rpss_last_finished_at', 0 );
		$max_silence      = max( 20 * MINUTE_IN_SECONDS, $minutes * 3 * MINUTE_IN_SECONDS );

		if ( $last_finished_at && ( time() - $last_finished_at ) > $max_silence ) {
			return array(
				'state'   => 'error',
				'label'   => __( 'بروزرسانی کالا: دیرکرد', 'rpss' ),
				'message' => __( 'از آخرین اجرای موفق بیشتر از حد انتظار گذشته است.', 'rpss' ) . ' ' . $time_summary,
			);
		}

		return array(
			'state'   => 'ok',
			'label'   => __( 'بروزرسانی کالا: فعال', 'rpss' ),
			'message' => sprintf(
				/* translators: %s: human readable time difference until next run. */
				__( 'اجرای بعدی حدود %s دیگر انجام می‌شود.', 'rpss' ),
				human_time_diff( time(), $next_run )
			) . ' ' . $time_summary,
		);
	}

	private function get_time_summary() {
		$file_time = $this->get_json_file_time();
		$sync_time = (int) get_option( 'rpss_last_success_finished_at', 0 );

		return sprintf(
			/* translators: 1: JSON file time, 2: last successful sync time. */
			__( 'فایل: %1$s | آخرین بروزرسانی موفق: %2$s', 'rpss' ),
			$file_time ? $this->format_timestamp( $file_time ) : __( 'نامشخص', 'rpss' ),
			$sync_time ? $this->format_timestamp( $sync_time ) : __( 'هنوز انجام نشده', 'rpss' )
		);
	}

	private function get_json_file_time() {
		$cached = get_transient( 'rpss_json_file_time' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$path = trim( (string) get_option( 'rpss_json_path', '' ) );
		if ( '' === $path || ! is_readable( $path ) ) {
			set_transient( 'rpss_json_file_time', 0, MINUTE_IN_SECONDS );
			return 0;
		}

		$time = filemtime( $path );
		$time = false === $time ? 0 : (int) $time;
		set_transient( 'rpss_json_file_time', $time, MINUTE_IN_SECONDS );

		return $time;
	}

	private function format_timestamp( $timestamp ) {
		if ( function_exists( 'wp_date' ) ) {
			return wp_date( 'Y/m/d H:i:s', (int) $timestamp );
		}

		return date_i18n( 'Y/m/d H:i:s', (int) $timestamp );
	}

	public function ajax_manual_run() {
		check_ajax_referer( 'rpss_manual_run', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'دسترسی غیرمجاز.', 'rpss' ) ) );
		}

		$outcome = RPSS_Core::run( 'manual' );

		if ( ! empty( $outcome['success'] ) ) {
			wp_send_json_success( $outcome['result'] );
		}

		wp_send_json_error( array( 'message' => isset( $outcome['message'] ) ? $outcome['message'] : __( 'خطای نامشخص', 'rpss' ) ) );
	}

	public function render_settings_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$path    = get_option( 'rpss_json_path', '' );
		$minutes = (int) get_option( 'rpss_cron_minutes', 60 );
		$enabled = get_option( 'rpss_enabled', '1' );
		$divisor = get_option( 'rpss_price_divisor', 10 );
		$last    = RPSS_DB::get_last_run();
		$file_time = $this->get_json_file_time();
		$last_started_at = (int) get_option( 'rpss_last_started_at', 0 );
		$last_success_finished_at = (int) get_option( 'rpss_last_success_finished_at', 0 );
		$next_run = wp_next_scheduled( RPSS_CRON_HOOK );

		$notice = isset( $_GET['rpss_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['rpss_notice'] ) ) : '';
		?>
		<div class="wrap rpss-wrap">
			<h1><?php esc_html_e( 'بروزرسانی کالا با نرم‌افزار حسابداری', 'rpss' ); ?></h1>

			<div class="notice notice-info inline">
				<p>
					<strong><?php esc_html_e( 'اتصال فعلی از طریق فایل JSON انجام می‌شود.', 'rpss' ); ?></strong>
					<?php esc_html_e( 'امکان اتصال مستقیم از طریق API در حال توسعه است. برای پیاده‌سازی اختصاصی متناسب با نرم‌افزار حسابداری خود می‌توانید پیام بدهید:', 'rpss' ); ?>
					<a href="mailto:shadman.nima@gmail.com">shadman.nima@gmail.com</a>
				</p>
				<p>
					<?php esc_html_e( 'توسعه‌دهنده:', 'rpss' ); ?>
					<a href="https://nimashadman.ir" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'نیما شادمان', 'rpss' ); ?></a>
				</p>
			</div>

			<?php if ( 'saved' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'تنظیمات ذخیره شد.', 'rpss' ); ?></p></div>
			<?php elseif ( 'saved_unreadable' === $notice ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'تنظیمات ذخیره شد، اما فایل در مسیر وارد شده در حال حاضر قابل خواندن نیست.', 'rpss' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="rpss_save_settings" />
				<?php wp_nonce_field( 'rpss_save_settings' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="rpss_json_path"><?php esc_html_e( 'مسیر فایل JSON', 'rpss' ); ?></label></th>
						<td>
							<input type="text" id="rpss_json_path" name="rpss_json_path" class="large-text code" value="<?php echo esc_attr( $path ); ?>" />
							<p class="description"><?php esc_html_e( 'مسیر کامل روی سرور، مثلاً: /home/h353344/domains/pharmafit.ir/RainDataSenderExport.json', 'rpss' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rpss_price_divisor"><?php esc_html_e( 'ضریب تبدیل قیمت', 'rpss' ); ?></label></th>
						<td>
							<input type="number" step="0.01" min="0.01" id="rpss_price_divisor" name="rpss_price_divisor" value="<?php echo esc_attr( $divisor ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'قیمت فایل JSON تقسیم بر این عدد می‌شود. چون فایل باران به ریال است و سایت به تومان، مقدار پیش‌فرض ۱۰ است.', 'rpss' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rpss_enabled"><?php esc_html_e( 'اجرای زمان‌بندی‌شده (Cron)', 'rpss' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" id="rpss_enabled" name="rpss_enabled" value="1" <?php checked( '1', $enabled ); ?> />
								<?php esc_html_e( 'فعال باشد', 'rpss' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rpss_cron_minutes"><?php esc_html_e( 'فاصله اجرا (دقیقه)', 'rpss' ); ?></label></th>
						<td>
							<input type="number" min="5" id="rpss_cron_minutes" name="rpss_cron_minutes" value="<?php echo esc_attr( $minutes ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'هر چند دقیقه یک بار همگام‌سازی به‌صورت خودکار اجرا شود (حداقل ۵ دقیقه).', 'rpss' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'ذخیره تنظیمات', 'rpss' ) ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'وضعیت زمان‌ها', 'rpss' ); ?></h2>
			<table class="widefat striped" style="max-width:700px;">
				<tbody>
					<tr>
						<td><?php esc_html_e( 'آخرین زمان آپلود/تغییر فایل روی FTP', 'rpss' ); ?></td>
						<td><?php echo esc_html( $file_time ? $this->format_timestamp( $file_time ) : __( 'فایل پیدا نشد یا قابل خواندن نیست', 'rpss' ) ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'آخرین شروع همگام‌سازی', 'rpss' ); ?></td>
						<td><?php echo esc_html( $last_started_at ? $this->format_timestamp( $last_started_at ) : __( 'هنوز انجام نشده', 'rpss' ) ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'آخرین بروزرسانی موفق', 'rpss' ); ?></td>
						<td><?php echo esc_html( $last_success_finished_at ? $this->format_timestamp( $last_success_finished_at ) : __( 'هنوز انجام نشده', 'rpss' ) ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'اجرای بعدی کران', 'rpss' ); ?></td>
						<td><?php echo esc_html( $next_run ? $this->format_timestamp( $next_run ) : __( 'زمان‌بندی ثبت نشده است', 'rpss' ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<hr />

			<h2><?php esc_html_e( 'اجرای دستی', 'rpss' ); ?></h2>
			<p><?php esc_html_e( 'برای کاتالوگ‌های بزرگ، اجرای دستی ممکن است چند دقیقه طول بکشد و صفحه را تا پایان باز نگه دارید.', 'rpss' ); ?></p>
			<button type="button" class="button button-primary" id="rpss-run-now"><?php esc_html_e( 'اجرای همگام‌سازی هم‌اکنون', 'rpss' ); ?></button>
			<span id="rpss-run-status" style="margin-right:10px;"></span>
			<div id="rpss-run-result"></div>

			<?php if ( $last ) : ?>
				<hr />
				<h2><?php esc_html_e( 'آخرین اجرا', 'rpss' ); ?></h2>
				<table class="widefat striped" style="max-width:700px;">
					<tbody>
						<tr><td><?php esc_html_e( 'زمان شروع', 'rpss' ); ?></td><td><?php echo esc_html( $last->started_at ); ?></td></tr>
						<tr><td><?php esc_html_e( 'نوع اجرا', 'rpss' ); ?></td><td><?php echo esc_html( 'manual' === $last->trigger_type ? __( 'دستی', 'rpss' ) : __( 'کران', 'rpss' ) ); ?></td></tr>
						<tr><td><?php esc_html_e( 'وضعیت', 'rpss' ); ?></td><td><?php echo esc_html( $last->status ); ?></td></tr>
						<tr><td><?php esc_html_e( 'تعداد آیتم در فایل', 'rpss' ); ?></td><td><?php echo esc_html( $last->total_items ); ?></td></tr>
						<tr><td><?php esc_html_e( 'مچ‌شده با محصولات', 'rpss' ); ?></td><td><?php echo esc_html( $last->matched ); ?></td></tr>
						<tr><td><?php esc_html_e( 'بروزرسانی‌شده', 'rpss' ); ?></td><td><?php echo esc_html( $last->updated_count ); ?></td></tr>
						<tr><td><?php esc_html_e( 'بدون تغییر', 'rpss' ); ?></td><td><?php echo esc_html( $last->skipped_count ); ?></td></tr>
						<tr><td><?php esc_html_e( 'خطا', 'rpss' ); ?></td><td><?php echo esc_html( $last->error_count ); ?></td></tr>
						<?php if ( $last->message ) : ?>
							<tr><td><?php esc_html_e( 'پیام', 'rpss' ); ?></td><td><?php echo esc_html( $last->message ); ?></td></tr>
						<?php endif; ?>
					</tbody>
				</table>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=rpss-logs&run_id=' . $last->id ) ); ?>"><?php esc_html_e( 'مشاهده جزئیات تغییرات این اجرا', 'rpss' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}

	public function render_logs_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$run_id = isset( $_GET['run_id'] ) ? (int) $_GET['run_id'] : 0;

		if ( $run_id ) {
			$this->render_single_run_log( $run_id );
			return;
		}

		$paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$per_page = 20;
		$offset   = ( $paged - 1 ) * $per_page;
		$runs     = RPSS_DB::get_runs( $per_page, $offset );
		$total    = RPSS_DB::count_runs();
		$pages    = (int) ceil( $total / $per_page );
		?>
		<div class="wrap rpss-wrap">
			<h1><?php esc_html_e( 'لاگ اجراهای بروزرسانی کالا', 'rpss' ); ?></h1>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'شناسه', 'rpss' ); ?></th>
						<th><?php esc_html_e( 'شروع', 'rpss' ); ?></th>
						<th><?php esc_html_e( 'نوع', 'rpss' ); ?></th>
						<th><?php esc_html_e( 'وضعیت', 'rpss' ); ?></th>
						<th><?php esc_html_e( 'کل آیتم', 'rpss' ); ?></th>
						<th><?php esc_html_e( 'مچ', 'rpss' ); ?></th>
						<th><?php esc_html_e( 'بروزرسانی', 'rpss' ); ?></th>
						<th><?php esc_html_e( 'خطا', 'rpss' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $runs ) ) : ?>
						<tr><td colspan="9"><?php esc_html_e( 'هنوز هیچ اجرایی ثبت نشده است.', 'rpss' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $runs as $run ) : ?>
							<tr>
								<td><?php echo esc_html( $run->id ); ?></td>
								<td><?php echo esc_html( $run->started_at ); ?></td>
								<td><?php echo esc_html( 'manual' === $run->trigger_type ? __( 'دستی', 'rpss' ) : __( 'کران', 'rpss' ) ); ?></td>
								<td><?php echo esc_html( $run->status ); ?></td>
								<td><?php echo esc_html( $run->total_items ); ?></td>
								<td><?php echo esc_html( $run->matched ); ?></td>
								<td><?php echo esc_html( $run->updated_count ); ?></td>
								<td><?php echo esc_html( $run->error_count ); ?></td>
								<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=rpss-logs&run_id=' . $run->id ) ); ?>"><?php esc_html_e( 'جزئیات', 'rpss' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => $paged,
							'total'     => $pages,
						)
					);
					?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_single_run_log( $run_id ) {
		$run  = RPSS_DB::get_run( $run_id );
		$logs = RPSS_DB::get_logs_for_run( $run_id );
		?>
		<div class="wrap rpss-wrap">
			<h1><?php printf( esc_html__( 'جزئیات اجرای #%d', 'rpss' ), $run_id ); ?></h1>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=rpss-logs' ) ); ?>">&right_arrow; <?php esc_html_e( 'بازگشت به لیست اجراها', 'rpss' ); ?></a></p>

			<?php if ( ! $run ) : ?>
				<p><?php esc_html_e( 'اجرای مورد نظر یافت نشد.', 'rpss' ); ?></p>
				<?php return; ?>
			<?php endif; ?>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'SKU / بارکد', 'rpss' ); ?></th>
						<th><?php esc_html_e( 'محصول', 'rpss' ); ?></th>
						<th><?php esc_html_e( 'نوع', 'rpss' ); ?></th>
						<th><?php esc_html_e( 'فیلد', 'rpss' ); ?></th>
						<th><?php esc_html_e( 'مقدار قبلی', 'rpss' ); ?></th>
						<th><?php esc_html_e( 'مقدار جدید', 'rpss' ); ?></th>
						<th><?php esc_html_e( 'زمان', 'rpss' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $logs ) ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'هیچ تغییری در این اجرا ثبت نشد.', 'rpss' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<td><?php echo esc_html( $log->sku ); ?></td>
								<td>
									<a href="<?php echo esc_url( get_edit_post_link( $log->product_id ) ); ?>" target="_blank">
										#<?php echo esc_html( $log->product_id ); ?>
									</a>
								</td>
								<td><?php echo esc_html( 'product_variation' === $log->product_type ? __( 'تنوع', 'rpss' ) : __( 'محصول', 'rpss' ) ); ?></td>
								<td><?php echo esc_html( 'price' === $log->field ? __( 'قیمت', 'rpss' ) : __( 'موجودی', 'rpss' ) ); ?></td>
								<td><?php echo esc_html( $log->old_value ); ?></td>
								<td><?php echo esc_html( $log->new_value ); ?></td>
								<td><?php echo esc_html( $log->created_at ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
