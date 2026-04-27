<?php
if (! defined('ABSPATH')) {
	exit;
}

class ZAYGL_IPG_Ajax
{
	const NONCE_ACTION = 'zaygl_ipg_ajax';
	const NOTES_OPTION = 'zaygl_ipg_notes_rows';

	public static function init(): void
	{
		add_action('wp_ajax_zaygl_ipg_table', array(__CLASS__, 'ajax_table'));
		add_action('wp_ajax_zaygl_ipg_export_csv', array(__CLASS__, 'export_csv'));
		add_action('wp_ajax_zaygl_ipg_export_print', array(__CLASS__, 'export_print'));

		add_action('wp_ajax_zaygl_ipg_notes_list', array(__CLASS__, 'notes_list'));
		add_action('wp_ajax_zaygl_ipg_notes_save', array(__CLASS__, 'notes_save'));
		add_action('wp_ajax_zaygl_ipg_notes_delete', array(__CLASS__, 'notes_delete'));

		add_action('wp_ajax_zaygl_ipg_bulk_action', array(__CLASS__, 'bulk_action'));

		add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin'));
	}

	public static function enqueue_admin($hook): void
	{
		if ('toplevel_page_dia-ip-guardian' !== $hook) {
			return;
		}

		$css_src  = ZAYGL_IPG_URL . 'assets/admin.css';
		$css_path = ZAYGL_IPG_PATH . 'assets/admin.css';
		$css_ver  = file_exists($css_path) ? filemtime($css_path) : (defined('ZAYGL_IPG_VERSION') ? ZAYGL_IPG_VERSION : '1.0.0');

		wp_enqueue_style('dia-ipg-admin', $css_src, array(), $css_ver);

		$js_src  = ZAYGL_IPG_URL . 'assets/admin.js';
		$js_path = ZAYGL_IPG_PATH . 'assets/admin.js';
		$js_ver  = file_exists($js_path) ? filemtime($js_path) : (defined('ZAYGL_IPG_VERSION') ? ZAYGL_IPG_VERSION : '1.0.0');

		wp_enqueue_script('dia-ipg-admin', $js_src, array(), $js_ver, true);

		wp_localize_script(
			'dia-ipg-admin',
			'ZAYGL_IPG_AJAX',
			array(
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'nonce'   => wp_create_nonce(self::NONCE_ACTION),
			)
		);
	}

	/* =========================================================
	 * Helpers
	 * ======================================================= */

