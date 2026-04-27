<?php
if (! defined('ABSPATH')) {
	exit;
}

class ZAYGL_IPG_Geo
{

	/**
	 * Fast header-only country (no API, no DB).
	 * Best: Cloudflare country header.
	 */
	public static function country_from_fast_headers(): string
	{
		if (! empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
			$country_code = strtoupper(
				trim(
					sanitize_text_field(
						wp_unslash((string) $_SERVER['HTTP_CF_IPCOUNTRY'])
					)
				)
			);

			if (preg_match('/^[A-Z]{2}$/', $country_code) && 'XX' !== $country_code) {
				return $country_code;
			}
		}

		return '';
	}

	/**
	 * Resolve a country for any IP (used in admin tables).
	 * - For non-Cloudflare sites: relies on MaxMind and/or remote lookup.
	 * - On success in wp-admin: backfills logs table so future tables have country stored.
	 */
	public static function resolve_country_for_ip(string $ip): string
	{
		$ip = trim($ip);

		if (! filter_var($ip, FILTER_VALIDATE_IP)) {
			return '';
		}

		$cfg  = ZAYGL_IPG_Core::cfg();
		$mode = (string) ($cfg['geo_mode'] ?? 'auto');

		if ('off' === $mode) {
			return '';
		}

		if ('cf' === $mode) {
			$country_code = self::country_from_fast_headers();

			if ($country_code) {
				return $country_code;
			}

			if (! empty($cfg['remote_geo'])) {
				$country_code = self::remote_country($ip, (string) ($cfg['remote_geo_vendor'] ?? 'ipapi_co'));

				if ($country_code && is_admin()) {
					self::backfill_country_for_ip($ip, $country_code);
				}

				return $country_code;
			}

			return '';
		}

		if ('auto' === $mode) {
			$country_code = self::maxmind_country($ip, (string) ($cfg['maxmind_mmdb_path'] ?? ''));

			if ($country_code) {
				if (is_admin()) {
					self::backfill_country_for_ip($ip, $country_code);
				}

				return $country_code;
			}

			if (! empty($cfg['remote_geo'])) {
				$country_code = self::remote_country($ip, (string) ($cfg['remote_geo_vendor'] ?? 'ipapi_co'));

				if ($country_code && is_admin()) {
					self::backfill_country_for_ip($ip, $country_code);
				}

				return $country_code;
			}

			return '';
		}

		if ('maxmind' === $mode) {
			$country_code = self::maxmind_country($ip, (string) ($cfg['maxmind_mmdb_path'] ?? ''));

			if ($country_code && is_admin()) {
				self::backfill_country_for_ip($ip, $country_code);
			}

			return $country_code;
		}

		if ('remote' === $mode) {
			if (empty($cfg['remote_geo'])) {
				return '';
			}

			$country_code = self::remote_country($ip, (string) ($cfg['remote_geo_vendor'] ?? 'ipapi_co'));

			if ($country_code && is_admin()) {
				self::backfill_country_for_ip($ip, $country_code);
			}

			return $country_code;
		}

		return '';
	}

