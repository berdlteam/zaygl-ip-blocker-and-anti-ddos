<?php
if (! defined('ABSPATH')) {
	exit;
}

class ZAYGL_IPG_Admin
{

	private const MENU_SLUG = 'dia-ip-guardian';

	public static function init(): void
	{
		add_action('admin_menu', array(__CLASS__, 'menu'));
		add_action('admin_post_zaygl_ipg_action', array(__CLASS__, 'handle_action'));

		add_action('admin_post_zaygl_ipg_reset_settings', array(__CLASS__, 'reset_settings'));
		add_action('admin_post_zaygl_ipg_clear_logs', array(__CLASS__, 'clear_logs'));
	}

	public static function menu(): void
	{
		add_menu_page(
			__('Zaygl IP Blocker and Anti-DDoS', 'zaygl-ip-blocker-and-anti-ddos'),
			__('IP Guardian', 'zaygl-ip-blocker-and-anti-ddos'),
			'manage_options',
			self::MENU_SLUG,
			array(__CLASS__, 'render'),
			'dashicons-shield',
			81
		);
	}

	private static function allowed_tabs(): array
	{
		return array('overview', 'recent', 'notes', 'blocked', 'settings', 'info');
	}

	private static function sanitize_tab(string $tab): string
	{
		$tab = sanitize_key($tab);

		return in_array($tab, self::allowed_tabs(), true) ? $tab : 'overview';
	}

	private static function current_tab(): string
	{
		$tab       = 'overview';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab_input = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
		if ('' !== $tab_input) {
			$tab = $tab_input;
		}

		return self::sanitize_tab($tab);
	}

	private static function get_text_param(array $source, string $key, string $default = ''): string
	{
		if (! isset($source[$key])) {
			return $default;
		}

		return sanitize_text_field(wp_unslash((string) $source[$key]));
	}

	private static function get_key_param(array $source, string $key, string $default = ''): string
	{
		if (! isset($source[$key])) {
			return $default;
		}

		return sanitize_key(wp_unslash((string) $source[$key]));
	}

	private static function get_int_param(array $source, string $key, int $default = 0): int
	{
		if (! isset($source[$key])) {
			return $default;
		}

		return absint(wp_unslash((string) $source[$key]));
	}

	private static function get_checkbox_param(array $source, string $key): int
	{
		return ! empty($source[$key]) ? 1 : 0;
	}

	private static function action_url(string $action_do, string $ip = '', ?string $tab = null): string
	{
		if (null === $tab) {
			$tab = self::current_tab();
		}

		return admin_url(
			'admin-post.php?' . http_build_query(
				array(
					'action'   => 'zaygl_ipg_action',
					'do'       => $action_do,
					'ip'       => $ip,
					'tab'      => $tab,
					'_wpnonce' => wp_create_nonce('zaygl_ipg_nonce'),
				)
			)
		);
	}

	private static function admin_page_url(string $tab = 'overview', array $extra = array()): string
	{
		$args = array_merge(
			array(
				'page' => self::MENU_SLUG,
				'tab'  => self::sanitize_tab($tab),
			),
			$extra
		);

		return admin_url('tools.php?' . http_build_query($args));
	}

	public static function handle_action(): void
	{
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Forbidden', 'zaygl-ip-blocker-and-anti-ddos'));
		}

		check_admin_referer('zaygl_ipg_nonce', '_wpnonce');

		$action_do = '';
		if (isset($_POST['do'])) {
			$action_do = self::get_key_param($_POST, 'do', '');
		} elseif (isset($_GET['do'])) {
			$action_do = self::get_key_param($_GET, 'do', '');
		}

		$ip = '';
		if (isset($_POST['ip'])) {
			$ip = self::get_text_param($_POST, 'ip', '');
		} elseif (isset($_GET['ip'])) {
			$ip = self::get_text_param($_GET, 'ip', '');
		}

		$return_tab = 'overview';

		if (isset($_POST['return_tab'])) {
			$return_tab = self::sanitize_tab(self::get_key_param($_POST, 'return_tab', 'overview'));
		} else {
			$return_tab_input = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
			if ('' !== $return_tab_input) {
				$return_tab = self::sanitize_tab($return_tab_input);
			}
		}