	private static function post_text(string $key, string $default = ''): string
	{
		if (! isset($_POST[$key])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return $default;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$value = wp_unslash($_POST[$key]);

		return sanitize_text_field((string) $value);
	}

	private static function post_key(string $key, string $default = ''): string
	{
		if (! isset($_POST[$key])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return $default;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$value = wp_unslash($_POST[$key]);

		return sanitize_key((string) $value);
	}
	private static function post_int(string $key, int $default = 0): int
	{
		if (! isset($_POST[$key])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return $default;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$value = wp_unslash($_POST[$key]);

		if ($value === '') {
			return $default;
		}

		return absint($value);
	}
	private static function post_array(string $key): array
	{
		if (! isset($_POST[$key]) || ! is_array($_POST[$key])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return array();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$value = wp_unslash($_POST[$key]);

		return is_array($value) ? $value : array();
	}

	private static function get_text(string $key, string $default = ''): string
	{
		if (! isset($_GET[$key])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $default;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$value = wp_unslash($_GET[$key]);

		return sanitize_text_field((string) $value);
	}

	private static function get_key(string $key, string $default = ''): string
	{
		if (! isset($_GET[$key])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $default;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$value = wp_unslash($_GET[$key]);

		return sanitize_key((string) $value);
	}
	private static function default_notes(): array
	{
		$notes   = array();
		$notes[] = array(
			'ip'      => '',
			'comment' => 'Googlebot',
		);
		$notes[] = array(
			'ip'      => '',
			'comment' => 'Bingbot',
		);
		$notes[] = array(
			'ip'      => '',
			'comment' => 'Heavy bot',
		);

		for ($i = 3; $i < 30; $i++) {
			$notes[] = array(
				'ip'      => '',
				'comment' => '',
			);
		}

		return $notes;
	}

	private static function require_admin_and_nonce(string $nonce_field = 'nonce'): void
	{
		if (! current_user_can('manage_options')) {
			wp_send_json_error(
				array(
					'message' => __('Forbidden', 'zaygl-ip-blocker-and-anti-ddos'),
				),
				403
			);
		}

		$nonce = self::post_text($nonce_field, '');

		if ('' === $nonce) {
			$nonce = self::get_text($nonce_field, '');
		}

		if (! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
			wp_send_json_error(
				array(
					'message' => __('Bad nonce', 'zaygl-ip-blocker-and-anti-ddos'),
				),
				400
			);
		}
	}

	private static function top_minutes_from_table(string $table): int
	{
		if ('top5m' === $table) {
			return 5;
		}
		if ('top1h' === $table) {
			return 60;
		}
		if ('top24' === $table) {
			return 24 * 60;
		}
		if ('top3d' === $table) {
			return 72 * 60;
		}
		if ('top7d' === $table) {
			return 168 * 60;
		}

		return 24 * 60;
	}

	private static function sanitize_cc(string $country_code): string
	{
		$country_code = strtoupper(trim((string) $country_code));

		if ('UK' === $country_code) {
			$country_code = 'GB';
		}

		return (preg_match('/^[A-Z]{2}$/', $country_code) && 'XX' !== $country_code) ? $country_code : '';
	}

	private static function sanitize_ip_str(string $ip): string
	{
		$ip = trim((string) $ip);

		if ('' === $ip) {
			return '';
		}

		return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
	}

	private static function sanitize_ip_search(string $search): string
	{
		$search = trim((string) $search);
		$search = (string) preg_replace('/[^0-9a-fA-F\.\:\s]/', '', $search);

		return trim($search);
	}

	private static function sanitize_note(string $note): string
	{
		$note = wp_strip_all_tags((string) $note, true);
		$note = trim((string) preg_replace('/\s+/', ' ', $note));

		if (function_exists('mb_substr')) {
			$note = mb_substr($note, 0, 300);
		} else {
			$note = substr($note, 0, 300);
		}

		return $note;
	}

	private static function get_notes(): array
	{
		$notes = get_option(self::NOTES_OPTION);

		if (! is_array($notes) || 30 !== count($notes)) {
			$notes = self::default_notes();
			update_option(self::NOTES_OPTION, $notes, false);
		}

		for ($i = 0; $i < 30; $i++) {
			if (! isset($notes[$i]) || ! is_array($notes[$i])) {
				$notes[$i] = array(
					'ip'      => '',
					'comment' => '',
				);
			}

			$notes[$i]['ip']      = isset($notes[$i]['ip']) ? (string) $notes[$i]['ip'] : '';
			$notes[$i]['comment'] = isset($notes[$i]['comment']) ? (string) $notes[$i]['comment'] : '';
		}

		return $notes;
	}

	private static function set_notes(array $notes): void
	{
		update_option(self::NOTES_OPTION, $notes, false);
	}

	private static function render_notes_html(array $notes): string
	{
		ob_start();
?>
		<table class="widefat striped ipg-notes-table">
			<thead>
				<tr>
					<th class="ipg-notes-ip-col"><?php echo esc_html__('IP', 'zaygl-ip-blocker-and-anti-ddos'); ?></th>
					<th><?php echo esc_html__('Comment', 'zaygl-ip-blocker-and-anti-ddos'); ?></th>
					<th class="ipg-notes-action-col"><?php echo esc_html__('Actions', 'zaygl-ip-blocker-and-anti-ddos'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($notes as $index => $row) : ?>
					<tr data-note-row="<?php echo esc_attr((string) $index); ?>">
						<td>
							<input
								type="text"
								class="ipg-note-ip"
								value="<?php echo esc_attr($row['ip']); ?>"
								placeholder="<?php echo esc_attr__('e.g. 8.8.8.8', 'zaygl-ip-blocker-and-anti-ddos'); ?>" />
						</td>
						<td>
							<input
								type="text"
								class="ipg-note-comment"
								value="<?php echo esc_attr($row['comment']); ?>" />
						</td>
						<td>
							<div class="ipg-note-actions">
								<button type="button" class="button button-primary ipg-note-save">
									<?php echo esc_html__('Save', 'zaygl-ip-blocker-and-anti-ddos'); ?>
								</button>
								<button type="button" class="button ipg-note-delete">
									<?php echo esc_html__('Delete', 'zaygl-ip-blocker-and-anti-ddos'); ?>
								</button>
							</div>
							<div class="ipg-note-msg"></div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
<?php
		return (string) ob_get_clean();
	}

	/* =========================================================
	 * Bulk actions
	 * ======================================================= */

	public static function bulk_action(): void
	{
		self::require_admin_and_nonce('nonce');

		$action_do = self::post_key('bulk_action', '');
		$ips       = self::post_array('ips');

		$allowed = array('block_selected', 'unblock_selected', 'unblock_all');

		if (! in_array($action_do, $allowed, true)) {
			wp_send_json_error(
				array(
					'message' => __('Invalid bulk action', 'zaygl-ip-blocker-and-anti-ddos'),
				),
				400
			);
		}

		$blocked = ZAYGL_IPG_Core::blocked_list();

		if (! is_array($blocked)) {
			$blocked = array();
		}

		if ('unblock_all' === $action_do) {
			ZAYGL_IPG_Core::set_blocked_list(array());
			wp_send_json_success(
				array(
					'message' => __('All blocked IPs were unblocked.', 'zaygl-ip-blocker-and-anti-ddos'),
				)
			);
		}

		$clean_ips = array();

		foreach ($ips as $ip) {
			$clean_ip = self::sanitize_ip_str((string) $ip);

			if ('' !== $clean_ip) {
				$clean_ips[] = $clean_ip;
			}
		}

		$clean_ips = array_values(array_unique($clean_ips));

		if (empty($clean_ips)) {
			wp_send_json_error(
				array(
					'message' => __('No valid IPs selected.', 'zaygl-ip-blocker-and-anti-ddos'),
				),
				400
			);
		}

		if ('block_selected' === $action_do) {
			foreach ($clean_ips as $ip) {
				if (! in_array($ip, $blocked, true)) {
					$blocked[] = $ip;
				}
			}
		}

		if ('unblock_selected' === $action_do) {
			$blocked = array_values(
				array_filter(
					$blocked,
					static function ($blocked_ip) use ($clean_ips) {
						return ! in_array($blocked_ip, $clean_ips, true);
					}
				)
			);
		}

		ZAYGL_IPG_Core::set_blocked_list($blocked);

		wp_send_json_success();
	}

	/* =========================================================
	 * Tables AJAX
	 * ======================================================= */

	public static function ajax_table(): void
	{
		self::require_admin_and_nonce('nonce');

		$table = self::post_key('table', '');

		if (! in_array($table, array('top5m', 'top1h', 'top24', 'top3d', 'top7d', 'recent'), true)) {
			wp_send_json_error(
				array(
					'message' => __('Invalid table', 'zaygl-ip-blocker-and-anti-ddos'),
				),
				400
			);
		}

		$page     = max(1, self::post_int('page', 1));
		$per_page = self::post_int('per_page', 50);

		$allowed_pp = array(20, 50, 100, 500);
		if (! in_array($per_page, $allowed_pp, true)) {
			$per_page = 50;
		}

		$orderby   = self::post_key('orderby', '');
		$order     = strtoupper(self::post_key('order', 'DESC'));
		$country   = self::sanitize_cc(self::post_text('country', ''));
		$ip_search = self::sanitize_ip_search(self::post_text('ip_search', ''));

		if (! in_array($order, array('ASC', 'DESC'), true)) {
			$order = 'DESC';
		}

		$blocked = ZAYGL_IPG_Core::blocked_list();

		ob_start();

		if (in_array($table, array('top5m', 'top1h', 'top24', 'top3d', 'top7d'), true)) {
			$minutes = self::top_minutes_from_table($table);
			$title   = __('Top IPs — last 24 hours', 'zaygl-ip-blocker-and-anti-ddos');

			if ('top5m' === $table) {
				$title = __('Top IPs — last 5 minutes', 'zaygl-ip-blocker-and-anti-ddos');
			} elseif ('top1h' === $table) {
				$title = __('Top IPs — last 1 hour', 'zaygl-ip-blocker-and-anti-ddos');
			} elseif ('top3d' === $table) {
				$title = __('Top IPs — last 3 days', 'zaygl-ip-blocker-and-anti-ddos');
			} elseif ('top7d' === $table) {
				$title = __('Top IPs — last 7 days', 'zaygl-ip-blocker-and-anti-ddos');
			}

			if (! in_array($orderby, array('hits', 'last_seen'), true)) {
				$orderby = 'hits';
			}

			$countries = ZAYGL_IPG_Logger::top_countries_minutes($minutes);

			$data = ZAYGL_IPG_Logger::top_ips_paged_minutes(
				$minutes,
				$page,
				$per_page,
				$orderby,
				$order,
				$country,
				$ip_search
			);

			ZAYGL_IPG_Table::render_top_ips_table(
				array(
					'table_key' => $table,
					'title'     => $title,
					'rows'      => $data['rows'],
					'total'     => $data['total'],
					'page'      => $page,
					'per_page'  => $per_page,
					'orderby'   => $orderby,
					'order'     => $order,
					'blocked'   => $blocked,
					'ip_search' => $ip_search,
					'countries' => $countries,
					'country'   => $country,
				)
			);
		} else {
			$recent_hours   = self::post_int('recent_hours', 24);
			$recent_minutes = self::post_int('recent_minutes', 0);

			$allowed_hours = array(1, 6, 24, 168, 720, 0);

			if (! in_array($recent_hours, $allowed_hours, true)) {
				$recent_hours = 24;
			}

			if (5 !== $recent_minutes) {
				$recent_minutes = 0;
			}

			if (5 === $recent_minutes && method_exists('ZAYGL_IPG_Logger', 'recent_visits_paged_minutes')) {
				$data = ZAYGL_IPG_Logger::recent_visits_paged_minutes($page, $per_page, 5, $order);

				ZAYGL_IPG_Table::render_recent_table(
					array(
						'title'        => __('Recent visitor activity — full URLs and browser info', 'zaygl-ip-blocker-and-anti-ddos'),
						'rows'         => $data['rows'],
						'total'        => $data['total'],
						'page'         => $page,
						'per_page'     => $per_page,
						'order'        => $order,
						'recent_hours' => 0,
						'blocked'      => $blocked,
					)
				);
			} else {
				$data = ZAYGL_IPG_Logger::recent_visits_paged($page, $per_page, $recent_hours, $order);

				ZAYGL_IPG_Table::render_recent_table(
					array(
						'title'        => __('Recent visitor activity — full URLs and browser info', 'zaygl-ip-blocker-and-anti-ddos'),
						'rows'         => $data['rows'],
						'total'        => $data['total'],
						'page'         => $page,
						'per_page'     => $per_page,
						'order'        => $order,
						'recent_hours' => $recent_hours,
						'blocked'      => $blocked,
					)
				);
			}
		}

		$html = (string) ob_get_clean();

		wp_send_json_success(
			array(
				'html' => $html,
			)
		);
	}

	/* =========================================================
	 * Notes AJAX
	 * ======================================================= */

	public static function notes_list(): void
	{
		self::require_admin_and_nonce('nonce');

		$notes = self::get_notes();
		$html  = self::render_notes_html($notes);

		wp_send_json_success(
			array(
				'html' => $html,
			)
		);
	}

	public static function notes_save(): void
	{
		self::require_admin_and_nonce('nonce');

		$row = self::post_int('row', -1);

		if ($row < 0 || $row > 29) {
			wp_send_json_error(
				array(
					'message' => __('Invalid row', 'zaygl-ip-blocker-and-anti-ddos'),
				),
				400
			);
		}

		$raw_ip = self::post_text('ip', '');
		$ip     = self::sanitize_ip_str($raw_ip);

		if ('' === $ip && '' !== trim($raw_ip)) {
			wp_send_json_error(
				array(
					'message' => __('Invalid IP', 'zaygl-ip-blocker-and-anti-ddos'),
				),
				400
			);
		}

		$comment = self::sanitize_note(self::post_text('comment', ''));

		$notes       = self::get_notes();
		$notes[$row] = array(
			'ip'      => $ip,
			'comment' => $comment,
		);

		self::set_notes($notes);

		wp_send_json_success(
			array(
				'ok' => true,
			)
		);
	}

	public static function notes_delete(): void
	{
		self::require_admin_and_nonce('nonce');

		$row = self::post_int('row', -1);

		if ($row < 0 || $row > 29) {
			wp_send_json_error(
				array(
					'message' => __('Invalid row', 'zaygl-ip-blocker-and-anti-ddos'),
				),
				400
			);
		}

		$notes       = self::get_notes();
		$notes[$row] = array(
			'ip'      => '',
			'comment' => '',
		);

		self::set_notes($notes);

		wp_send_json_success(
			array(
				'ok' => true,
			)
		);
	}

	/* =========================================================
	 * Export
	 * ======================================================= */

	public static function export_csv(): void
	{
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Forbidden', 'zaygl-ip-blocker-and-anti-ddos'), 403);
		}

		$nonce = self::get_text('nonce', '');

		if (! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
			wp_die(esc_html__('Bad nonce', 'zaygl-ip-blocker-and-anti-ddos'), 400);
		}

		$table   = self::get_key('table', 'top24');
		$orderby = self::get_key('orderby', 'hits');
		$order   = strtoupper(self::get_key('order', 'DESC'));
		$country = self::sanitize_cc(self::get_text('country', ''));

		if (! in_array($table, array('top24', 'top3d', 'top7d'), true)) {
			$table = 'top24';
		}

		if (! in_array($orderby, array('hits', 'last_seen'), true)) {
			$orderby = 'hits';
		}

		if (! in_array($order, array('ASC', 'DESC'), true)) {
			$order = 'DESC';
		}

		$hours = 24;
		if ('top3d' === $table) {
			$hours = 72;
		}
		if ('top7d' === $table) {
			$hours = 168;
		}

		$all_rows = array();
		$page     = 1;
		$per_page = 2000;
		$max_rows = 50000;

		do {
			$data  = ZAYGL_IPG_Logger::top_ips_paged($hours, $page, $per_page, $orderby, $order, $country, '');
			$rows  = (array) ($data['rows'] ?? array());
			$total = (int) ($data['total'] ?? 0);

			if (empty($rows)) {
				break;
			}

			$all_rows = array_merge($all_rows, $rows);
			++$page;

			if ($total > 0 && count($all_rows) >= $total) {
				break;
			}

			if (count($all_rows) >= $max_rows) {
				$all_rows = array_slice($all_rows, 0, $max_rows);
				break;
			}
		} while (true);

		$filename = 'zaygl-ip-blocker-and-anti-ddos-' . $table . '-' . gmdate('Y-m-d') . '.csv';

		nocache_headers();
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename=' . $filename);

		echo "\xEF\xBB\xBF";

		$output = fopen('php://output', 'w');

		if (false === $output) {
			wp_die(esc_html__('Could not open export output.', 'zaygl-ip-blocker-and-anti-ddos'));
		}

		fputcsv($output, array('IP', 'Country', 'Hits', 'Last seen (WP time)'));

		foreach ($all_rows as $row) {
			$ip        = (string) ($row['ip'] ?? '');
			$hits      = (int) ($row['hits'] ?? 0);
			$last_seen = (string) ($row['last_seen'] ?? '');

			$country_code = (string) ($row['country'] ?? '');
			if ('' === $country_code && '' !== $ip) {
				$country_code = (string) ZAYGL_IPG_Geo::resolve_country_for_ip($ip);
			}
			$country_code = self::sanitize_cc($country_code);

			fputcsv(
				$output,
				array(
					$ip,
					$country_code,
					$hits,
					ZAYGL_IPG_Table::export_fmt_dt($last_seen),
				)
			);
		}

		exit;
	}

	public static function export_print(): void
	{
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Forbidden', 'zaygl-ip-blocker-and-anti-ddos'), 403);
		}

		$nonce = self::get_text('nonce', '');

		if (! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
			wp_die(esc_html__('Bad nonce', 'zaygl-ip-blocker-and-anti-ddos'), 400);
		}

		$table   = self::get_key('table', 'top24');
		$orderby = self::get_key('orderby', 'hits');
		$order   = strtoupper(self::get_key('order', 'DESC'));
		$country = self::sanitize_cc(self::get_text('country', ''));

		if (! in_array($table, array('top24', 'top3d', 'top7d'), true)) {
			$table = 'top24';
		}

		if (! in_array($orderby, array('hits', 'last_seen'), true)) {
			$orderby = 'hits';
		}

		if (! in_array($order, array('ASC', 'DESC'), true)) {
			$order = 'DESC';
		}

		$hours = 24;
		if ('top3d' === $table) {
			$hours = 72;
		}
		if ('top7d' === $table) {
			$hours = 168;
		}

		$all_rows = array();
		$page     = 1;
		$per_page = 2000;
		$max_rows = 50000;

		do {
			$data  = ZAYGL_IPG_Logger::top_ips_paged($hours, $page, $per_page, $orderby, $order, $country, '');
			$rows  = (array) ($data['rows'] ?? array());
			$total = (int) ($data['total'] ?? 0);

			if (empty($rows)) {
				break;
			}

			$all_rows = array_merge($all_rows, $rows);
			++$page;

			if ($total > 0 && count($all_rows) >= $total) {
				break;
			}

			if (count($all_rows) >= $max_rows) {
				$all_rows = array_slice($all_rows, 0, $max_rows);
				break;
			}
		} while (true);

		$title = __('Top IPs — last 24 hours', 'zaygl-ip-blocker-and-anti-ddos');
		if ('top3d' === $table) {
			$title = __('Top IPs — last 3 days', 'zaygl-ip-blocker-and-anti-ddos');
		} elseif ('top7d' === $table) {
			$title = __('Top IPs — last 7 days', 'zaygl-ip-blocker-and-anti-ddos');
		}

		if ('' !== $country) {
			$title .= ' — ' . $country;
		}

		nocache_headers();
		header('Content-Type: text/html; charset=utf-8');

		echo '<!doctype html>';
		echo '<html><head><meta charset="utf-8"><title>' . esc_html($title) . '</title></head><body>';
		echo '<h1>' . esc_html($title) . '</h1>';
		echo '<p>' . esc_html__('Generated:', 'zaygl-ip-blocker-and-anti-ddos') . ' ' . esc_html(wp_date('Y-m-d H:i')) . '</p>';

		echo '<table border="1" cellpadding="8" cellspacing="0">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__('IP', 'zaygl-ip-blocker-and-anti-ddos') . '</th>';
		echo '<th>' . esc_html__('Country', 'zaygl-ip-blocker-and-anti-ddos') . '</th>';
		echo '<th>' . esc_html__('Hits', 'zaygl-ip-blocker-and-anti-ddos') . '</th>';
		echo '<th>' . esc_html__('Last seen (WP time)', 'zaygl-ip-blocker-and-anti-ddos') . '</th>';
		echo '</tr></thead><tbody>';

		foreach ($all_rows as $row) {
			$ip        = (string) ($row['ip'] ?? '');
			$hits      = (int) ($row['hits'] ?? 0);
			$last_seen = (string) ($row['last_seen'] ?? '');

			$country_code = (string) ($row['country'] ?? '');
			if ('' === $country_code && '' !== $ip) {
				$country_code = (string) ZAYGL_IPG_Geo::resolve_country_for_ip($ip);
			}
			$country_code = self::sanitize_cc($country_code);

			echo '<tr>';
			echo '<td><code>' . esc_html($ip) . '</code></td>';
			echo '<td>' . esc_html($country_code) . '</td>';
			echo '<td>' . esc_html((string) $hits) . '</td>';
			echo '<td>' . esc_html(ZAYGL_IPG_Table::export_fmt_dt($last_seen)) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</body></html>';
		exit;
	}
}
