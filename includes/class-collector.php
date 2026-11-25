<?php
/**
 * Reads the site inventory with WordPress's own functions. Collects only versions and flags: no content, no users, no options values.
 *
 * `update_available` is true/false only when WordPress's update data says so (the plugin is in the transient's `response` or
 * `no_update` list). A plugin in neither list (premium plugins with their own updater that has not run, for example) is reported as
 * null = "could not verify", so the panel never shows it as up to date.
 */
class Atelie_WPM_Collector {

	/** Refresh WordPress's update data when it is older than this (WordPress itself refreshes every 12 hours). */
	const STALE_AFTER = 13 * 3600;

	/** @return array<string, mixed> the payload of POST /api/v1/agent/sync */
	public function collect( $refresh = true ) {
		if ( $refresh ) {
			$this->refresh_update_data();
		}

		$core    = $this->core();
		$plugins = $this->plugins();
		$themes  = $this->themes();

		return array(
			'schema_version' => '1.0',
			'sync_id'        => wp_generate_uuid4(),
			'collected_at'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'agent_version'  => ATELIE_WPM_VERSION,
			'wordpress'      => $core,
			'environment'    => $this->environment(),
			'plugins'        => $plugins,
			'themes'         => $themes,
			'integrity'      => $this->integrity(),
			'hardening'      => $this->hardening(),
		);
	}

	/** A full verification is one pass over ~3,700 files; a host that cannot finish it in this many seconds reports "unavailable" instead of guessing. */
	const SCAN_BUDGET = 25;

	/** Files larger than this are not read (core files are far smaller): they are reported as inaccessible. */
	const MAX_FILE = 10485760;

	/** Only this much of a PHP file is searched for signatures. */
	const SIGNATURE_MAX = 524288;

	/** A few conservative webshell and obfuscation signatures, looked for ONLY in core files that differ from the official ones. A match is evidence, never a verdict. */
	const SIGNATURES = array(
		'eval-decode'   => '~\beval\s*\(\s*(?:base64_decode|gzinflate|gzuncompress|str_rot13)\s*\(~i',
		'eval-input'    => '~\b(?:eval|assert)\s*\(\s*\$_(?:POST|GET|REQUEST|COOKIE)\b~i',
		'exec-input'    => '~\b(?:system|shell_exec|passthru|exec|popen|proc_open)\s*\(\s*\$_(?:POST|GET|REQUEST|COOKIE)\b~i',
		'preg-e'        => '~preg_replace\s*\(\s*([\'"])/[^\'"]*/[a-z]*e[a-z]*\1~i',
		'decode-chain'  => '~gzinflate\s*\(\s*base64_decode\s*\(~i',
		'long-base64'   => '~base64_decode\s*\(\s*[\'"][A-Za-z0-9+/=]{300,}[\'"]~i',
		'webshell-name' => '~\b(?:c99shell|r57shell|b374k|FilesMan|WSO\s*Shell)\b~i',
	);

	/**
	 * Integrity data, with no file content: (1) the MD5 of every core file the panel judges (wp-admin, wp-includes and the root PHP
	 * files), computed at most once a day and resent on every sync until then, (2) a fingerprint (hash, never the login) of each
	 * administrator account, so the panel can say "a new administrator appeared" without knowing who they are.
	 * The verdict is NOT made here: the panel compares against the official checksums, so a tampered plugin cannot declare its site clean.
	 */
	private function integrity() {
		$out = array( 'admins' => array() );

		$core = get_transient( 'atelie_wpm_core_scan' );
		if ( false === $core ) {
			$core = $this->core_scan();
			// a scan that could not finish is tried again in an hour, a good one lasts the day
			set_transient( 'atelie_wpm_core_scan', $core, empty( $core['error'] ) ? DAY_IN_SECONDS : HOUR_IN_SECONDS );
		}
		$out['core'] = $core;

		foreach ( get_users( array( 'role' => 'administrator', 'fields' => 'user_login', 'number' => 200 ) ) as $login ) {
			$out['admins'][] = substr( hash_hmac( 'sha256', strtolower( $login ), wp_salt( 'auth' ) ), 0, 16 );
		}

		return $out;
	}

