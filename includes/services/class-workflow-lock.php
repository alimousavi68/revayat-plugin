<?php
/** Short, owner-bound locks for workflow transitions and OTP consumption. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Revayat_Companion_Workflow_Lock {
	public static function run( $resource, $callback ) {
		global $wpdb;
		$key = '_rv_lock_' . hash( 'sha256', $resource );
		$old = get_option( $key );
		if ( $old && (int) $old < time() ) { self::release( $key, $old ); }
		$token = ( time() + 90 ) . '|' . wp_generate_uuid4();
		if ( ! add_option( $key, $token, '', false ) ) { return new WP_Error( 'workflow_busy', 'درخواست دیگری در حال پردازش است؛ چند لحظه بعد دوباره تلاش کنید.' ); }
		try { return call_user_func( $callback ); }
		finally { self::release( $key, $token ); }
	}
	private static function release( $key, $token ) {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => $key, 'option_value' => $token ), array( '%s', '%s' ) );
		wp_cache_delete( $key, 'options' );
	}
}
