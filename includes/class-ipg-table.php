<?php
if (! defined('ABSPATH')) {
	exit;
}

class ZAYGL_IPG_Table
{

	public static function export_fmt_dt($mysql_dt): string
	{
		return (string) self::fmt_dt($mysql_dt);
	}

	private static function sanitize_ip_search(string $search): string
	{
		$search = trim($search);
		$search = (string) preg_replace('/[^0-9a-fA-F\.\:\s]/', '', $search);

		return trim($search);
	}

	/**
	 * Format a MySQL DATETIME string for display in WP timezone.
	 *
	 * @param mixed $mysql_dt MySQL datetime string.
	 * @return string
	 */
	private static function fmt_dt($mysql_dt): string
	{
		$value = trim((string) $mysql_dt);

		if ('' === $value || '0000-00-00 00:00:00' === $value) {
			return '';
		}

		$mode = apply_filters('zaygl_ipg_dt_storage_mode', 'auto');
		$mode = is_string($mode) ? strtolower($mode) : 'auto';

		if (! in_array($mode, array('auto', 'utc', 'local'), true)) {
			$mode = 'auto';
		}

		try {
			$wp_tz = wp_timezone();

			if ('utc' === $mode) {
				$dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
				return $dt->setTimezone($wp_tz)->format('H:i d.m.y');
			}

			if ('local' === $mode) {
				$dt = new DateTimeImmutable($value, $wp_tz);
				return $dt->format('H:i d.m.y');
			}

			$dt_utc  = new DateTimeImmutable($value, new DateTimeZone('UTC'));
			$as_wp_1 = $dt_utc->setTimezone($wp_tz);

			$dt_local = new DateTimeImmutable($value, $wp_tz);
			$as_wp_2  = $dt_local;

			$now = time();
			$t1  = $as_wp_1->getTimestamp();
			$t2  = $as_wp_2->getTimestamp();

			$d1 = abs($now - $t1);
			$d2 = abs($now - $t2);

			$very_old = 365 * DAY_IN_SECONDS;
			$chosen   = ($d1 < $d2 && $d1 < $very_old) ? $as_wp_1 : $as_wp_2;

			return $chosen->format('H:i d.m.y');
		} catch (Exception $e) {
			return '';
		}
	}

	private static function per_page_select(string $table_key, int $per_page): void
	{
		$options = array(20, 50, 100, 500);

		echo '<label class="ipg-per-page-label">';
		echo '<span class="ipg-per-page-text">' . esc_html__('Rows:', 'zaygl-ip-blocker-and-anti-ddos') . '</span>';
		echo '<select class="ipg-per-page" data-table="' . esc_attr($table_key) . '">';

		foreach ($options as $option) {
			printf(
				'<option value="%1$d"%2$s>%1$d</option>',
				(int) $option,
				selected((int) $per_page, (int) $option, false)
			);
		}

		echo '</select>';
		echo '</label>';
	}

