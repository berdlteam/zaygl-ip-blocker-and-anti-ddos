<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAYGL_IPG_Logger {

	const TABLE_LOGS = 'zaygl_ipg_logs';

	/**
	 * Return UTC datetime string for "now minus N seconds",
	 * where "now" is based on WordPress website timezone.
	 *
	 * We store created_at in UTC (current_time('mysql', true)),
	 * so all comparisons must be in UTC as well.
	 */
	private static function wp_cutoff_utc_str( int $seconds_back ): string {
		$seconds_back = max( 0, (int) $seconds_back );

		$site_tz = function_exists( 'wp_timezone' )
			? wp_timezone()
			: new DateTimeZone( wp_timezone_string() );

		$datetime = new DateTime( 'now', $site_tz );

		if ( $seconds_back > 0 ) {
			$datetime->modify( '-' . $seconds_back . ' seconds' );
		}

		$datetime->setTimezone( new DateTimeZone( 'UTC' ) );

		return $datetime->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Safe access to $_SERVER text values.
	 */
	private static function server_text( string $key ): string {
		if ( ! isset( $_SERVER[ $key ] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) );
	}

	/**
	 * Safe access to request URI.
	 */
	private static function server_request_uri(): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) );
	}

	/**
	 * Escaped plugin-owned table name for SQL usage.
	 */
	private static function table_name_sql(): string {
		global $wpdb;

		return esc_sql( $wpdb->prefix . self::TABLE_LOGS );
	}

	private static function normalize_per_page( int $per_page ): int {
		$allowed = array( 20, 50, 100, 500 );

		return in_array( $per_page, $allowed, true ) ? $per_page : 50;
	}

	private static function normalize_order( string $order ): string {
		$order = strtoupper( $order );

		return in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC';
	}

	private static function normalize_orderby( string $orderby ): string {
		return in_array( $orderby, array( 'hits', 'last_seen' ), true ) ? $orderby : 'hits';
	}

	private static function normalize_country( string $country ): string {
		$country = strtoupper( trim( $country ) );

		if ( 'UK' === $country ) {
			$country = 'GB';
		}

		if ( '' !== $country && ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
			$country = '';
		}

		return $country;
	}

	private static function sanitize_ip_search( string $ip_search ): string {
		$ip_search = trim( $ip_search );
		$ip_search = preg_replace( '/[^0-9a-fA-F\.\:\s]/', '', $ip_search );

		return trim( (string) $ip_search );
	}

	private static function order_sql_for_top( string $orderby, string $order ): string {
		$orderby = self::normalize_orderby( $orderby );
		$order   = self::normalize_order( $order );

		return ( 'hits' === $orderby ) ? "hits {$order}" : "last_seen {$order}";
	}

	private static function order_sql_for_recent( string $order ): string {
		$order = self::normalize_order( $order );

		return "created_at {$order}";
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
	private static function get_recent_total( string $cutoff_str = '' ): int {
		global $wpdb;

		if ( '' !== $cutoff_str ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom query against plugin-owned table with prepared value.
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . self::table_name_sql() . ' WHERE created_at >= %s',
					$cutoff_str
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom query against plugin-owned table.
		return (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . self::table_name_sql()
		);
	}
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
	private static function get_recent_rows( int $per_page, int $offset, string $order, string $cutoff_str = '' ): array {
		global $wpdb;

		$order_sql = self::order_sql_for_recent( $order );

		if ( '' !== $cutoff_str ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom query against plugin-owned table; ORDER BY is strictly whitelisted.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, ip, country, created_at, url, user_agent
					FROM ' . self::table_name_sql() . '
					WHERE created_at >= %s
					ORDER BY ' . $order_sql . '
					LIMIT %d OFFSET %d',
					$cutoff_str,
					$per_page,
					$offset
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom query against plugin-owned table; ORDER BY is strictly whitelisted.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, ip, country, created_at, url, user_agent
					FROM ' . self::table_name_sql() . '
					ORDER BY ' . $order_sql . '
					LIMIT %d OFFSET %d',
					$per_page,
					$offset
				),
				ARRAY_A
			);
		}

		return is_array( $rows ) ? $rows : array();
	}
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
	private static function get_top_total( string $cutoff_str, string $country = '', string $ip_search = '' ): int {
		global $wpdb;

		$country = self::normalize_country( $country );

		if ( '' !== $ip_search ) {
			$like = '%' . $wpdb->esc_like( $ip_search ) . '%';

			if ( 'GB' === $country ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom query against plugin-owned table with prepared values.
				return (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(DISTINCT ip)
						FROM ' . self::table_name_sql() . '
						WHERE created_at >= %s
							AND country IN (%s, %s)
							AND ip LIKE %s',
						$cutoff_str,
						'GB',
						'UK',
						$like
					)
				);
			}

			if ( '' !== $country ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom query against plugin-owned table with prepared values.
				return (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(DISTINCT ip)
						FROM ' . self::table_name_sql() . '
						WHERE created_at >= %s
							AND country = %s
							AND ip LIKE %s',
						$cutoff_str,
						$country,
						$like
					)
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom query against plugin-owned table with prepared values.
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(DISTINCT ip)
					FROM ' . self::table_name_sql() . '
					WHERE created_at >= %s
						AND ip LIKE %s',
					$cutoff_str,
					$like
				)
			);
		}

		if ( 'GB' === $country ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom query against plugin-owned table with prepared values.
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(DISTINCT ip)
					FROM ' . self::table_name_sql() . '
					WHERE created_at >= %s
						AND country IN (%s, %s)',
					$cutoff_str,
					'GB',
					'UK'
				)
			);
		}

		if ( '' !== $country ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom query against plugin-owned table with prepared values.
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(DISTINCT ip)
					FROM ' . self::table_name_sql() . '
					WHERE created_at >= %s
						AND country = %s',
					$cutoff_str,
					$country
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom query against plugin-owned table with prepared values.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT ip)
				FROM ' . self::table_name_sql() . '
				WHERE created_at >= %s',
				$cutoff_str
			)
		);
	}
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
	private static function get_top_rows( string $cutoff_str, int $per_page, int $offset, string $orderby, string $order, string $country = '', string $ip_search = '' ): array {
		global $wpdb;

		$order_sql = self::order_sql_for_top( $orderby, $order );
		$country   = self::normalize_country( $country );

		if ( '' !== $ip_search ) {
			$like = '%' . $wpdb->esc_like( $ip_search ) . '%';

			if ( 'GB' === $country ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom query against plugin-owned table; ORDER BY is strictly whitelisted.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT ip,
							COUNT(*) AS hits,
							MAX(created_at) AS last_seen,
							MAX(country) AS country
						FROM ' . self::table_name_sql() . '
						WHERE created_at >= %s
							AND country IN (%s, %s)
							AND ip LIKE %s
						GROUP BY ip
						ORDER BY ' . $order_sql . '
						LIMIT %d OFFSET %d',
						$cutoff_str,
						'GB',
						'UK',
						$like,
						$per_page,
						$offset
					),
					ARRAY_A
				);

				return is_array( $rows ) ? $rows : array();
			}

			if ( '' !== $country ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom query against plugin-owned table; ORDER BY is strictly whitelisted.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT ip,
							COUNT(*) AS hits,
							MAX(created_at) AS last_seen,
							MAX(country) AS country
						FROM ' . self::table_name_sql() . '
						WHERE created_at >= %s
							AND country = %s
							AND ip LIKE %s
						GROUP BY ip
						ORDER BY ' . $order_sql . '
						LIMIT %d OFFSET %d',
						$cutoff_str,
						$country,
						$like,
						$per_page,
						$offset
					),
					ARRAY_A
				);

				return is_array( $rows ) ? $rows : array();
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom query against plugin-owned table; ORDER BY is strictly whitelisted.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT ip,
						COUNT(*) AS hits,
						MAX(created_at) AS last_seen,
						MAX(country) AS country
					FROM ' . self::table_name_sql() . '
					WHERE created_at >= %s
						AND ip LIKE %s
					GROUP BY ip
					ORDER BY ' . $order_sql . '
					LIMIT %d OFFSET %d',
					$cutoff_str,
					$like,
					$per_page,
					$offset
				),
				ARRAY_A
			);

			return is_array( $rows ) ? $rows : array();
		}

		if ( 'GB' === $country ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom query against plugin-owned table; ORDER BY is strictly whitelisted.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT ip,
						COUNT(*) AS hits,
						MAX(created_at) AS last_seen,
						MAX(country) AS country
					FROM ' . self::table_name_sql() . '
					WHERE created_at >= %s
						AND country IN (%s, %s)
					GROUP BY ip
					ORDER BY ' . $order_sql . '
					LIMIT %d OFFSET %d',
					$cutoff_str,
					'GB',
					'UK',
					$per_page,
					$offset
				),
				ARRAY_A
			);

			return is_array( $rows ) ? $rows : array();
		}

		if ( '' !== $country ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom query against plugin-owned table; ORDER BY is strictly whitelisted.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT ip,
						COUNT(*) AS hits,
						MAX(created_at) AS last_seen,
						MAX(country) AS country
					FROM ' . self::table_name_sql() . '
					WHERE created_at >= %s
						AND country = %s
					GROUP BY ip
					ORDER BY ' . $order_sql . '
					LIMIT %d OFFSET %d',
					$cutoff_str,
					$country,
					$per_page,
					$offset
				),
				ARRAY_A
			);

			return is_array( $rows ) ? $rows : array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom query against plugin-owned table; ORDER BY is strictly whitelisted.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT ip,
					COUNT(*) AS hits,
					MAX(created_at) AS last_seen,
					MAX(country) AS country
				FROM ' . self::table_name_sql() . '
				WHERE created_at >= %s
				GROUP BY ip
				ORDER BY ' . $order_sql . '
				LIMIT %d OFFSET %d',
				$cutoff_str,
				$per_page,
				$offset
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

	public static function recent_visits_paged_minutes( int $page, int $per_page, int $minutes, string $order ): array {
		$page     = max( 1, (int) $page );
		$per_page = self::normalize_per_page( (int) $per_page );
		$offset   = ( $page - 1 ) * $per_page;
		$minutes  = max( 0, (int) $minutes );
		$order    = self::normalize_order( $order );

		$cutoff_str = '';

		if ( $minutes > 0 ) {
			$cutoff_str = self::wp_cutoff_utc_str( $minutes * MINUTE_IN_SECONDS );
		}

		return array(
			'rows'  => self::get_recent_rows( $per_page, $offset, $order, $cutoff_str ),
			'total' => self::get_recent_total( $cutoff_str ),
		);
	}

	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'track_visit' ), 1 );
	}

	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_LOGS;
	}

	public static function create_or_upgrade_table(): void {
		global $wpdb;

		$table           = self::table_name_sql();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE `{$table}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ip VARCHAR(64) NOT NULL,
			country CHAR(2) NULL,
			user_agent VARCHAR(255) NULL,
			url TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY ip_created (ip, created_at),
			KEY created_at (created_at),
			KEY country_created (country, created_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	public static function get_client_ip(): string {
		$cfg    = ZAYGL_IPG_Core::cfg();
		$source = (string) ( $cfg['ip_source'] ?? 'auto' );

		$remote = trim( self::server_text( 'REMOTE_ADDR' ) );
		$cf     = trim( self::server_text( 'HTTP_CF_CONNECTING_IP' ) );
		$xff    = trim( self::server_text( 'HTTP_X_FORWARDED_FOR' ) );

		$first_from_xff = static function( $value ) {
			$parts = array_map( 'trim', explode( ',', (string) $value ) );

			foreach ( $parts as $part ) {
				if ( filter_var( $part, FILTER_VALIDATE_IP ) ) {
					return $part;
				}
			}

			return '';
		};

		if ( 'remote_addr' === $source ) {
			$ip = $remote;
		} elseif ( 'cf' === $source ) {
			$ip = $cf ?: $remote;
		} elseif ( 'xff' === $source ) {
			$ip = $first_from_xff( $xff ) ?: $remote;
		} else {
			$ip = $cf ?: ( $first_from_xff( $xff ) ?: $remote );
		}

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	public static function track_visit(): void {
		if ( is_admin() ) {
			return;
		}

		$cfg = ZAYGL_IPG_Core::cfg();

		if ( ! empty( $cfg['ignore_admins'] ) && ZAYGL_IPG_Core::is_admin_user() ) {
			return;
		}

		if ( empty( $cfg['track_logged_in'] ) && is_user_logged_in() ) {
			return;
		}

		$ip = self::get_client_ip();

		if ( ! $ip ) {
			return;
		}

		$blocked = ZAYGL_IPG_Core::blocked_list();

		if ( is_array( $blocked ) && in_array( $ip, $blocked, true ) ) {
			return;
		}

		$uri = self::server_request_uri();

		if ( 0 === stripos( $uri, '/wp-json/' ) ) {
			return;
		}
		if ( 0 === stripos( $uri, '/wp-admin/' ) ) {
			return;
		}
		if ( false !== stripos( $uri, 'admin-ajax.php' ) ) {
			return;
		}

		$url = esc_url_raw( home_url( $uri ) );

		$user_agent = self::server_text( 'HTTP_USER_AGENT' );
		$user_agent = substr( $user_agent, 0, 255 );

		$key = 'zaygl_ipg_' . md5( $ip . '|' . $uri );

		if ( get_transient( $key ) ) {
			return;
		}

		set_transient( $key, 1, 60 );

		$country = ZAYGL_IPG_Geo::country_from_fast_headers();
		$country = $country ? strtoupper( (string) $country ) : '';

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Standard insert into plugin-owned log table.
		$wpdb->insert(
			self::table_name(),
			array(
				'ip'         => $ip,
				'country'    => $country ?: null,
				'user_agent' => $user_agent,
				'url'        => $url,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
	public static function cleanup_logs(): void {
		$cfg        = ZAYGL_IPG_Core::cfg();
		$days       = max( 1, (int) ( $cfg['retention_days'] ?? 30 ) );
		$cutoff_utc = self::wp_cutoff_utc_str( $days * DAY_IN_SECONDS );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Deleting old rows from plugin-owned table with prepared cutoff.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::table_name_sql() . ' WHERE created_at < %s',
				$cutoff_utc
			)
		);
	}
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

	/* =========================================================
	 * Top tables (hours-based)
	 * ======================================================= */

	public static function top_ips_paged( $hours, $page, $per_page, $orderby, $order, $country = '', $ip_search = '' ): array {
		$hours     = max( 1, (int) $hours );
		$page      = max( 1, (int) $page );
		$per_page  = self::normalize_per_page( (int) $per_page );
		$offset    = ( $page - 1 ) * $per_page;
		$orderby   = self::normalize_orderby( (string) $orderby );
		$order     = self::normalize_order( (string) $order );
		$country   = self::normalize_country( (string) $country );
		$ip_search = self::sanitize_ip_search( (string) $ip_search );

		$cutoff_str = self::wp_cutoff_utc_str( $hours * HOUR_IN_SECONDS );

		return array(
			'rows'  => self::get_top_rows( $cutoff_str, $per_page, $offset, $orderby, $order, $country, $ip_search ),
			'total' => self::get_top_total( $cutoff_str, $country, $ip_search ),
		);
	}

	/* =========================================================
	 * Top tables (minutes-based)
	 * ======================================================= */

	public static function top_ips_paged_minutes( $minutes, $page, $per_page, $orderby, $order, $country = '', $ip_search = '' ): array {
		$minutes   = max( 1, (int) $minutes );
		$page      = max( 1, (int) $page );
		$per_page  = self::normalize_per_page( (int) $per_page );
		$offset    = ( $page - 1 ) * $per_page;
		$orderby   = self::normalize_orderby( (string) $orderby );
		$order     = self::normalize_order( (string) $order );
		$country   = self::normalize_country( (string) $country );
		$ip_search = self::sanitize_ip_search( (string) $ip_search );

		$cutoff_str = self::wp_cutoff_utc_str( $minutes * MINUTE_IN_SECONDS );

		return array(
			'rows'  => self::get_top_rows( $cutoff_str, $per_page, $offset, $orderby, $order, $country, $ip_search ),
			'total' => self::get_top_total( $cutoff_str, $country, $ip_search ),
		);
	}

	/* =========================================================
	 * Countries (hours/minutes)
	 * ======================================================= */

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
	public static function top_countries( int $hours ): array {
		global $wpdb;

		$hours      = max( 1, (int) $hours );
		$cutoff_str = self::wp_cutoff_utc_str( $hours * HOUR_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Querying distinct countries from plugin-owned table.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT country
				FROM ' . self::table_name_sql() . '
				WHERE created_at >= %s
					AND country IS NOT NULL
					AND country <> \'\'
				ORDER BY country ASC',
				$cutoff_str
			)
		);

		$out = array();

		foreach ( (array) $rows as $country_code ) {
			$country_code = strtoupper( trim( (string) $country_code ) );

			if ( preg_match( '/^[A-Z]{2}$/', $country_code ) && 'XX' !== $country_code ) {
				$out[] = $country_code;
			}
		}

		return array_values( array_unique( $out ) );
	}
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
	public static function top_countries_minutes( int $minutes ): array {
		global $wpdb;

		$minutes    = max( 1, (int) $minutes );
		$cutoff_str = self::wp_cutoff_utc_str( $minutes * MINUTE_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Querying distinct countries from plugin-owned table.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT country
				FROM ' . self::table_name_sql() . '
				WHERE created_at >= %s
					AND country IS NOT NULL
					AND country <> \'\'
				ORDER BY country ASC',
				$cutoff_str
			)
		);

		$out = array();

		foreach ( (array) $rows as $country_code ) {
			$country_code = strtoupper( trim( (string) $country_code ) );

			if ( preg_match( '/^[A-Z]{2}$/', $country_code ) && 'XX' !== $country_code ) {
				$out[] = $country_code;
			}
		}

		return array_values( array_unique( $out ) );
	}
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

	/* =========================================================
	 * Recent table
	 * ======================================================= */

	public static function recent_visits_paged( int $page, int $per_page, int $hours, string $order ): array {
		$page     = max( 1, (int) $page );
		$per_page = self::normalize_per_page( (int) $per_page );
		$offset   = ( $page - 1 ) * $per_page;
		$hours    = max( 0, (int) $hours );
		$order    = self::normalize_order( $order );

		$cutoff_str = '';

		if ( $hours > 0 ) {
			$cutoff_str = self::wp_cutoff_utc_str( $hours * HOUR_IN_SECONDS );
		}

		return array(
			'rows'  => self::get_recent_rows( $per_page, $offset, $order, $cutoff_str ),
			'total' => self::get_recent_total( $cutoff_str ),
		);
	}
}