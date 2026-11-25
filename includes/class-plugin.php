<?php
/** Settings, scheduling and the sync run. Credentials may live in wp-config.php so they never touch the database. */
class Atelie_WPM_Plugin {

	const OPT_URL      = 'atelie_wpm_url';
	const OPT_SITE     = 'atelie_wpm_site_id';
	const OPT_SECRET   = 'atelie_wpm_secret';
	const OPT_INTERVAL = 'atelie_wpm_interval_hours';
	const OPT_STATUS   = 'atelie_wpm_status';
	const OPT_LOG      = 'atelie_wpm_log';
	const LOG_MAX      = 50;

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( ATELIE_WPM_HOOK, array( __CLASS__, 'run_scheduled' ) );
		add_action( ATELIE_WPM_RETRY_HOOK, array( __CLASS__, 'run_retry' ) );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
	}

	public static function activate() {
		self::ensure_scheduled();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( ATELIE_WPM_HOOK );
		wp_clear_scheduled_hook( ATELIE_WPM_RETRY_HOOK );
	}

	public static function interval_hours() {
		return max( 1, min( 24, (int) get_option( self::OPT_INTERVAL, 6 ) ) );
	}

	public static function cron_schedules( $schedules ) {
		$schedules['atelie_wpm'] = array(
			'interval' => self::interval_hours() * HOUR_IN_SECONDS,
			'display'  => 'Ateliê.deck Conector',
		);

		return $schedules;
	}

	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( ATELIE_WPM_HOOK ) ) {
			wp_schedule_event( time() + 60, 'atelie_wpm', ATELIE_WPM_HOOK );
		}
	}

	/** wp-config.php constants win over the stored options. */
	public static function setting( $constant, $option ) {
		return defined( $constant ) ? (string) constant( $constant ) : (string) get_option( $option, '' );
	}

	public static function client() {
		return new Atelie_WPM_Client(
			self::setting( 'ATELIE_WPM_URL', self::OPT_URL ),
			self::setting( 'ATELIE_WPM_SITE_ID', self::OPT_SITE ),
			self::setting( 'ATELIE_WPM_SECRET', self::OPT_SECRET )
		);
	}

	public static function run_scheduled() {
		self::run( 'agenda' );
	}

	public static function run_retry() {
		self::run( 'repetição' );
	}

	/** Collects and sends the inventory. Used by the schedule, the retry and the "Sincronizar agora" button ($source says which). */
	public static function run( $source = 'agenda' ) {
		$started = microtime( true );
		if ( 'manual' === $source ) {
			delete_transient( 'atelie_wpm_core_scan' ); // "Sync now" always includes a fresh integrity scan
		}
		$client = self::client();
		if ( ! $client->is_configured() ) {
			return self::save_status( false, esc_html__( 'Not configured.', 'atelie-deck-conector' ), $source, $started );
		}

		$payload = ( new Atelie_WPM_Collector() )->collect();
		$counts  = array( count( $payload['plugins'] ), count( $payload['themes'] ) );
		$result  = $client->post( Atelie_WPM_Client::SYNC_PATH, $payload );

		if ( $result['ok'] ) {
			wp_clear_scheduled_hook( ATELIE_WPM_RETRY_HOOK );
			if ( ! empty( $result['data']['request_integrity_scan'] ) ) {
				// someone asked the panel for a new verification: look again shortly, in a separate run
				delete_transient( 'atelie_wpm_core_scan' );
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, ATELIE_WPM_RETRY_HOOK );
			}
			self::apply_server_interval( (int) ( $result['data']['sync_interval_hours'] ?? 0 ) );

			return self::save_status( true, sprintf( /* translators: %s: result status from the dashboard */ esc_html__( 'Synced (%s).', 'atelie-deck-conector' ), $result['data']['status'] ?? 'ok' ), $source, $started, $counts );
		}

		// One retry in 15 minutes; the regular schedule continues either way. A 401/422 will not fix itself, so no retry.
		if ( ! in_array( $result['status'], array( 401, 413, 422 ), true ) && ! wp_next_scheduled( ATELIE_WPM_RETRY_HOOK ) ) {
			wp_schedule_single_event( time() + 15 * MINUTE_IN_SECONDS, ATELIE_WPM_RETRY_HOOK );
		}

		return self::save_status( false, $result['message'], $source, $started, $counts );
	}

	private static function apply_server_interval( $hours ) {
		if ( $hours >= 1 && $hours <= 24 && $hours !== self::interval_hours() ) {
			update_option( self::OPT_INTERVAL, $hours, false );
			wp_clear_scheduled_hook( ATELIE_WPM_HOOK );
			self::ensure_scheduled();
		}
	}

	/** Keeps the last status and a short history (newest first, LOG_MAX entries) for the "Sincronizações" tab. No inventory data is kept, only the outcome. */
	private static function save_status( $ok, $message, $source = 'agenda', $started = null, $counts = null ) {
		$status = array( 'ok' => (bool) $ok, 'message' => (string) $message, 'at' => time() );
		update_option( self::OPT_STATUS, $status, false );

		$log = (array) get_option( self::OPT_LOG, array() );
		array_unshift( $log, $status + array(
			'source'  => (string) $source,
			'ms'      => null === $started ? null : (int) round( ( microtime( true ) - $started ) * 1000 ),
			'plugins' => $counts ? (int) $counts[0] : null,
			'themes'  => $counts ? (int) $counts[1] : null,
		) );
		update_option( self::OPT_LOG, array_slice( $log, 0, self::LOG_MAX ), false );

		return $status;
	}
}
