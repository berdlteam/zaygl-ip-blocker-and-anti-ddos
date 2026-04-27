<?php
/**
 * Plugin Name: Zaygl IP Blocker and Anti-DDoS
 * Description: Logs visitor IPs and shows top IPs (24h / 7d) with easy block/unblock.
 * Version:     1.0.0
 * Author:      Diana Hakobyan
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zaygl-ip-blocker-and-anti-ddos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'ZAYGL_IPG_VERSION' ) ) {
	define( 'ZAYGL_IPG_VERSION', '1.0.0' );
}

if ( ! defined( 'ZAYGL_IPG_FILE' ) ) {
	define( 'ZAYGL_IPG_FILE', __FILE__ );
}

if ( ! defined( 'ZAYGL_IPG_PATH' ) ) {
	define( 'ZAYGL_IPG_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'ZAYGL_IPG_URL' ) ) {
	define( 'ZAYGL_IPG_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! function_exists( 'zaygl_ipg_require' ) ) {
	/**
	 * Safely require a plugin file if it exists.
	 *
	 * @param string $rel_path Relative path from plugin root.
	 * @return void
	 */
	function zaygl_ipg_require( $rel_path ) {
		$file = ZAYGL_IPG_PATH . ltrim( $rel_path, '/' );

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}

/**
 * Load plugin files.
 * Table class must be loaded before admin render uses it.
 */
zaygl_ipg_require( 'includes/helpers.php' );
zaygl_ipg_require( 'includes/class-ipg-core.php' );
zaygl_ipg_require( 'includes/class-ipg-logger.php' );
zaygl_ipg_require( 'includes/class-ipg-geo.php' );
zaygl_ipg_require( 'includes/class-ipg-blocker.php' );
zaygl_ipg_require( 'includes/class-ipg-table.php' );
zaygl_ipg_require( 'includes/class-ipg-admin.php' );
zaygl_ipg_require( 'includes/class-ipg-ajax.php' );

register_activation_hook( __FILE__, array( 'ZAYGL_IPG_Core', 'on_activate' ) );
register_deactivation_hook( __FILE__, array( 'ZAYGL_IPG_Core', 'on_deactivate' ) );

add_action(
	'plugins_loaded',
	function() {
		if ( class_exists( 'ZAYGL_IPG_Core' ) ) {
			ZAYGL_IPG_Core::init();
		}

		if ( class_exists( 'ZAYGL_IPG_Ajax' ) ) {
			ZAYGL_IPG_Ajax::init();
		}
	}
);