	private static function pagination(string $table_key, int $page, int $per_page, int $total): void
	{
		$total_pages = (int) ceil(max(1, $total) / max(1, $per_page));

		if ($total_pages <= 1) {
			return;
		}

		$prev = max(1, $page - 1);
		$next = min($total_pages, $page + 1);

		$prev_disabled = ($page <= 1);
		$next_disabled = ($page >= $total_pages);

		$prev_class = $prev_disabled ? ' button disabled' : ' button';
		$next_class = $next_disabled ? ' button disabled' : ' button';

		echo '<div class="tablenav bottom ipg-tablenav-bottom">';
		echo '<div class="tablenav-pages">';
		echo '<span class="displaying-num">' . esc_html((string) $total) . ' ' . esc_html__('items', 'zaygl-ip-blocker-and-anti-ddos') . '</span> ';

		if ($prev_disabled) {
			echo '<a href="#" class="ipg-page' . esc_attr($prev_class) . '" data-table="' . esc_attr($table_key) . '" data-page="1" aria-disabled="true" tabindex="-1">&laquo;</a> ';
			echo '<a href="#" class="ipg-page' . esc_attr($prev_class) . '" data-table="' . esc_attr($table_key) . '" data-page="' . esc_attr((string) $prev) . '" aria-disabled="true" tabindex="-1">&lsaquo;</a> ';
		} else {
			echo '<a href="#" class="ipg-page' . esc_attr($prev_class) . '" data-table="' . esc_attr($table_key) . '" data-page="1">&laquo;</a> ';
			echo '<a href="#" class="ipg-page' . esc_attr($prev_class) . '" data-table="' . esc_attr($table_key) . '" data-page="' . esc_attr((string) $prev) . '">&lsaquo;</a> ';
		}

		echo '<span class="ipg-page-status">';
		echo esc_html__('Page', 'zaygl-ip-blocker-and-anti-ddos') . ' <strong>' . esc_html((string) $page) . '</strong> ';
		echo esc_html__('of', 'zaygl-ip-blocker-and-anti-ddos') . ' <strong>' . esc_html((string) $total_pages) . '</strong>';
		echo '</span>';

		if ($next_disabled) {
			echo ' <a href="#" class="ipg-page' . esc_attr($next_class) . '" data-table="' . esc_attr($table_key) . '" data-page="' . esc_attr((string) $next) . '" aria-disabled="true" tabindex="-1">&rsaquo;</a> ';
			echo '<a href="#" class="ipg-page' . esc_attr($next_class) . '" data-table="' . esc_attr($table_key) . '" data-page="' . esc_attr((string) $total_pages) . '" aria-disabled="true" tabindex="-1">&raquo;</a>';
		} else {
			echo ' <a href="#" class="ipg-page' . esc_attr($next_class) . '" data-table="' . esc_attr($table_key) . '" data-page="' . esc_attr((string) $next) . '">&rsaquo;</a> ';
			echo '<a href="#" class="ipg-page' . esc_attr($next_class) . '" data-table="' . esc_attr($table_key) . '" data-page="' . esc_attr((string) $total_pages) . '">&raquo;</a>';
		}

		echo '</div>';
		echo '</div>';
	}

	private static function sort_link(string $table_key, string $label, string $orderby_key, string $current_orderby, string $current_order): void
	{
		$is            = ($current_orderby === $orderby_key);
		$current_order = strtoupper((string) $current_order);

		if (! in_array($current_order, array('ASC', 'DESC'), true)) {
			$current_order = 'DESC';
		}

		$next_order = ($is && 'DESC' === $current_order) ? 'ASC' : 'DESC';
		$arrow      = $is ? (('DESC' === $current_order) ? ' ▼' : ' ▲') : '';

		printf(
			'<a href="#" class="ipg-sort" data-table="%1$s" data-orderby="%2$s" data-order="%3$s">%4$s%5$s</a>',
			esc_attr($table_key),
			esc_attr($orderby_key),
			esc_attr($next_order),
			esc_html($label),
			esc_html($arrow)
		);
	}

	private static function normalize_cc(string $country_code): string
	{
		$country_code = strtoupper(trim($country_code));

		if ('UK' === $country_code) {
			$country_code = 'GB';
		}

		return $country_code;
	}