	/**
	 * Walks wp-admin, wp-includes and the root *.php once, one file at a time (nothing is held in memory but the hashes), and never executes
	 * anything. Symbolic links that leave the WordPress folder, unreadable and huge files are reported as inaccessible, not followed.
	 *
	 * @return array<string, mixed> the `integrity.core` block of the sync payload
	 */
	private function core_scan() {
		if ( ! function_exists( 'get_core_checksums' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		$started = microtime( true );
		$version = get_bloginfo( 'version' );
		$locale  = get_locale();
		$head    = array( 'version' => $version, 'locale' => $locale, 'scanned_at' => gmdate( 'Y-m-d\TH:i:s\Z' ) );
		$root    = untrailingslashit( wp_normalize_path( ABSPATH ) );
		$real    = wp_normalize_path( (string) realpath( $root ) );
		$files   = array();
		$blocked = array();

		$areas = array( 'wp-admin' => $root . '/wp-admin', 'wp-includes' => $root . '/' . WPINC );
		foreach ( $areas as $prefix => $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			try {
				$walk = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD );
				foreach ( $walk as $file ) {
					if ( microtime( true ) - $started > self::SCAN_BUDGET ) {
						return $head + array( 'error' => 'timeout', 'files' => array() );
					}
					$path = wp_normalize_path( $file->getPathname() );
					$this->hash_into( $path, $prefix . substr( $path, strlen( wp_normalize_path( $dir ) ) ), $real, $files, $blocked );
				}
			} catch ( Exception $e ) {
				$blocked[] = $prefix . '/';
			}
		}
		foreach ( (array) glob( $root . '/*.php' ) as $path ) {
			$this->hash_into( wp_normalize_path( $path ), basename( $path ), $real, $files, $blocked );
		}

		return $head + array(
			'duration_ms'  => (int) round( ( microtime( true ) - $started ) * 1000 ),
			'files'        => $files,
			'inaccessible' => array_slice( $blocked, 0, 200 ),
			'details'      => $this->differences( $files, $root, $version, $locale ),
		);
	}

	/** @param array<string, string> $files @param list<string> $blocked */
	private function hash_into( $path, $relative, $real, array &$files, array &$blocked ) {
		if ( is_link( $path ) ) {
			$target = realpath( $path );
			if ( false === $target || 0 !== strpos( wp_normalize_path( $target ), $real . '/' ) ) {
				$blocked[] = $relative; // a link that leaves the WordPress folder is never followed
				return;
			}
		}
		$size = @filesize( $path );
		$md5  = ( false === $size || $size > self::MAX_FILE ) ? false : @md5_file( $path );
		if ( false === $md5 ) {
			$blocked[] = $relative;
			return;
		}
		$files[ $relative ] = $md5;
	}

	/**
	 * For the files that differ from WordPress's own checksums (or are not in them): SHA-256, size, modified time and signature hits.
	 * At most 300 files; nothing when WordPress could not give the official list (the panel then says "verification unavailable").
	 *
	 * @param array<string, string> $files
	 * @return array<string, array<string, mixed>>
	 */
	private function differences( array $files, $root, $version, $locale ) {
		$official = get_core_checksums( $version, $locale );
		if ( empty( $official ) || ! is_array( $official ) ) {
			return array();
		}
		$details = array();
		foreach ( $files as $relative => $md5 ) {
			if ( isset( $official[ $relative ] ) && $official[ $relative ] === $md5 ) {
				continue;
			}
			$path    = $root . '/' . $relative;
			$size    = (int) @filesize( $path );
			$details[ $relative ] = array(
				'sha256' => (string) @hash_file( 'sha256', $path ),
				'size'   => $size,
				'mtime'  => (int) @filemtime( $path ),
			);
			if ( '.php' === substr( $relative, -4 ) && $size <= self::SIGNATURE_MAX ) {
				$hits = $this->signatures( $path );
				if ( $hits ) {
					$details[ $relative ]['signatures'] = $hits;
				}
			}
			if ( count( $details ) >= 300 ) {
				break;
			}
		}

		return $details;
	}

	/** @return list<array{id: string, line: int}> */
	private function signatures( $path ) {
		$code = @file_get_contents( $path, false, null, 0, self::SIGNATURE_MAX );
		$hits = array();
		if ( false === $code ) {
			return $hits;
		}
		foreach ( self::SIGNATURES as $id => $pattern ) {
			if ( preg_match( $pattern, $code, $m, PREG_OFFSET_CAPTURE ) ) {
				$hits[] = array( 'id' => $id, 'line' => 1 + substr_count( substr( $code, 0, $m[0][1] ), "\n" ) );
			}
		}

		return $hits;
	}

	/**
	 * Security settings of this site, as yes/no flags only: nothing secret is read (no salts, no passwords, no file contents).
	 * The panel shows them next to what to change and how.
	 *
	 * @return array<string, mixed>
	 */
	private function hardening() {
		global $wpdb;

		$config  = ABSPATH . 'wp-config.php';
		$config  = file_exists( $config ) ? $config : dirname( ABSPATH ) . '/wp-config.php';
		$perms   = file_exists( $config ) ? fileperms( $config ) : false;
		$salt    = defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '';
		$updates = defined( 'WP_AUTO_UPDATE_CORE' ) ? WP_AUTO_UPDATE_CORE : 'minor';
		$admins  = get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 200 ) );