	/**
	 * Backfill country into logs table for this IP if missing.
	 *
	 * - Only runs in wp-admin
	 * - Only updates rows where country is NULL/empty
	 * - Throttled per IP (60s) to prevent repeated writes on big tables
	 */
	private static function backfill_country_for_ip(string $ip, string $country_code): void
	{
		if (! is_admin()) {
			return;
		}

		$ip           = trim($ip);
		$country_code = strtoupper(trim($country_code));

		if (! filter_var($ip, FILTER_VALIDATE_IP)) {
			return;
		}

		if (! preg_match('/^[A-Z]{2}$/', $country_code) || 'XX' === $country_code) {
			return;
		}

		$throttle_key = 'zaygl_ipg_geo_fill_' . md5($ip);

		if (get_transient($throttle_key)) {
			return;
		}

		set_transient($throttle_key, 1, 60);

		if (! class_exists('ZAYGL_IPG_Logger') || ! method_exists('ZAYGL_IPG_Logger', 'table_name')) {
			return;
		}

		global $wpdb;

		$table = esc_sql(ZAYGL_IPG_Logger::table_name());

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted plugin-owned table name with prepared values.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE `' . $table . '`
				SET country = %s
				WHERE ip = %s
					AND (country IS NULL OR country = \'\')',
				$country_code,
				$ip
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Remote country lookup.
	 * Cached for 7 days to reduce API calls.
	 *
	 * Key behavior:
	 * - We do not negative-cache rate limits (429/403), otherwise flags can disappear.
	 * - We only negative-cache invalid/broken responses briefly (3 minutes).
	 * - Cache prefix bumped to v3 to ignore old cached failures from earlier versions.
	 */
	public static function remote_country(string $ip, string $vendor): string
	{
		$vendor = $vendor ? $vendor : 'ipapi_co';

		$cache_key = 'zaygl_ipg_geo_v3_' . md5($vendor . '|' . $ip);

		$cached = get_transient($cache_key);

		if (is_string($cached) && '' !== $cached && '0' !== $cached) {
			return $cached;
		}

		if ('0' === $cached) {
			return '';
		}

		if (! is_admin()) {
			return '';
		}

		$country_code = '';

		$negative_cache = static function (int $seconds = 180) use ($cache_key): string {
			set_transient($cache_key, '0', max(30, (int) $seconds));
			return '';
		};

		$user_agent = 'Zaygl-IP-Blocker/' . (defined('ZAYGL_IPG_VERSION') ? ZAYGL_IPG_VERSION : '1.0.0');
		
		if ('ip_api_com' === $vendor) {
			$url      = 'https://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,countryCode,message';
			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 6,
					'headers' => array(
						'Accept'     => 'application/json',
						'User-Agent' => $user_agent,
					),
				)
			);

			if (is_wp_error($response)) {
				return $negative_cache(180);
			}

			$code = (int) wp_remote_retrieve_response_code($response);
			$body = (string) wp_remote_retrieve_body($response);

			if (429 === $code || 403 === $code) {
				return '';
			}

			if (200 !== $code || '' === $body) {
				return $negative_cache(180);
			}

			$json = json_decode($body, true);

			if (! is_array($json) || 'success' !== ($json['status'] ?? '')) {
				return $negative_cache(180);
			}

			$country_code = strtoupper(trim((string) ($json['countryCode'] ?? '')));
		} else {
			$url      = 'https://ipapi.co/' . rawurlencode($ip) . '/country/';
			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 6,
					'headers' => array(
						'Accept'     => 'text/plain',
						'User-Agent' => $user_agent,
					),
				)
			);

			if (is_wp_error($response)) {
				return $negative_cache(180);
			}

			$code = (int) wp_remote_retrieve_response_code($response);
			$body = trim((string) wp_remote_retrieve_body($response));

			if (429 === $code) {
				return '';
			}

			if (200 !== $code || '' === $body) {
				return $negative_cache(180);
			}

			$country_code = strtoupper(trim($body));
		}

		if (! preg_match('/^[A-Z]{2}$/', $country_code) || 'XX' === $country_code) {
			return $negative_cache(180);
		}

		set_transient($cache_key, $country_code, 7 * DAY_IN_SECONDS);

		return $country_code;
	}

	/**
	 * MaxMind support (optional).
	 */
	public static function maxmind_country(string $ip, string $mmdb_path): string
	{
		$mmdb_path = trim((string) $mmdb_path);

		if ('' === $mmdb_path || ! file_exists($mmdb_path)) {
			return '';
		}

		if (! class_exists('\GeoIp2\Database\Reader')) {
			return '';
		}

		try {
			$reader       = new \GeoIp2\Database\Reader($mmdb_path);
			$record       = $reader->country($ip);
			$country_code = strtoupper((string) ($record->country->isoCode ?? ''));

			return preg_match('/^[A-Z]{2}$/', $country_code) ? $country_code : '';
		} catch (\Throwable $e) {
			return '';
		}
	}

	// -----------------------
	// Emoji flag helpers
	// -----------------------

	public static function country_to_flag(string $country_code): string
	{
		$country_code = strtoupper(trim($country_code));

		if (! preg_match('/^[A-Z]{2}$/', $country_code)) {
			return '';
		}

		$first  = 127397 + ord($country_code[0]);
		$second = 127397 + ord($country_code[1]);

		if (function_exists('mb_chr')) {
			return mb_chr($first, 'UTF-8') . mb_chr($second, 'UTF-8');
		}

		return '';
	}

	public static function render_flag(string $country_code): string
	{
		$flag = self::country_to_flag($country_code);

		if ('' === $flag) {
			return '';
		}

		return '<span class="ipg-flag" aria-hidden="true">' . $flag . '</span>';
	}

	// -----------------------
	// Local SVG flag helpers
	// -----------------------

	public static function render_flag_img(string $country_code): string
	{
		$country_code = strtoupper(trim($country_code));

		if (! preg_match('/^[A-Z]{2}$/', $country_code) || 'XX' === $country_code) {
			return '';
		}

		$file = strtolower($country_code) . '.svg';

		$path = ZAYGL_IPG_PATH . 'assets/flags/' . $file;

		if (! file_exists($path)) {
			return '';
		}

		$src = ZAYGL_IPG_URL . 'assets/flags/' . $file;

		return '<img class="ipg-flag" src="' . esc_url($src) . '" alt="' . esc_attr($country_code) . '" loading="lazy" />';
	}
}
