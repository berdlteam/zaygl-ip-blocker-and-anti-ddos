<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAYGL_IPG_Blocker {

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_block_request' ), 0 );
	}

	public static function maybe_block_request(): void {
		if ( is_admin() ) {
			return;
		}

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}

		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return;
		}

		$ip = ZAYGL_IPG_Logger::get_client_ip();

		if ( ! $ip ) {
			return;
		}

		$blocked = ZAYGL_IPG_Core::blocked_list();

		if ( in_array( $ip, $blocked, true ) ) {
			status_header( 403 );
			nocache_headers();

			if ( ! headers_sent() ) {
				header( 'Content-Type: text/plain; charset=utf-8' );
			}

			echo esc_html__( '403 Forbidden', 'zaygl-ip-blocker-and-anti-ddos' );
			exit;
		}
	}
}