		if ('save_settings' === $action_do) {
			$new = array(
				'ip_source'         => self::get_text_param($_POST, 'ip_source', 'auto'),
				'retention_days'    => self::get_int_param($_POST, 'retention_days', 30),
				'ignore_admins'     => self::get_checkbox_param($_POST, 'ignore_admins'),
				'track_logged_in'   => self::get_checkbox_param($_POST, 'track_logged_in'),
				'geo_mode'          => self::get_text_param($_POST, 'geo_mode', 'auto'),
				'remote_geo'        => self::get_checkbox_param($_POST, 'remote_geo'),
				'remote_geo_vendor' => self::get_text_param($_POST, 'remote_geo_vendor', 'ipapi_co'),
				'maxmind_mmdb_path' => self::get_text_param($_POST, 'maxmind_mmdb_path', ''),
			);

			if (! in_array($new['ip_source'], array('auto', 'remote_addr', 'cf', 'xff'), true)) {
				$new['ip_source'] = 'auto';
			}

			$new['retention_days'] = max(1, min(365, (int) $new['retention_days']));

			if (! in_array($new['geo_mode'], array('auto', 'off', 'cf', 'maxmind', 'remote'), true)) {
				$new['geo_mode'] = 'auto';
			}

			if (! in_array($new['remote_geo_vendor'], array('ipapi_co', 'ip_api_com'), true)) {
				$new['remote_geo_vendor'] = 'ipapi_co';
			}

			ZAYGL_IPG_Core::set_cfg($new);
		}

		if ('unblock_all' === $action_do) {
			ZAYGL_IPG_Core::set_blocked_list(array());
		}

		if (in_array($action_do, array('block', 'unblock'), true) && filter_var($ip, FILTER_VALIDATE_IP)) {
			$blocked = ZAYGL_IPG_Core::blocked_list();

			if ('block' === $action_do) {
				if (! in_array($ip, $blocked, true)) {
					$blocked[] = $ip;
				}
			} else {
				$blocked = array_values(
					array_filter(
						$blocked,
						static function ($blocked_ip) use ($ip) {
							return $blocked_ip !== $ip;
						}
					)
				);
			}

			ZAYGL_IPG_Core::set_blocked_list($blocked);
		}

		if ('cleanup_now' === $action_do) {
			ZAYGL_IPG_Logger::cleanup_logs();
		}

