<?php
/** Settings → Ateliê.deck Conector. Administrators only (manage_options), every action behind a nonce. The secret is never printed back. */
class Atelie_WPM_Admin {

	const SLUG = 'atelie-deck-conector';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_atelie_wpm_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_atelie_wpm_sync', array( __CLASS__, 'sync' ) );
		add_action( 'admin_post_atelie_wpm_clear', array( __CLASS__, 'clear' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( dirname( __DIR__ ) . '/atelie-deck-conector.php' ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		add_options_page( 'Ateliê.deck Conector', 'Ateliê.deck Conector', 'manage_options', self::SLUG, array( __CLASS__, 'page' ) );
	}

	/** The stylesheet loads only on this plugin's screen. */
	public static function assets( $hook ) {
		if ( 'settings_page_' . self::SLUG === $hook ) {
			wp_enqueue_style( 'atelie-wpm-admin', self::asset( 'admin.css' ), array(), ATELIE_WPM_VERSION );
		}
	}

	/** "Configurações" next to Ativar/Desativar in the plugins list. */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Settings', 'atelie-deck-conector' ) . '</a>' );
		return $links;
	}

	public static function save() {
		self::guard( 'atelie_wpm_save' ); // capability + nonce (check_admin_referer)

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by guard() above.
		$url     = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$site_id = isset( $_POST['site_id'] ) ? sanitize_text_field( wp_unslash( $_POST['site_id'] ) ) : '';
		// The secret is stored exactly as typed: sanitizing could alter it. It is never printed back or used in output.
		$secret = isset( $_POST['secret'] ) ? trim( (string) wp_unslash( $_POST['secret'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		update_option( Atelie_WPM_Plugin::OPT_URL, $url, false );
		update_option( Atelie_WPM_Plugin::OPT_SITE, $site_id, false );
		if ( '' !== $secret ) { // empty = keep the stored one
			update_option( Atelie_WPM_Plugin::OPT_SECRET, $secret, false );
		}

		self::back( 'saved' );
	}

	public static function sync() {
		self::guard( 'atelie_wpm_sync' );
		$status = Atelie_WPM_Plugin::run( 'manual' );

		self::back( $status['ok'] ? 'synced' : 'failed', isset( $_POST['tab'] ) && 'sync' === $_POST['tab'] ? 'sync' : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by guard() above.
	}

	public static function clear() {
		self::guard( 'atelie_wpm_clear' );
		delete_option( Atelie_WPM_Plugin::OPT_LOG );

		self::back( 'cleared', 'sync' );
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$status     = get_option( Atelie_WPM_Plugin::OPT_STATUS );
		$locked     = defined( 'ATELIE_WPM_URL' ) && defined( 'ATELIE_WPM_SITE_ID' ) && defined( 'ATELIE_WPM_SECRET' );
		$has_url    = '' !== Atelie_WPM_Plugin::setting( 'ATELIE_WPM_URL', Atelie_WPM_Plugin::OPT_URL );
		$has_site   = '' !== Atelie_WPM_Plugin::setting( 'ATELIE_WPM_SITE_ID', Atelie_WPM_Plugin::OPT_SITE );
		$has_secret = '' !== Atelie_WPM_Plugin::setting( 'ATELIE_WPM_SECRET', Atelie_WPM_Plugin::OPT_SECRET );
		$configured = $has_url && $has_site && $has_secret;
		$next       = wp_next_scheduled( ATELIE_WPM_HOOK );
		$state      = ! is_array( $status ) ? 'idle' : ( $status['ok'] ? 'ok' : 'fail' );
		$labels     = array( 'idle' => __( 'Waiting for the first sync', 'atelie-deck-conector' ), 'ok' => __( 'Connected', 'atelie-deck-conector' ), 'fail' => __( 'Last sync failed', 'atelie-deck-conector' ) );
		$flag       = isset( $_GET['atelie'] ) ? sanitize_key( wp_unslash( $_GET['atelie'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag.
		$tab        = isset( $_GET['tab'] ) && 'sync' === $_GET['tab'] ? 'sync' : 'home'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
		$base_url   = admin_url( 'options-general.php?page=' . self::SLUG );
		?>
		<div class="wrap atl">
			<h1 class="screen-reader-text">Ateliê.deck Conector</h1>

			<?php if ( 'saved' === $flag ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved. Use “Sync now” to test the connection.', 'atelie-deck-conector' ); ?></p></div>
			<?php elseif ( 'synced' === $flag ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Synced with the dashboard.', 'atelie-deck-conector' ); ?></p></div>
			<?php elseif ( 'cleared' === $flag ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'History cleared.', 'atelie-deck-conector' ); ?></p></div>
			<?php elseif ( 'failed' === $flag ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'The sync failed. See the reason in the “Connection status” card.', 'atelie-deck-conector' ); ?></p></div>
			<?php endif; ?>

			<header class="atl-hero">
				<div class="atl-brand">
					<img src="<?php echo esc_url( self::asset( 'atelie-deck-lockup-dark.svg' ) ); ?>" alt="Ateliê.deck" width="190" height="36">
					<p><?php /* translators: %s: plugin version number */ printf( esc_html__( 'WordPress connector · version %s', 'atelie-deck-conector' ), esc_html( ATELIE_WPM_VERSION ) ); ?></p>
				</div>
				<span class="atl-pill atl-pill-<?php echo esc_attr( $state ); ?>"><i aria-hidden="true"></i><?php echo esc_html( $labels[ $state ] ); ?></span>
			</header>

			<nav class="atl-tabs" aria-label="<?php echo esc_attr__( 'Connector sections', 'atelie-deck-conector' ) ; ?>">
				<a href="<?php echo esc_url( $base_url ); ?>" <?php echo 'home' === $tab ? 'aria-current="page"' : ''; ?>><?php esc_html_e( 'Overview', 'atelie-deck-conector' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'sync', $base_url ) ); ?>" <?php echo 'sync' === $tab ? 'aria-current="page"' : ''; ?>><?php esc_html_e( 'Syncs', 'atelie-deck-conector' ); ?></a>
			</nav>

			<?php if ( 'sync' === $tab ) : ?>
				<?php self::history( $configured ); ?>
			<?php else : ?>
			<div class="atl-grid">
				<section class="atl-card" aria-labelledby="atl-st">
					<h2 id="atl-st"><span class="dashicons dashicons-update" aria-hidden="true"></span> <?php esc_html_e( 'Connection status', 'atelie-deck-conector' ); ?></h2>
					<?php if ( is_array( $status ) ) : ?>
						<p class="atl-msg atl-msg-<?php echo esc_attr( $state ); ?>"><?php echo esc_html( $status['message'] ); ?></p>
					<?php else : ?>
						<p class="atl-msg"><?php esc_html_e( 'This site has not synced with the dashboard yet.', 'atelie-deck-conector' ); ?></p>
					<?php endif; ?>
					<dl class="atl-meta">
						<div><dt><?php esc_html_e( 'Last sync', 'atelie-deck-conector' ); ?></dt><dd><?php /* translators: %s: time span, e.g. 5 minutes */ echo is_array( $status ) ? esc_html( sprintf( __( '%s ago', 'atelie-deck-conector' ), human_time_diff( $status['at'] ) ) ) : '—'; ?></dd></div>
						<div><dt><?php esc_html_e( 'Next automatic sync', 'atelie-deck-conector' ); ?></dt><dd><?php /* translators: %s: time span, e.g. 5 minutes */ echo $next ? esc_html( sprintf( __( 'in %s', 'atelie-deck-conector' ), human_time_diff( $next ) ) ) : '—'; ?></dd></div>
						<div><dt><?php esc_html_e( 'Frequency', 'atelie-deck-conector' ); ?></dt><dd><?php /* translators: %d: number of hours */ echo esc_html( sprintf( __( 'every %d h', 'atelie-deck-conector' ), Atelie_WPM_Plugin::interval_hours() ) ); ?></dd></div>
					</dl>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="atelie_wpm_sync">
						<?php wp_nonce_field( 'atelie_wpm_sync' ); ?>
						<?php submit_button( __( 'Sync now', 'atelie-deck-conector' ), 'primary', 'submit', false, array( 'disabled' => $configured ? null : 'disabled' ) ); ?>
						<?php if ( ! $configured ) : ?><span class="atl-hint"><?php esc_html_e( 'Fill in the connection settings first.', 'atelie-deck-conector' ); ?></span><?php endif; ?>
					</form>
				</section>

				<section class="atl-card" aria-labelledby="atl-co">
					<h2 id="atl-co"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span> <?php esc_html_e( 'Connection to the dashboard', 'atelie-deck-conector' ); ?></h2>
					<?php if ( $locked ) : ?>
						<p><?php echo wp_kses( __( 'Defined in <code>wp-config.php</code>, the safest way: the secret is not stored in the database.', 'atelie-deck-conector' ), array( 'code' => array() ) ); ?></p>
						<ul class="atl-checks">
							<li><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <code>ATELIE_WPM_URL</code></li>
							<li><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <code>ATELIE_WPM_SITE_ID</code></li>
							<li><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <code>ATELIE_WPM_SECRET</code></li>
						</ul>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="atelie_wpm_save">
							<?php wp_nonce_field( 'atelie_wpm_save' ); ?>
							<div class="atl-field">
								<label for="url"><?php esc_html_e( 'Dashboard address', 'atelie-deck-conector' ); ?></label>
								<input id="url" name="url" type="url" placeholder="https://deck.example.com" value="<?php echo esc_attr( get_option( Atelie_WPM_Plugin::OPT_URL, '' ) ); ?>">
								<span class="atl-hint"><?php esc_html_e( 'The address where you open Ateliê.deck. HTTPS only.', 'atelie-deck-conector' ); ?></span>
							</div>
							<div class="atl-field">
								<label for="site_id"><?php esc_html_e( 'Installation ID', 'atelie-deck-conector' ); ?></label>
								<input id="site_id" name="site_id" type="text" autocomplete="off" value="<?php echo esc_attr( get_option( Atelie_WPM_Plugin::OPT_SITE, '' ) ); ?>">
								<span class="atl-hint"><?php esc_html_e( 'Shown in the dashboard when you register the site.', 'atelie-deck-conector' ); ?></span>
							</div>
							<div class="atl-field">
								<label for="secret"><?php esc_html_e( 'Secret', 'atelie-deck-conector' ); ?></label>
								<input id="secret" name="secret" type="password" autocomplete="new-password" placeholder="<?php echo $has_secret ? esc_attr__( '•••••••• (stored; leave blank to keep)', 'atelie-deck-conector' ) : ''; ?>">
								<span class="atl-hint"><?php esc_html_e( 'Shown only once when the site is registered. It is never displayed here again.', 'atelie-deck-conector' ); ?></span>
							</div>
							<?php submit_button( __( 'Save connection', 'atelie-deck-conector' ), 'secondary', 'submit', false ); ?>
						</form>
					<?php endif; ?>
				</section>

				<section class="atl-card atl-wide" aria-labelledby="atl-env">
					<h2 id="atl-env"><span class="dashicons dashicons-shield" aria-hidden="true"></span> <?php esc_html_e( 'What this plugin sends', 'atelie-deck-conector' ); ?></h2>
					<div class="atl-two">
						<div>
							<h3><?php esc_html_e( 'Sends', 'atelie-deck-conector' ); ?></h3>
							<ul class="atl-checks">
								<li><span class="dashicons dashicons-yes" aria-hidden="true"></span> <?php esc_html_e( 'WordPress, PHP, database and web server versions', 'atelie-deck-conector' ); ?></li>
								<li><span class="dashicons dashicons-yes" aria-hidden="true"></span> <?php esc_html_e( 'Name, version and state (active or not) of plugins and themes', 'atelie-deck-conector' ); ?></li>
								<li><span class="dashicons dashicons-yes" aria-hidden="true"></span> <?php esc_html_e( 'The MD5 checksum of each WordPress core file (wp-admin, wp-includes and the root PHP files), never their content', 'atelie-deck-conector' ); ?></li>
								<li><span class="dashicons dashicons-yes" aria-hidden="true"></span> <?php esc_html_e( 'Whether a core file that differs from the original contains known webshell patterns (only the pattern name and line)', 'atelie-deck-conector' ); ?></li>
								<li><span class="dashicons dashicons-yes" aria-hidden="true"></span> <?php esc_html_e( 'A one-way fingerprint of each administrator account (never the username)', 'atelie-deck-conector' ); ?></li>
								<li><span class="dashicons dashicons-yes" aria-hidden="true"></span> <?php esc_html_e( 'Yes/no security settings, for example whether file editing is disabled', 'atelie-deck-conector' ); ?></li>
								<li><span class="dashicons dashicons-yes" aria-hidden="true"></span> <?php esc_html_e( 'Whether an update is available', 'atelie-deck-conector' ); ?></li>
							</ul>
						</div>
						<div>
							<h3><?php esc_html_e( 'Never sends', 'atelie-deck-conector' ); ?></h3>
							<ul class="atl-checks atl-no">
								<li><span class="dashicons dashicons-no-alt" aria-hidden="true"></span> <?php esc_html_e( 'Site content (pages, posts, media)', 'atelie-deck-conector' ); ?></li>
								<li><span class="dashicons dashicons-no-alt" aria-hidden="true"></span> <?php esc_html_e( 'Users, e-mails and passwords', 'atelie-deck-conector' ); ?></li>
								<li><span class="dashicons dashicons-no-alt" aria-hidden="true"></span> <?php esc_html_e( 'Configuration values', 'atelie-deck-conector' ); ?></li>
							</ul>
						</div>
					</div>
					<p class="atl-hint"><?php esc_html_e( 'Every request is signed and travels over HTTPS only. The plugin only reads and reports: it changes nothing on this site.', 'atelie-deck-conector' ); ?></p>
				</section>
			</div>
			<?php endif; ?>

			<footer class="atl-foot">
				<img src="<?php echo esc_url( self::asset( 'atelie-solutions.png' ) ); ?>" alt="Ateliê Solutions" width="150" height="29" loading="lazy">
				<p><?php /* translators: %s: link to the Ateliê Solutions website */ echo wp_kses( sprintf( __( 'Ateliê.deck is a product by %s, from Cristais, Minas Gerais, Brazil.', 'atelie-deck-conector' ), '<a href="https://ateliesolutions.com.br/" target="_blank" rel="noopener">Ateliê Solutions</a>' ), array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ); ?></p>
				<p class="atl-links">
					<a href="https://ateliesolutions.com.br/" target="_blank" rel="noopener"><?php esc_html_e( 'Website', 'atelie-deck-conector' ); ?></a>
					<a href="mailto:contato@ateliesolutions.com.br">contato@ateliesolutions.com.br</a>
					<a href="https://api.whatsapp.com/send?phone=5535991269829" target="_blank" rel="noopener">WhatsApp</a>
					<a href="https://ateliesolutions.com.br/politica-de-privacidade/" target="_blank" rel="noopener"><?php esc_html_e( 'Privacy', 'atelie-deck-conector' ); ?></a>
				</p>
			</footer>
		</div>
		<?php
	}

	/** The "Sincronizações" tab: the last LOG_MAX attempts, newest first. */
	private static function history( $configured ) {
		$log    = array_values( array_filter( (array) get_option( Atelie_WPM_Plugin::OPT_LOG, array() ), 'is_array' ) );
		$total  = count( $log );
		$ok     = count( array_filter( $log, function ( $e ) { return ! empty( $e['ok'] ); } ) );
		$source = array( 'agenda' => __( 'Scheduled', 'atelie-deck-conector' ), 'manual' => __( 'Manual', 'atelie-deck-conector' ), 'repetição' => __( 'Retry', 'atelie-deck-conector' ) );
		?>
		<section class="atl-card atl-wide" aria-labelledby="atl-hist">
			<div class="atl-histhead">
				<h2 id="atl-hist"><span class="dashicons dashicons-backup" aria-hidden="true"></span> <?php esc_html_e( 'Sync history', 'atelie-deck-conector' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="atelie_wpm_sync">
					<input type="hidden" name="tab" value="sync">
					<?php wp_nonce_field( 'atelie_wpm_sync' ); ?>
					<?php submit_button( __( 'Sync now', 'atelie-deck-conector' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<?php if ( 0 === $total ) : ?>
				<p class="atl-empty"><?php esc_html_e( 'No syncs recorded yet. After setting up the connection, use “Sync now” or wait for the next automatic one.', 'atelie-deck-conector' ); ?></p>
			<?php else : ?>
				<ul class="atl-sum" aria-label="<?php echo esc_attr__( 'Summary', 'atelie-deck-conector' ) ; ?>">
					<li><?php /* translators: %s: number of records */ echo wp_kses( sprintf( _n( '<b>%s</b> record', '<b>%s</b> records', $total, 'atelie-deck-conector' ), number_format_i18n( $total ) ), array( 'b' => array() ) ); ?></li>
					<li class="atl-sum-ok"><?php /* translators: %s: number of successful syncs */ echo wp_kses( sprintf( __( '<b>%s</b> succeeded', 'atelie-deck-conector' ), number_format_i18n( $ok ) ), array( 'b' => array() ) ); ?></li>
					<li class="atl-sum-fail"><?php /* translators: %s: number of failed syncs */ echo wp_kses( sprintf( __( '<b>%s</b> failed', 'atelie-deck-conector' ), number_format_i18n( $total - $ok ) ), array( 'b' => array() ) ); ?></li>
				</ul>
				<div class="atl-tablewrap">
					<table class="widefat striped atl-table">
						<thead><tr><th><?php esc_html_e( 'When', 'atelie-deck-conector' ); ?></th><th><?php esc_html_e( 'Result', 'atelie-deck-conector' ); ?></th><th><?php esc_html_e( 'Source', 'atelie-deck-conector' ); ?></th><th><?php esc_html_e( 'Duration', 'atelie-deck-conector' ); ?></th><th><?php esc_html_e( 'Sent', 'atelie-deck-conector' ); ?></th><th><?php esc_html_e( 'Message', 'atelie-deck-conector' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $log as $e ) : ?>
							<?php $good = ! empty( $e['ok'] ); ?>
							<tr>
								<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $e['at'] ) ); ?><small><?php /* translators: %s: time span */ echo esc_html( sprintf( __( '%s ago', 'atelie-deck-conector' ), human_time_diff( (int) $e['at'] ) ) ); ?></small></td>
								<td><span class="atl-tag atl-tag-<?php echo $good ? 'ok' : 'fail'; ?>"><?php echo $good ? esc_html__( 'Success', 'atelie-deck-conector' ) : esc_html__( 'Failure', 'atelie-deck-conector' ); ?></span></td>
								<td><?php echo esc_html( $source[ $e['source'] ?? '' ] ?? $source['agenda'] ); ?></td>
								<td><?php echo isset( $e['ms'] ) ? esc_html( $e['ms'] >= 1000 ? number_format_i18n( $e['ms'] / 1000, 1 ) . ' s' : $e['ms'] . ' ms' ) : '—'; ?></td>
								<td><?php echo isset( $e['plugins'] ) ? esc_html( sprintf( /* translators: 1: number of plugins, 2: number of themes */ __( '%1$d plugins · %2$d themes', 'atelie-deck-conector' ), $e['plugins'], $e['themes'] ) ) : '—'; ?></td>
								<td><?php echo esc_html( (string) ( $e['message'] ?? '' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<p class="atl-hint"><?php /* translators: %d: number of attempts kept */ echo esc_html( sprintf( __( 'We keep the last %d attempts, outcome only: no site data goes into this list.', 'atelie-deck-conector' ), Atelie_WPM_Plugin::LOG_MAX ) ); ?></p>
				<form class="atl-clear" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm( '<?php echo esc_js( __( 'Delete the sync history of this site?', 'atelie-deck-conector' ) ); ?>' );">
					<input type="hidden" name="action" value="atelie_wpm_clear">
					<?php wp_nonce_field( 'atelie_wpm_clear' ); ?>
					<?php submit_button( __( 'Clear history', 'atelie-deck-conector' ), 'link-delete', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function asset( $file ) {
		return plugins_url( 'assets/' . $file, dirname( __DIR__ ) . '/atelie-deck-conector.php' );
	}

	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'atelie-deck-conector' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	private static function back( $flag, $tab = '' ) {
		wp_safe_redirect( add_query_arg( array_filter( array( 'page' => self::SLUG, 'tab' => $tab, 'atelie' => $flag ) ), admin_url( 'options-general.php' ) ) );
		exit;
	}
}
