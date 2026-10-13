jQuery(function ($) {
	$('#rpss-run-now').on('click', function () {
		var $btn = $(this);
		var $status = $('#rpss-run-status');
		var $result = $('#rpss-run-result');

		$btn.prop('disabled', true);
		$status.text(RPSS.i18n.running);
		$result.empty();

		$.post(RPSS.ajaxUrl, {
			action: 'rpss_manual_run',
			nonce: RPSS.nonce
		}).done(function (res) {
			$btn.prop('disabled', false);
			$status.text('');

			if (res.success) {
				var r = res.data;
				var html = '<div class="notice notice-success inline"><p>' +
					'کل آیتم‌ها: ' + r.total_items +
					' | مچ‌شده: ' + r.matched +
					' | بروزرسانی‌شده: ' + r.updated +
					' | بدون تغییر: ' + r.skipped +
					' | خطا: ' + r.errors +
					'</p></div>';
				$result.html(html);
				setTimeout(function () {
					window.location.reload();
				}, 1500);
			} else {
				$result.html('<div class="notice notice-error inline"><p>' + (res.data && res.data.message ? res.data.message : RPSS.i18n.error) + '</p></div>');
			}
		}).fail(function () {
			$btn.prop('disabled', false);
			$status.text('');
			$result.html('<div class="notice notice-error inline"><p>' + RPSS.i18n.error + '</p></div>');
		});
	});
});