		wp_safe_redirect(self::admin_page_url($return_tab));
		exit;
	}

	public static function render(): void
	{
		if (! current_user_can('manage_options')) {
			return;
		}

		$tab     = self::current_tab();
		$cfg     = ZAYGL_IPG_Core::cfg();
		$blocked = ZAYGL_IPG_Core::blocked_list();
?>
		<div class="wrap ipg-admin-wrap">
			<h1><?php echo esc_html__('Zaygl IP Blocker and Anti-DDoS', 'zaygl-ip-blocker-and-anti-ddos'); ?></h1>

			<?php self::render_tabs($tab); ?>

			<div class="ipg-admin-content">
				<?php
				if ('overview' === $tab) {
					self::render_tab_overview($blocked);
				} elseif ('recent' === $tab) {
					self::render_tab_recent($blocked);
				} elseif ('notes' === $tab) {
					self::render_tab_notes();
				} elseif ('blocked' === $tab) {
					self::render_tab_blocked($blocked);
				} elseif ('settings' === $tab) {
					self::render_tab_settings($cfg);
				} else {
					self::render_tab_info();
				}
				?>
			</div>
		</div>
	<?php
	}

	private static function render_tabs(string $active_tab): void
	{
		$tabs = array(
			'overview' => __('Overview', 'zaygl-ip-blocker-and-anti-ddos'),
			'recent'   => __('Visitor activity', 'zaygl-ip-blocker-and-anti-ddos'),
			'notes'    => __('Notes', 'zaygl-ip-blocker-and-anti-ddos'),
			'blocked'  => __('Blocked', 'zaygl-ip-blocker-and-anti-ddos'),
			'settings' => __('Settings', 'zaygl-ip-blocker-and-anti-ddos'),
			'info'     => __('Info', 'zaygl-ip-blocker-and-anti-ddos'),
		);

		echo '<nav class="nav-tab-wrapper ipg-nav-tabs">';

		foreach ($tabs as $key => $label) {
			$url   = self::admin_page_url($key);
			$class = 'nav-tab' . ($active_tab === $key ? ' nav-tab-active' : '');

			echo '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
		}

		echo '</nav>';
	}

	private static function render_tab_overview(array $blocked): void
	{
		unset($blocked);
	?>
		<p class="ipg-intro-text">
			<?php echo esc_html__('Overview of top visitor IPs. Use sorting, pagination, and rows-per-page without reloading the whole plugin page.', 'zaygl-ip-blocker-and-anti-ddos'); ?>
		</p>

		<hr />

		<div class="ipg-overview-wrap">
			<div class="ipg-overview-toolbar">
				<div class="ipg-overview-toolbar-left">
					<label for="ipg_top_range"><strong><?php echo esc_html__('Top IPs:', 'zaygl-ip-blocker-and-anti-ddos'); ?></strong></label>

					<select id="ipg_top_range">
						<option value="top5m"><?php echo esc_html__('Last 5 minutes', 'zaygl-ip-blocker-and-anti-ddos'); ?></option>
						<option value="top1h"><?php echo esc_html__('Last 1 hour', 'zaygl-ip-blocker-and-anti-ddos'); ?></option>
						<option value="top24" selected="selected"><?php echo esc_html__('Last 24 hours', 'zaygl-ip-blocker-and-anti-ddos'); ?></option>
						<option value="top3d"><?php echo esc_html__('Last 3 days', 'zaygl-ip-blocker-and-anti-ddos'); ?></option>
						<option value="top7d"><?php echo esc_html__('Last 7 days', 'zaygl-ip-blocker-and-anti-ddos'); ?></option>
					</select>

					<span id="ipg-top-loading" class="ipg-top-loading">
						<?php echo esc_html__('Loading…', 'zaygl-ip-blocker-and-anti-ddos'); ?>
					</span>
				</div>

				<div class="ipg-overview-toolbar-center">
					<input type="search" class="ipg-ip-search" placeholder="<?php echo esc_attr__('Search IP', 'zaygl-ip-blocker-and-anti-ddos'); ?>" />
				</div>

				<div class="ipg-overview-toolbar-right">
					<select id="ipg-bulk-action">
						<option value=""><?php echo esc_html__('bulk actions', 'zaygl-ip-blocker-and-anti-ddos'); ?></option>
						<option value="block_selected"><?php echo esc_html__('block selected', 'zaygl-ip-blocker-and-anti-ddos'); ?></option>
						<option value="unblock_selected"><?php echo esc_html__('unblock selected', 'zaygl-ip-blocker-and-anti-ddos'); ?></option>
						<option value="unblock_all"><?php echo esc_html__('unblock all blocked IPs', 'zaygl-ip-blocker-and-anti-ddos'); ?></option>
					</select>

					<button type="button" class="button" id="ipg-bulk-apply">
						<?php echo esc_html__('Submit', 'zaygl-ip-blocker-and-anti-ddos'); ?>
					</button>
				</div>
			</div>

			<div id="ipg-top-container" data-country="" data-ip-search=""></div>
		</div>
	<?php
	}

	private static function render_tab_recent(array $blocked): void
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$recent_range = isset($_GET['recent_hours']) ? absint(wp_unslash($_GET['recent_hours'])) : 24;
		
		$allowed_ranges = array(5, 1, 6, 24, 168, 720, 0);

		if (! in_array($recent_range, $allowed_ranges, true)) {
			$recent_range = 24;
		}
	?>
		<p class="ipg-intro-text">
			<?php echo esc_html__('Recent visitor activity shows exact URLs and browser user agents. You can sort by time and paginate without reloading the whole plugin page.', 'zaygl-ip-blocker-and-anti-ddos'); ?>
		</p>

		<hr />

		<form method="get" class="ipg-recent-filter-form">
			<input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SLUG); ?>" />
			<input type="hidden" name="tab" value="recent" />

			<label for="recent_hours"><strong><?php echo esc_html__('Show:', 'zaygl-ip-blocker-and-anti-ddos'); ?></strong></label>
			<select name="recent_hours" id="recent_hours">
				<option value="5" <?php selected($recent_range, 5); ?>><?php echo esc_html__('Last 5 minutes', 'zaygl-ip-blocker-and-anti-ddos'); ?></option>
				<option value="1" <?php selected($recent_range, 1); ?>><?php echo esc_html__('Last 1 hour', 'zaygl-ip-blocker-and-anti-ddos'); ?></option>
				<option value="6" <?php selected($recent_range, 6); ?>><?php echo esc_html__('Last 6 hours', 'zaygl-ip-blocker-and-anti-ddos'); ?></option>
				<option value="24" <?php selected($recent_range, 24); ?>><?php echo esc_html__('Last 24 hours', 'zaygl-ip-blocker-and-anti-ddos'); ?></option>
				<option value="168" <?php selected($recent_range, 168); ?>><?php echo esc_html__('Last 7 days', 'zaygl-ip-blocker-and-anti-ddos'); ?></option>
				<option value="720" <?php selected($recent_range, 720); ?>><?php echo esc_html__('Last 30 days', 'zaygl-ip-blocker-and-anti-ddos'); ?></option>
				<option value="0" <?php selected($recent_range, 0); ?>><?php echo esc_html__('All time', 'zaygl-ip-blocker-and-anti-ddos'); ?></option>
			</select>

			<?php submit_button(__('Filter', 'zaygl-ip-blocker-and-anti-ddos'), 'secondary', '', false); ?>
		</form>

		<?php
		$page     = 1;
		$per_page = 50;
		$order    = 'DESC';

		if (5 === $recent_range) {
			$data = ZAYGL_IPG_Logger::recent_visits_paged_minutes($page, $per_page, 5, $order);
		} else {
			$data = ZAYGL_IPG_Logger::recent_visits_paged($page, $per_page, $recent_range, $order);
		}

		ZAYGL_IPG_Table::render_recent_table(
			array(
				'title'        => __('Recent visitor activity — full URLs and browser info', 'zaygl-ip-blocker-and-anti-ddos'),
				'rows'         => $data['rows'],
				'total'        => $data['total'],
				'page'         => $page,
				'per_page'     => $per_page,
				'order'        => $order,
				'recent_hours' => $recent_range,
				'blocked'      => $blocked,
			)
		);
	}

	private static function render_tab_notes(): void
	{
		$notes = get_option('zaygl_ipg_notes_rows', array());

		if (! is_array($notes)) {
			$notes = array();
		}

		for ($i = 0; $i < 30; $i++) {
			if (! isset($notes[$i]) || ! is_array($notes[$i])) {
				$notes[$i] = array(
					'ip'      => '',
					'comment' => '',
				);
			} else {
				$notes[$i]['ip']      = isset($notes[$i]['ip']) ? (string) $notes[$i]['ip'] : '';
				$notes[$i]['comment'] = isset($notes[$i]['comment']) ? (string) $notes[$i]['comment'] : '';
			}
		}

		if ('' === trim($notes[0]['comment'])) {
			$notes[0]['comment'] = 'Googlebot';
		}
		if ('' === trim($notes[1]['comment'])) {
			$notes[1]['comment'] = 'Bingbot';
		}
		if ('' === trim($notes[2]['comment'])) {
			$notes[2]['comment'] = 'Heavy bot (high hits / suspicious)';
		}

		update_option('zaygl_ipg_notes_rows', $notes, false);
		?>
		<p class="ipg-intro-text">
			<?php echo esc_html__('Store quick notes for IPs, for example “Googlebot”, “Payment webhook”, or “Suspicious scraper”. Notes are stored locally.', 'zaygl-ip-blocker-and-anti-ddos'); ?>
		</p>

		<hr />

		<div class="ipg-notes-wrap">
			<table class="widefat striped ipg-notes-table">
				<thead>
					<tr>
						<th class="ipg-notes-ip-col"><?php echo esc_html__('IP', 'zaygl-ip-blocker-and-anti-ddos'); ?></th>
						<th><?php echo esc_html__('Comment', 'zaygl-ip-blocker-and-anti-ddos'); ?></th>
						<th class="ipg-notes-action-col"><?php echo esc_html__('Action', 'zaygl-ip-blocker-and-anti-ddos'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php for ($i = 0; $i < 30; $i++) : ?>
						<tr data-note-row="<?php echo esc_attr((string) $i); ?>">
							<td>
								<input type="text" class="ipg-note-ip" value="<?php echo esc_attr($notes[$i]['ip']); ?>" placeholder="<?php echo esc_attr__('8.8.8.8', 'zaygl-ip-blocker-and-anti-ddos'); ?>" />
							</td>
							<td>
								<input type="text" class="ipg-note-comment" value="<?php echo esc_attr($notes[$i]['comment']); ?>" placeholder="<?php echo esc_attr__('Write a short note…', 'zaygl-ip-blocker-and-anti-ddos'); ?>" />
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
					<?php endfor; ?>
				</tbody>
			</table>
		</div>
	<?php
	}

	private static function render_tab_blocked(array $blocked): void
	{
	?>
		<p><?php echo esc_html__('All IPs you blocked using the plugin.', 'zaygl-ip-blocker-and-anti-ddos'); ?></p>
		<hr />

		<div class="ipg-blocked-actions">
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="zaygl_ipg_action" />
				<input type="hidden" name="do" value="unblock_all" />
				<input type="hidden" name="return_tab" value="blocked" />
				<?php wp_nonce_field('zaygl_ipg_nonce'); ?>
				<button type="submit" class="button button-secondary">
					<?php echo esc_html__('unblock all', 'zaygl-ip-blocker-and-anti-ddos'); ?>
				</button>
			</form>
		</div>

		<div class="ipg-blocked-wrap">
			<table class="widefat striped">
				<thead>
					<tr>
						<th class="ipg-blocked-num-col">#</th>
						<th><?php echo esc_html__('IP', 'zaygl-ip-blocker-and-anti-ddos'); ?></th>
						<th class="ipg-blocked-action-col"><?php echo esc_html__('Action', 'zaygl-ip-blocker-and-anti-ddos'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if (empty($blocked)) : ?>
						<tr>
							<td colspan="3"><?php echo esc_html__('No blocked IPs yet.', 'zaygl-ip-blocker-and-anti-ddos'); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach (array_values($blocked) as $index => $ip) : ?>
							<tr>
								<td><?php echo esc_html((string) ($index + 1)); ?></td>
								<td><code><?php echo esc_html((string) $ip); ?></code></td>
								<td>
									<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
										<input type="hidden" name="action" value="zaygl_ipg_action" />
										<input type="hidden" name="do" value="unblock" />
										<input type="hidden" name="ip" value="<?php echo esc_attr((string) $ip); ?>" />
										<input type="hidden" name="return_tab" value="blocked" />
										<?php wp_nonce_field('zaygl_ipg_nonce'); ?>
										<button type="submit" class="button">
											<?php echo esc_html__('unblock', 'zaygl-ip-blocker-and-anti-ddos'); ?>
										</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	<?php
	}

	public static function clear_logs(): void
	{
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Forbidden', 'zaygl-ip-blocker-and-anti-ddos'));
		}

		check_admin_referer('zaygl_ipg_clear_logs');

		global $wpdb;

		$table = esc_sql($wpdb->prefix . 'zaygl_ipg_logs');

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted plugin-owned table name.
		$wpdb->query('TRUNCATE TABLE `' . $table . '`');

		wp_safe_redirect(self::admin_page_url('settings'));
		exit;
	}

	public static function reset_settings(): void
	{
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Forbidden', 'zaygl-ip-blocker-and-anti-ddos'));
		}

		check_admin_referer('zaygl_ipg_reset_settings');

		delete_option('zaygl_ipg_retention_days');
		delete_option('zaygl_ipg_geo_enabled');
		delete_option('zaygl_ipg_whatever_else');

		wp_safe_redirect(self::admin_page_url('settings'));
		exit;
	}

	private static function render_tab_settings(array $cfg): void
	{
	?>
		<div class="ipg-settings-wrap">
			<p>
				<?php echo esc_html__('Here you can control how Zaygl IP Blocker and Anti-DDoS detects visitor IPs, how long logs are stored, and how country flags are detected. If you are not sure, the “Auto” options are safe defaults.', 'zaygl-ip-blocker-and-anti-ddos'); ?>
			</p>

			<hr />

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="zaygl_ipg_action" />
				<input type="hidden" name="do" value="save_settings" />
				<input type="hidden" name="return_tab" value="settings" />
				<?php wp_nonce_field('zaygl_ipg_nonce'); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__('IP Source', 'zaygl-ip-blocker-and-anti-ddos'); ?></th>
						<td>
							<select name="ip_source">
								<option value="auto" <?php selected($cfg['ip_source'], 'auto'); ?>>
									<?php echo esc_html__('Auto (CF → XFF → REMOTE_ADDR)', 'zaygl-ip-blocker-and-anti-ddos'); ?>
								</option>
								<option value="cf" <?php selected($cfg['ip_source'], 'cf'); ?>>
									<?php echo esc_html__('Cloudflare (HTTP_CF_CONNECTING_IP)', 'zaygl-ip-blocker-and-anti-ddos'); ?>
								</option>
								<option value="xff" <?php selected($cfg['ip_source'], 'xff'); ?>>
									<?php echo esc_html__('Proxy (X-Forwarded-For)', 'zaygl-ip-blocker-and-anti-ddos'); ?>
								</option>
								<option value="remote_addr" <?php selected($cfg['ip_source'], 'remote_addr'); ?>>
									<?php echo esc_html__('Direct (REMOTE_ADDR)', 'zaygl-ip-blocker-and-anti-ddos'); ?>
								</option>
							</select>

							<p class="description">
								<?php echo esc_html__('This controls where the plugin gets the visitor IP from. If your site is behind Cloudflare or a proxy, picking the wrong source can show the proxy IP instead of the real visitor.', 'zaygl-ip-blocker-and-anti-ddos'); ?>
							</p>

							<ul class="ipg-settings-list">
								<li><strong><?php echo esc_html__('Auto:', 'zaygl-ip-blocker-and-anti-ddos'); ?></strong> <?php echo esc_html__('Best choice for most sites. Tries Cloudflare first, then proxy headers, then direct.', 'zaygl-ip-blocker-and-anti-ddos'); ?></li>
								<li><strong><?php echo esc_html__('Cloudflare:', 'zaygl-ip-blocker-and-anti-ddos'); ?></strong> <?php echo esc_html__('Use this if your site is on Cloudflare and you want the most accurate real visitor IP.', 'zaygl-ip-blocker-and-anti-ddos'); ?></li>
								<li><strong><?php echo esc_html__('Proxy (X-Forwarded-For):', 'zaygl-ip-blocker-and-anti-ddos'); ?></strong> <?php echo esc_html__('Use only if you are behind a trusted proxy or load balancer that sets XFF correctly.', 'zaygl-ip-blocker-and-anti-ddos'); ?></li>
								<li><strong><?php echo esc_html__('Direct (REMOTE_ADDR):', 'zaygl-ip-blocker-and-anti-ddos'); ?></strong> <?php echo esc_html__('Use this if you are not behind Cloudflare or proxies.', 'zaygl-ip-blocker-and-anti-ddos'); ?></li>
							</ul>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php echo esc_html__('Retention (days)', 'zaygl-ip-blocker-and-anti-ddos'); ?></th>
						<td>
							<input type="number" name="retention_days" min="1" max="365" value="<?php echo esc_attr((string) (int) $cfg['retention_days']); ?>" />

							<p class="description">
								<?php echo esc_html__('How long visit logs are kept before old entries are deleted automatically. Smaller values keep your database lighter. A good default is 7–30 days.', 'zaygl-ip-blocker-and-anti-ddos'); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php echo esc_html__('Ignore admins', 'zaygl-ip-blocker-and-anti-ddos'); ?></th>
						<td>
							<label>
								<input type="checkbox" name="ignore_admins" value="1" <?php checked(! empty($cfg['ignore_admins'])); ?> />
								<?php echo esc_html__('Do not log administrators', 'zaygl-ip-blocker-and-anti-ddos'); ?>
							</label>

							<p class="description">
								<?php echo esc_html__('Enabled: visits made by Administrator users will not be logged. Useful to avoid polluting logs with your own activity.', 'zaygl-ip-blocker-and-anti-ddos'); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php echo esc_html__('Track logged-in users', 'zaygl-ip-blocker-and-anti-ddos'); ?></th>
						<td>
							<label>
								<input type="checkbox" name="track_logged_in" value="1" <?php checked(! empty($cfg['track_logged_in'])); ?> />
								<?php echo esc_html__('Track logged-in users too', 'zaygl-ip-blocker-and-anti-ddos'); ?>
							</label>

							<p class="description">
								<?php echo esc_html__('Enabled: visits from logged-in users will be logged. Disabled: only visitors who are not logged in will be tracked.', 'zaygl-ip-blocker-and-anti-ddos'); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th colspan="2">
							<hr />
						</th>
					</tr>

					<tr>
						<th scope="row"><?php echo esc_html__('Geo detection', 'zaygl-ip-blocker-and-anti-ddos'); ?></th>
						<td>
							<select name="geo_mode">
								<option value="auto" <?php selected($cfg['geo_mode'], 'auto'); ?>>
									<?php echo esc_html__('Auto (CF → MaxMind → Remote)', 'zaygl-ip-blocker-and-anti-ddos'); ?>
								</option>
								<option value="off" <?php selected($cfg['geo_mode'], 'off'); ?>>
									<?php echo esc_html__('Off', 'zaygl-ip-blocker-and-anti-ddos'); ?>
								</option>
								<option value="cf" <?php selected($cfg['geo_mode'], 'cf'); ?>>
									<?php echo esc_html__('Cloudflare header only', 'zaygl-ip-blocker-and-anti-ddos'); ?>
								</option>
								<option value="maxmind" <?php selected($cfg['geo_mode'], 'maxmind'); ?>>
									<?php echo esc_html__('MaxMind (MMDB + library)', 'zaygl-ip-blocker-and-anti-ddos'); ?>
								</option>
								<option value="remote" <?php selected($cfg['geo_mode'], 'remote'); ?>>
									<?php echo esc_html__('Remote API (admin only)', 'zaygl-ip-blocker-and-anti-ddos'); ?>
								</option>
							</select>

							<p class="description">
								<?php echo esc_html__('Country detection is used only to show flags and country codes in the admin tables. If you do not need flags, you can turn this off.', 'zaygl-ip-blocker-and-anti-ddos'); ?>
							</p>

							<label class="ipg-settings-block-label">
								<input type="checkbox" name="remote_geo" value="1" <?php checked(! empty($cfg['remote_geo'])); ?> />
								<?php echo esc_html__('Allow remote geo lookup in admin (cached)', 'zaygl-ip-blocker-and-anti-ddos'); ?>
							</label>

							<label class="ipg-settings-block-label">
								<?php echo esc_html__('Remote vendor:', 'zaygl-ip-blocker-and-anti-ddos'); ?>
								<select name="remote_geo_vendor">
									<option value="ipapi_co" <?php selected($cfg['remote_geo_vendor'], 'ipapi_co'); ?>>ipapi.co</option>
									<option value="ip_api_com" <?php selected($cfg['remote_geo_vendor'], 'ip_api_com'); ?>>ip-api.com</option>
								</select>
							</label>

							<label class="ipg-settings-block-label">
								<?php echo esc_html__('MaxMind MMDB path (optional):', 'zaygl-ip-blocker-and-anti-ddos'); ?>
								<input type="text" name="maxmind_mmdb_path" value="<?php echo esc_attr((string) $cfg['maxmind_mmdb_path']); ?>" placeholder="<?php echo esc_attr__('/full/path/to/GeoLite2-Country.mmdb', 'zaygl-ip-blocker-and-anti-ddos'); ?>" />
							</label>

							<p class="description">
								<?php echo esc_html__('Requires GeoIp2\\Database\\Reader (geoip2/geoip2).', 'zaygl-ip-blocker-and-anti-ddos'); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(__('Save settings', 'zaygl-ip-blocker-and-anti-ddos')); ?>
			</form>

			<div class="ipg-settings-actions">
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="zaygl_ipg_clear_logs" />
					<?php wp_nonce_field('zaygl_ipg_clear_logs'); ?>
					<button type="submit" class="button button-secondary">
						<?php echo esc_html__('Clear all logs', 'zaygl-ip-blocker-and-anti-ddos'); ?>
					</button>
				</form>

				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="zaygl_ipg_reset_settings" />
					<?php wp_nonce_field('zaygl_ipg_reset_settings'); ?>
					<button type="submit" class="button button-link-delete">
						<?php echo esc_html__('Reset settings', 'zaygl-ip-blocker-and-anti-ddos'); ?>
					</button>
				</form>
			</div>
		</div>
	<?php
	}

	private static function render_tab_info(): void
	{
	?>
		<h2><?php echo esc_html__('What this plugin does', 'zaygl-ip-blocker-and-anti-ddos'); ?></h2>
		<p><?php echo esc_html__('This plugin helps you see who is visiting your website and gives you simple tools to protect it.', 'zaygl-ip-blocker-and-anti-ddos'); ?></p>

		<ul class="ipg-info-list">
			<li><?php echo esc_html__('Logs each visitor IP address, the page they visited, and their browser information', 'zaygl-ip-blocker-and-anti-ddos'); ?></li>
			<li><?php echo esc_html__('Shows the most active IPs in the last 24 hours and last 7 days', 'zaygl-ip-blocker-and-anti-ddos'); ?></li>
			<li><?php echo esc_html__('Displays recent visits with full URLs and user agents', 'zaygl-ip-blocker-and-anti-ddos'); ?></li>
			<li><?php echo esc_html__('Helps you detect suspicious or unwanted bots based on traffic behavior', 'zaygl-ip-blocker-and-anti-ddos'); ?></li>
			<li><?php echo esc_html__('Lets you block or unblock any IP with one click', 'zaygl-ip-blocker-and-anti-ddos'); ?></li>
			<li><?php echo esc_html__('Optionally detects visitor country and shows country flags', 'zaygl-ip-blocker-and-anti-ddos'); ?></li>
		</ul>

		<p><?php echo esc_html__('It is especially useful if you:', 'zaygl-ip-blocker-and-anti-ddos'); ?></p>
		<ul class="ipg-info-list">
			<li><?php echo esc_html__('Want to monitor suspicious activity or spam', 'zaygl-ip-blocker-and-anti-ddos'); ?></li>
			<li><?php echo esc_html__('Run an online shop and need to track unusual behavior', 'zaygl-ip-blocker-and-anti-ddos'); ?></li>
			<li><?php echo esc_html__('Are getting too many fake logins or bot traffic', 'zaygl-ip-blocker-and-anti-ddos'); ?></li>
			<li><?php echo esc_html__('Simply want more visibility and control over your website traffic', 'zaygl-ip-blocker-and-anti-ddos'); ?></li>
		</ul>

		<hr />

		<h2><?php echo esc_html__('Tips', 'zaygl-ip-blocker-and-anti-ddos'); ?></h2>
		<ul class="ipg-info-list">
			<li><?php echo wp_kses_post(__('If you use Cloudflare, set <strong>IP Source = Cloudflare</strong> to correctly detect real visitor IPs.', 'zaygl-ip-blocker-and-anti-ddos')); ?></li>
			<li><?php echo esc_html__('Choose a reasonable log retention period, for example 7–30 days, to keep your database clean and fast.', 'zaygl-ip-blocker-and-anti-ddos'); ?></li>
			<li><?php echo esc_html__('Use Geo detection set to “Auto” for the best balance between accuracy and simplicity.', 'zaygl-ip-blocker-and-anti-ddos'); ?></li>
		</ul>

		<h2><?php echo esc_html__('Note', 'zaygl-ip-blocker-and-anti-ddos'); ?></h2>

		<div class="ipg-info-box">
			<p><?php echo esc_html__('These numbers help you understand the nature of your traffic and identify whether activity is normal or potentially suspicious.', 'zaygl-ip-blocker-and-anti-ddos'); ?></p>

			<table class="widefat striped ipg-info-table">
				<thead>
					<tr>
						<th><?php echo esc_html__('Hits from 1 IP (24h)', 'zaygl-ip-blocker-and-anti-ddos'); ?></th>
						<th><?php echo esc_html__('Interpretation', 'zaygl-ip-blocker-and-anti-ddos'); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td>1–50</td>
						<td><?php echo esc_html__('Normal visitor activity', 'zaygl-ip-blocker-and-anti-ddos'); ?></td>
					</tr>
					<tr>
						<td>50–150</td>
						<td><?php echo esc_html__('Probably a crawler or heavy user', 'zaygl-ip-blocker-and-anti-ddos'); ?></td>
					</tr>
					<tr>
						<td>150–500</td>
						<td><?php echo esc_html__('Worth investigating', 'zaygl-ip-blocker-and-anti-ddos'); ?></td>
					</tr>
					<tr>
						<td>500–1000+</td>
						<td><?php echo esc_html__('Likely automated bot', 'zaygl-ip-blocker-and-anti-ddos'); ?></td>
					</tr>
					<tr>
						<td>2000+</td>
						<td><strong><?php echo esc_html__('Very suspicious — possible attack', 'zaygl-ip-blocker-and-anti-ddos'); ?></strong></td>
					</tr>
				</tbody>
			</table>

			<p class="ipg-info-muted">
				<?php echo esc_html__('Keep in mind that search engine bots such as Google and Bing may generate high traffic and are not always malicious. Always review the IP, URL patterns, and user agent before blocking.', 'zaygl-ip-blocker-and-anti-ddos'); ?>
			</p>
		</div>

		<div class="ipg-privacy-box">
			<h2><?php echo esc_html__('Privacy note', 'zaygl-ip-blocker-and-anti-ddos'); ?></h2>
			<p><?php echo esc_html__('This plugin stores visitor IP addresses and related visit information. In many countries, including the EU and UK, IP addresses may be considered personal data under privacy laws such as GDPR.', 'zaygl-ip-blocker-and-anti-ddos'); ?></p>
			<p><?php echo esc_html__('If you use IP logging, you should mention it in your website privacy policy. Explain what data is collected, why it is collected, and how long it is stored.', 'zaygl-ip-blocker-and-anti-ddos'); ?></p>
			<p><?php echo esc_html__('You are responsible for ensuring your website complies with applicable data protection laws.', 'zaygl-ip-blocker-and-anti-ddos'); ?></p>
		</div>
<?php
	}
}
