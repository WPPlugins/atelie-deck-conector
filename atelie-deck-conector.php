<?php
/**
 * Plugin Name:       Ateliê.deck Conector
 * Description:       Connects this site to your own Ateliê.deck dashboard: reports versions, updates and security settings. Read-only.
 * Version:           1.3.0
 * Plugin URI:        https://ateliesolutions.com.br/deck-wordpress-manager
 * Author URI:        https://ateliesolutions.com.br/
 * Requires at least: 5.5
 * Requires PHP:      7.4
 * Author:            Ateliê Solutions
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       atelie-deck-conector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'ATELIE_WPM_VERSION' ) ) {
	// Another copy (e.g. the older folder name) is already loaded: loading this one too would be a fatal "class already declared".
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Ateliê.deck Conector: another copy of this plugin is already active (for example the older “atelie-wp-monitor” folder). Deactivate it first, then activate this one.', 'atelie-deck-conector' ) . '</p></div>';
		}
	);
	return;
}

define( 'ATELIE_WPM_VERSION', '1.3.0' );
define( 'ATELIE_WPM_HOOK', 'atelie_wpm_sync' );
define( 'ATELIE_WPM_RETRY_HOOK', 'atelie_wpm_sync_retry' );

require_once __DIR__ . '/includes/class-collector.php';
require_once __DIR__ . '/includes/class-client.php';
require_once __DIR__ . '/includes/class-plugin.php';
require_once __DIR__ . '/admin/class-admin.php';


register_activation_hook( __FILE__, array( 'Atelie_WPM_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Atelie_WPM_Plugin', 'deactivate' ) );

Atelie_WPM_Plugin::init();
if ( is_admin() ) {
	Atelie_WPM_Admin::init();
}