	private static function country_name(string $country_code): string
	{
		$country_code = self::normalize_cc($country_code);

		if (! preg_match('/^[A-Z]{2}$/', $country_code) || 'XX' === $country_code) {
			return $country_code;
		}

		if (class_exists('WC_Countries')) {
			$wc        = new WC_Countries();
			$countries = (array) $wc->get_countries();

			if (! empty($countries[$country_code])) {
				$name = (string) $countries[$country_code];

				if ('GB' === $country_code) {
					$name = preg_replace('/\s*\(UK\)\s*$/u', '', $name);
					$name = trim((string) $name);
				}

				return $name;
			}
		}

		static $map = array(
			'AF' => 'Afghanistan',
			'AL' => 'Albania',
			'DZ' => 'Algeria',
			'AS' => 'American Samoa',
			'AD' => 'Andorra',
			'AO' => 'Angola',
			'AI' => 'Anguilla',
			'AQ' => 'Antarctica',
			'AG' => 'Antigua and Barbuda',
			'AR' => 'Argentina',
			'AM' => 'Armenia',
			'AW' => 'Aruba',
			'AU' => 'Australia',
			'AT' => 'Austria',
			'AZ' => 'Azerbaijan',
			'BS' => 'Bahamas',
			'BH' => 'Bahrain',
			'BD' => 'Bangladesh',
			'BB' => 'Barbados',
			'BY' => 'Belarus',
			'BE' => 'Belgium',
			'BZ' => 'Belize',
			'BJ' => 'Benin',
			'BM' => 'Bermuda',
			'BT' => 'Bhutan',
			'BO' => 'Bolivia',
			'BA' => 'Bosnia and Herzegovina',
			'BW' => 'Botswana',
			'BR' => 'Brazil',
			'BN' => 'Brunei',
			'BG' => 'Bulgaria',
			'BF' => 'Burkina Faso',
			'BI' => 'Burundi',
			'KH' => 'Cambodia',
			'CM' => 'Cameroon',
			'CA' => 'Canada',
			'CV' => 'Cape Verde',
			'KY' => 'Cayman Islands',
			'CF' => 'Central African Republic',
			'TD' => 'Chad',
			'CL' => 'Chile',
			'CN' => 'China',
			'CO' => 'Colombia',
			'KM' => 'Comoros',
			'CG' => 'Congo',
			'CD' => 'Congo (Democratic Republic)',
			'CR' => 'Costa Rica',
			'CI' => 'Côte d’Ivoire',
			'HR' => 'Croatia',
			'CU' => 'Cuba',
			'CY' => 'Cyprus',
			'CZ' => 'Czech Republic',
			'DK' => 'Denmark',
			'DJ' => 'Djibouti',
			'DM' => 'Dominica',
			'DO' => 'Dominican Republic',
			'EC' => 'Ecuador',
			'EG' => 'Egypt',
			'SV' => 'El Salvador',
			'GQ' => 'Equatorial Guinea',
			'ER' => 'Eritrea',
			'EE' => 'Estonia',
			'ET' => 'Ethiopia',
			'FJ' => 'Fiji',
			'FI' => 'Finland',
			'FR' => 'France',
			'GF' => 'French Guiana',
			'GA' => 'Gabon',
			'GM' => 'Gambia',
			'GE' => 'Georgia',
			'DE' => 'Germany',
			'GH' => 'Ghana',
			'GI' => 'Gibraltar',
			'GR' => 'Greece',
			'GL' => 'Greenland',
			'GD' => 'Grenada',
			'GP' => 'Guadeloupe',
			'GU' => 'Guam',
			'GT' => 'Guatemala',
			'GN' => 'Guinea',
			'GW' => 'Guinea-Bissau',
			'GY' => 'Guyana',
			'HT' => 'Haiti',
			'HN' => 'Honduras',
			'HK' => 'Hong Kong',
			'HU' => 'Hungary',
			'IS' => 'Iceland',
			'IN' => 'India',
			'ID' => 'Indonesia',
			'IR' => 'Iran',
			'IQ' => 'Iraq',
			'IE' => 'Ireland',
			'IL' => 'Israel',
			'IT' => 'Italy',
			'JM' => 'Jamaica',
			'JP' => 'Japan',
			'JO' => 'Jordan',
			'KZ' => 'Kazakhstan',
			'KE' => 'Kenya',
			'KI' => 'Kiribati',
			'KW' => 'Kuwait',
			'KG' => 'Kyrgyzstan',
			'LA' => 'Laos',
			'LV' => 'Latvia',
			'LB' => 'Lebanon',
			'LS' => 'Lesotho',
			'LR' => 'Liberia',
			'LY' => 'Libya',
			'LI' => 'Liechtenstein',
			'LT' => 'Lithuania',
			'LU' => 'Luxembourg',
			'MO' => 'Macau',
			'MK' => 'North Macedonia',
			'MG' => 'Madagascar',
			'MW' => 'Malawi',
			'MY' => 'Malaysia',
			'MV' => 'Maldives',
			'ML' => 'Mali',
			'MT' => 'Malta',
			'MH' => 'Marshall Islands',
			'MQ' => 'Martinique',
			'MR' => 'Mauritania',
			'MU' => 'Mauritius',
			'MX' => 'Mexico',
			'FM' => 'Micronesia',
			'MD' => 'Moldova',
			'MC' => 'Monaco',
			'MN' => 'Mongolia',
			'ME' => 'Montenegro',
			'MA' => 'Morocco',
			'MZ' => 'Mozambique',
			'MM' => 'Myanmar',
			'NA' => 'Namibia',
			'NR' => 'Nauru',
			'NP' => 'Nepal',
			'NL' => 'Netherlands',
			'NZ' => 'New Zealand',
			'NI' => 'Nicaragua',
			'NE' => 'Niger',
			'NG' => 'Nigeria',
			'KP' => 'North Korea',
			'NO' => 'Norway',
			'OM' => 'Oman',
			'PK' => 'Pakistan',
			'PA' => 'Panama',
			'PG' => 'Papua New Guinea',
			'PY' => 'Paraguay',
			'PE' => 'Peru',
			'PH' => 'Philippines',
			'PL' => 'Poland',
			'PT' => 'Portugal',
			'PR' => 'Puerto Rico',
			'QA' => 'Qatar',
			'RO' => 'Romania',
			'RU' => 'Russia',
			'RW' => 'Rwanda',
			'KN' => 'Saint Kitts and Nevis',
			'LC' => 'Saint Lucia',
			'VC' => 'Saint Vincent and the Grenadines',
			'WS' => 'Samoa',
			'SM' => 'San Marino',
			'ST' => 'Sao Tome and Principe',
			'SA' => 'Saudi Arabia',
			'SN' => 'Senegal',
			'RS' => 'Serbia',
			'SC' => 'Seychelles',
			'SL' => 'Sierra Leone',
			'SG' => 'Singapore',
			'SK' => 'Slovakia',
			'SI' => 'Slovenia',
			'SB' => 'Solomon Islands',
			'SO' => 'Somalia',
			'ZA' => 'South Africa',
			'KR' => 'South Korea',
			'ES' => 'Spain',
			'LK' => 'Sri Lanka',
			'SD' => 'Sudan',
			'SR' => 'Suriname',
			'SE' => 'Sweden',
			'CH' => 'Switzerland',
			'SY' => 'Syria',
			'TW' => 'Taiwan',
			'TJ' => 'Tajikistan',
			'TZ' => 'Tanzania',
			'TH' => 'Thailand',
			'TL' => 'Timor-Leste',
			'TG' => 'Togo',
			'TO' => 'Tonga',
			'TT' => 'Trinidad and Tobago',
			'TN' => 'Tunisia',
			'TR' => 'Turkey',
			'TM' => 'Turkmenistan',
			'UG' => 'Uganda',
			'UA' => 'Ukraine',
			'AE' => 'United Arab Emirates',
			'GB' => 'United Kingdom',
			'US' => 'United States',
			'UY' => 'Uruguay',
			'UZ' => 'Uzbekistan',
			'VU' => 'Vanuatu',
			'VA' => 'Vatican City',
			'VE' => 'Venezuela',
			'VN' => 'Vietnam',
			'YE' => 'Yemen',
			'ZM' => 'Zambia',
			'ZW' => 'Zimbabwe',
		);

		return $map[$country_code] ?? $country_code;
	}