		return array(
			'file_edit_disabled'    => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
			'debug_display'         => defined( 'WP_DEBUG' ) && WP_DEBUG && ( ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY ),
			'xmlrpc_enabled'        => (bool) apply_filters( 'xmlrpc_enabled', true ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook, read only.
			'default_admin_user'    => false !== username_exists( 'admin' ),
			'default_table_prefix'  => 'wp_' === $wpdb->base_prefix,
			'salts_set'             => strlen( $salt ) >= 32 && false === strpos( $salt, 'put your unique phrase here' ),
			'config_world_writable' => false !== $perms && (bool) ( $perms & 0002 ),
			'https_site'            => 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ),
			'force_ssl_admin'       => defined( 'FORCE_SSL_ADMIN' ) && FORCE_SSL_ADMIN,
			'core_auto_update'      => true === $updates || 'major' === $updates ? 'major' : ( false === $updates ? 'off' : 'minor' ),
			'admin_count'           => count( $admins ),
		);
	}

	private function refresh_update_data() {
		$stale = function ( $transient ) {
			$data = get_site_transient( $transient );
			return empty( $data->last_checked ) || ( time() - (int) $data->last_checked ) > self::STALE_AFTER;
		};

		// These are WordPress's own checkers (they talk to wordpress.org and to premium updaters hooked into the transient).
		if ( $stale( 'update_core' ) && function_exists( 'wp_version_check' ) ) {
			wp_version_check();
		}
		if ( $stale( 'update_plugins' ) && function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}
		if ( $stale( 'update_themes' ) && function_exists( 'wp_update_themes' ) ) {
			wp_update_themes();
		}
	}

	private function core() {
		$version   = get_bloginfo( 'version' );
		$update    = get_site_transient( 'update_core' );
		$latest    = $version;
		$available = null;

		if ( ! empty( $update->last_checked ) ) {
			$available = false;
			foreach ( (array) ( $update->updates ?? array() ) as $offer ) {
				if ( isset( $offer->response ) && 'upgrade' === $offer->response && version_compare( $offer->current, $version, '>' ) ) {
					$available = true;
					$latest    = $offer->current;
					break;
				}
			}
		}

		return array(
			'version'          => $version,
			'latest_version'   => $latest,
			'update_available' => $available,
			'multisite'        => is_multisite(),
		);
	}

	private function environment() {
		global $wpdb;

		$server = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : null;

		return array(
			'php_version'      => PHP_VERSION,
			'database_version' => $wpdb->db_version(),
			'server'           => $server ? substr( $server, 0, 255 ) : null,
		);
	}

	private function plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$updates      = get_site_transient( 'update_plugins' );
		$checked      = ! empty( $updates->last_checked );
		$response     = (array) ( $updates->response ?? array() );
		$no_update    = (array) ( $updates->no_update ?? array() );
		$active       = (array) get_option( 'active_plugins', array() );
		$network      = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
		$list         = array();

		foreach ( get_plugins() as $file => $data ) {
			$offer   = $response[ $file ] ?? null;
			$known   = $offer ?? ( $no_update[ $file ] ?? null );
			$is_net  = in_array( $file, $network, true );
			$package = is_object( $offer ) ? (string) ( $offer->package ?? '' ) : '';
			$url     = is_object( $known ) ? (string) ( $known->url ?? '' ) : '';

			$list[] = array(
				'file'             => $file,
				'slug'             => is_object( $known ) && ! empty( $known->slug ) ? $known->slug : ( false !== strpos( $file, '/' ) ? dirname( $file ) : basename( $file, '.php' ) ),
				'name'             => $data['Name'] ?: $file,
				'version'          => $data['Version'] ?: '0',
				'latest_version'   => is_object( $known ) && ! empty( $known->new_version ) ? $known->new_version : null,
				'active'           => $is_net || in_array( $file, $active, true ),
				'network_active'   => $is_net,
				'update_available' => $checked && $known ? is_object( $offer ) : null,
				'update_source'    => $known ? ( false !== strpos( $package . $url, 'wordpress.org' ) ? 'wordpress.org' : 'third-party' ) : null,
				'requires_wp'      => $data['RequiresWP'] ?: null,
				'requires_php'     => $data['RequiresPHP'] ?: null,
			);
		}

		return $list;
	}

	private function themes() {
		$updates   = get_site_transient( 'update_themes' );
		$checked   = ! empty( $updates->last_checked );
		$response  = (array) ( $updates->response ?? array() );
		$no_update = (array) ( $updates->no_update ?? array() );
		$active    = get_stylesheet();
		$list      = array();

		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$offer = $response[ $stylesheet ] ?? null;
			$known = $offer ?? ( $no_update[ $stylesheet ] ?? null );

			$list[] = array(
				'stylesheet'       => $stylesheet,
				'name'             => (string) $theme->get( 'Name' ),
				'version'          => (string) ( $theme->get( 'Version' ) ?: '0' ),
				'latest_version'   => is_array( $known ) && ! empty( $known['new_version'] ) ? $known['new_version'] : null,
				'is_active'        => $stylesheet === $active,
				'update_available' => $checked && $known ? is_array( $offer ) : null,
			);
		}

		return $list;
	}
}
