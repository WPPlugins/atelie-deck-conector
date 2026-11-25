<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'atelie_wpm_url', 'atelie_wpm_site_id', 'atelie_wpm_secret', 'atelie_wpm_interval_hours', 'atelie_wpm_status', 'atelie_wpm_log' ) as $atelie_wpm_option ) {
	delete_option( $atelie_wpm_option );
}
wp_clear_scheduled_hook( 'atelie_wpm_sync' );
wp_clear_scheduled_hook( 'atelie_wpm_sync_retry' );