	private static function country_code_to_emoji(string $country_code): string
	{
		$country_code = self::normalize_cc($country_code);

		if (! preg_match('/^[A-Z]{2}$/', $country_code) || 'XX' === $country_code) {
			return '';
		}

		$first  = 127397 + ord($country_code[0]);
		$second = 127397 + ord($country_code[1]);

		return html_entity_decode(
			'&#' . $first . ';&#' . $second . ';',
			ENT_QUOTES,
			'UTF-8'
		);
	}

	/**
	 * Returns safe local HTML for a country flag display.
	 * No remote images, no inline JS.
	 *
	 * @param string $country_code Country code.
	 * @return string
	 */
	private static function safe_flag_html(string $country_code): string
	{
		$country_code = self::normalize_cc($country_code);

		if ($country_code && preg_match('/^[A-Z]{2}$/', $country_code) && 'XX' !== $country_code) {
			$image_html = (string) ZAYGL_IPG_Geo::render_flag_img($country_code);

			if ('' !== $image_html) {
				return $image_html;
			}

			$emoji_html = (string) ZAYGL_IPG_Geo::render_flag($country_code);

			if ('' !== $emoji_html) {
				return $emoji_html;
			}
		}

		return '<span class="ipg-flag ipg-flag-placeholder" aria-hidden="true">🏳️</span>';
	}
	private static function country_suffix_html(string $country_code): string
	{
		$country_code = self::normalize_cc($country_code);

		if ($country_code && preg_match('/^[A-Z]{2}$/', $country_code) && 'XX' !== $country_code) {
			return '<span class="ipg-country-code">' . esc_html($country_code) . '</span>';
		}

		return '';
	}

	private static function hits_html(int $hits): string
	{
		$hits_text = esc_html((string) $hits);

		if ($hits >= 1000) {
			return '<strong class="ipg-hits ipg-hits-high">' . $hits_text . '</strong>';
		}

		if ($hits >= 100) {
			return '<strong class="ipg-hits ipg-hits-medium">' . $hits_text . '</strong>';
		}

		return '<span class="ipg-hits">' . $hits_text . '</span>';
	}

	private static function allowed_inline_html(): array
	{
		return array(
			'span'   => array(
				'class'       => true,
				'aria-hidden' => true,
			),
			'strong' => array(
				'class' => true,
			),
			'img'    => array(
				'class'   => true,
				'src'     => true,
				'alt'     => true,
				'loading' => true,
			),
		);
	}

	public static function render_top_ips_table(array $args): void
	{
		$table_key      = (string) ($args['table_key'] ?? '');
		$rows           = (array) ($args['rows'] ?? array());
		$total          = (int) ($args['total'] ?? 0);
		$page           = (int) ($args['page'] ?? 1);
		$per_page       = (int) ($args['per_page'] ?? 50);
		$orderby        = (string) ($args['orderby'] ?? 'hits');
		$order          = (string) ($args['order'] ?? 'DESC');
		$blocked        = (array) ($args['blocked'] ?? array());
		$ip_search      = self::sanitize_ip_search((string) ($args['ip_search'] ?? ''));
		$country_filter = self::normalize_cc((string) ($args['country'] ?? ''));

		if ('' !== $country_filter && ! preg_match('/^[A-Z]{2}$/', $country_filter)) {
			$country_filter = '';
		}

		$countries = (array) ($args['countries'] ?? array());

		echo '<div class="ipg-table-wrap"';
		echo ' data-ipg-table="' . esc_attr($table_key) . '"';
		echo ' data-country="' . esc_attr($country_filter) . '"';
		echo ' data-orderby="' . esc_attr($orderby) . '"';
		echo ' data-order="' . esc_attr($order) . '"';
		echo ' data-ip-search="' . esc_attr($ip_search) . '"';
		echo ' data-page="' . esc_attr((string) $page) . '"';
		echo ' data-per-page="' . esc_attr((string) $per_page) . '"';
		echo '>';

		echo '<div class="ipg-table-toolbar">';

		echo '<div class="ipg-table-toolbar-left">';
		echo '<strong class="ipg-toolbar-label">' . esc_html__('Country:', 'zaygl-ip-blocker-and-anti-ddos') . '</strong>';

		echo '<select class="ipg-country-filter" data-table="' . esc_attr($table_key) . '">';
		echo '<option value="">' . esc_html__('All countries', 'zaygl-ip-blocker-and-anti-ddos') . '</option>';

		$normalized_countries = array();

		foreach ($countries as $country) {
			$country = self::normalize_cc((string) $country);

			if (preg_match('/^[A-Z]{2}$/', $country) && 'XX' !== $country) {
				$normalized_countries[] = $country;
			}
		}

		$countries = array_values(array_unique($normalized_countries));
		sort($countries);

		foreach ($countries as $country_code) {
			$name           = self::country_name($country_code);
			$ends_with_code = (bool) preg_match('/\(\s*' . preg_quote($country_code, '/') . '\s*\)\s*$/u', $name);
			$label          = $ends_with_code ? $name : ($name . ' (' . $country_code . ')');

			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr($country_code),
				selected($country_filter, $country_code, false),
				esc_html($label)
			);
		}

		echo '</select>';
		echo '</div>';

		echo '<div class="ipg-table-toolbar-center">';
		echo '<button type="button" class="button ipg-refresh" data-table="' . esc_attr($table_key) . '" title="' . esc_attr__('Refresh table', 'zaygl-ip-blocker-and-anti-ddos') . '">' . esc_html__('refresh', 'zaygl-ip-blocker-and-anti-ddos') . '</button>';
		echo '<button type="button" class="button ipg-export-csv" data-table="' . esc_attr($table_key) . '" title="' . esc_attr__('Export this range to CSV', 'zaygl-ip-blocker-and-anti-ddos') . '">' . esc_html__('export CSV', 'zaygl-ip-blocker-and-anti-ddos') . '</button>';
		echo '<button type="button" class="button ipg-export-pdf" data-table="' . esc_attr($table_key) . '" title="' . esc_attr__('Open printable view (save as PDF)', 'zaygl-ip-blocker-and-anti-ddos') . '">' . esc_html__('Save as PDF', 'zaygl-ip-blocker-and-anti-ddos') . '</button>';
		echo '<span class="ipg-refresh-msg">' . esc_html__('Refreshing…', 'zaygl-ip-blocker-and-anti-ddos') . '</span>';
		echo '</div>';

		echo '<div class="ipg-table-toolbar-right">';
		self::per_page_select($table_key, $per_page);
		echo '</div>';

		echo '</div>';

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th class="ipg-checkbox-col">';
		echo '<input type="checkbox" class="ipg-select-all-page" title="' . esc_attr__('Select all rows on this page', 'zaygl-ip-blocker-and-anti-ddos') . '" />';
		echo '</th>';
		echo '<th>' . esc_html__('IP', 'zaygl-ip-blocker-and-anti-ddos') . '</th>';
		echo '<th>';
		self::sort_link($table_key, __('Hits', 'zaygl-ip-blocker-and-anti-ddos'), 'hits', $orderby, $order);
		echo '</th>';
		echo '<th>';
		self::sort_link($table_key, __('Last seen', 'zaygl-ip-blocker-and-anti-ddos'), 'last_seen', $orderby, $order);
		echo ' ' . esc_html__('(WordPress time)', 'zaygl-ip-blocker-and-anti-ddos') . '</th>';
		echo '<th>' . esc_html__('Action', 'zaygl-ip-blocker-and-anti-ddos') . '</th>';
		echo '</tr></thead>';

		echo '<tbody>';

		if (empty($rows)) {
			echo '<tr><td colspan="6">' . esc_html__('No data yet.', 'zaygl-ip-blocker-and-anti-ddos') . '</td></tr>';
		} else {
			$cc_cache = array();

			foreach ($rows as $row) {
				$ip        = (string) ($row['ip'] ?? '');
				$hits      = (int) ($row['hits'] ?? 0);
				$last_seen = (string) ($row['last_seen'] ?? '');
				$cc        = (string) ($row['country'] ?? '');

				if ('' !== $ip && ('' === $cc || ! preg_match('/^[A-Z]{2}$/', $cc))) {
					if (! isset($cc_cache[$ip])) {
						$cc_cache[$ip] = (string) ZAYGL_IPG_Geo::resolve_country_for_ip($ip);
					}

					$cc = (string) $cc_cache[$ip];
				}

				$cc        = self::normalize_cc($cc);
				$flag      = self::safe_flag_html($cc);
				$suffix    = self::country_suffix_html($cc);
				$hits_html = self::hits_html($hits);

				$is_blocked = ($ip && in_array($ip, $blocked, true));
				$action     = $is_blocked ? 'unblock' : 'block';
				$label      = $is_blocked ? __('unblock', 'zaygl-ip-blocker-and-anti-ddos') : __('block', 'zaygl-ip-blocker-and-anti-ddos');
				$class      = $is_blocked ? 'button' : 'button button-primary';

				$action_url = add_query_arg(
					array(
						'action'   => 'zaygl_ipg_action',
						'do'       => $action,
						'ip'       => $ip,
						'_wpnonce' => wp_create_nonce('zaygl_ipg_nonce'),
					),
					admin_url('admin-post.php')
				);

				echo '<tr>';
				echo '<td class="ipg-checkbox-col">';
				echo '<input type="checkbox" class="ipg-row-select" value="' . esc_attr($ip) . '" />';
				echo '</td>';
				echo '<td>' . wp_kses($flag, self::allowed_inline_html()) . '<code>' . esc_html($ip) . '</code>' . wp_kses($suffix, self::allowed_inline_html()) . '</td>';
				echo '<td>' . wp_kses($hits_html, self::allowed_inline_html()) . '</td>';
				echo '<td>' . esc_html(self::fmt_dt($last_seen)) . '</td>';
				echo '<td>';

				if ('' !== $ip) {
					echo '<a class="' . esc_attr($class) . '" href="' . esc_url($action_url) . '">' . esc_html($label) . '</a>';
				}

				echo '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';

		self::pagination($table_key, $page, $per_page, $total);
		echo '</div>';
	}

	public static function render_recent_table(array $args): void
	{
		$rows         = (array) ($args['rows'] ?? array());
		$total        = (int) ($args['total'] ?? 0);
		$page         = (int) ($args['page'] ?? 1);
		$per_page     = (int) ($args['per_page'] ?? 50);
		$order        = strtoupper((string) ($args['order'] ?? 'DESC'));
		$recent_hours = (int) ($args['recent_hours'] ?? 24);
		$blocked      = (array) ($args['blocked'] ?? array());
		$title        = (string) ($args['title'] ?? '');

		if (! in_array($order, array('ASC', 'DESC'), true)) {
			$order = 'DESC';
		}

		$arrow      = ('DESC' === $order) ? ' ▼' : ' ▲';
		$next_order = ('DESC' === $order) ? 'ASC' : 'DESC';

		echo '<div class="ipg-table-wrap ipg-table-wrap-recent" data-ipg-table="recent" data-orderby="created_at" data-order="' . esc_attr($order) . '" data-page="' . esc_attr((string) $page) . '" data-per-page="' . esc_attr((string) $per_page) . '" data-recent-hours="' . esc_attr((string) $recent_hours) . '">';

		echo '<div class="ipg-table-toolbar">';
		echo '<div class="ipg-table-toolbar-left">';
		echo '<strong class="ipg-toolbar-label">' . esc_html($title) . '</strong>';
		echo '</div>';
		echo '<div class="ipg-table-toolbar-right">';
		self::per_page_select('recent', $per_page);
		echo '</div>';
		echo '</div>';

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>';

		printf(
			'<a href="#" class="ipg-sort" data-table="recent" data-orderby="created_at" data-order="%1$s">%2$s%3$s</a>',
			esc_attr($next_order),
			esc_html__('Time', 'zaygl-ip-blocker-and-anti-ddos'),
			esc_html($arrow)
		);

		echo '</th>';
		echo '<th>' . esc_html__('IP', 'zaygl-ip-blocker-and-anti-ddos') . '</th>';
		echo '<th>' . esc_html__('URL', 'zaygl-ip-blocker-and-anti-ddos') . '</th>';
		echo '<th>' . esc_html__('User Agent', 'zaygl-ip-blocker-and-anti-ddos') . '</th>';
		echo '<th>' . esc_html__('Action', 'zaygl-ip-blocker-and-anti-ddos') . '</th>';
		echo '</tr></thead>';

		echo '<tbody>';

		if (empty($rows)) {
			echo '<tr><td colspan="6">' . esc_html__('No data.', 'zaygl-ip-blocker-and-anti-ddos') . '</td></tr>';
		} else {
			$cc_cache = array();

			foreach ($rows as $row) {
				$ip = (string) ($row['ip'] ?? '');
				$cc = (string) ($row['country'] ?? '');

				if ('' !== $ip && ('' === $cc || ! preg_match('/^[A-Z]{2}$/', $cc))) {
					if (! isset($cc_cache[$ip])) {
						$cc_cache[$ip] = (string) ZAYGL_IPG_Geo::resolve_country_for_ip($ip);
					}

					$cc = (string) $cc_cache[$ip];
				}

				$cc = self::normalize_cc($cc);

				$flag   = self::safe_flag_html($cc);
				$suffix = self::country_suffix_html($cc);

				$is_blocked = ($ip && in_array($ip, $blocked, true));
				$action     = $is_blocked ? 'unblock' : 'block';
				$label      = $is_blocked ? __('unblock', 'zaygl-ip-blocker-and-anti-ddos') : __('block', 'zaygl-ip-blocker-and-anti-ddos');
				$class      = $is_blocked ? 'button' : 'button button-primary';

				$action_url = admin_url(
					'admin-post.php?' . http_build_query(
						array(
							'action'   => 'zaygl_ipg_action',
							'do'       => $action,
							'ip'       => $ip,
							'_wpnonce' => wp_create_nonce('zaygl_ipg_nonce'),
						)
					)
				);

				$url        = (string) ($row['url'] ?? '');
				$user_agent = (string) ($row['user_agent'] ?? '');
				$created_at = (string) ($row['created_at'] ?? '');

				echo '<tr>';
				echo '<td>' . esc_html(self::fmt_dt($created_at)) . '</td>';
				echo '<td>' . wp_kses($flag, self::allowed_inline_html()) . '<code>' . esc_html($ip) . '</code>' . wp_kses($suffix, self::allowed_inline_html()) . '</td>';
				echo '<td class="ipg-col-url">';
				echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($url) . '</a>';
				echo '</td>';
				echo '<td class="ipg-col-user-agent">' . esc_html($user_agent) . '</td>';
				echo '<td>';

				if ('' !== $ip) {
					echo '<a class="' . esc_attr($class) . '" href="' . esc_url($action_url) . '">' . esc_html($label) . '</a>';
				}

				echo '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';

		self::pagination('recent', $page, $per_page, $total);
		echo '</div>';
	}
}
