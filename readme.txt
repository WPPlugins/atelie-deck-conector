=== Ateliê.deck Conector ===
Contributors: ateliesolutions, jnatanael
Tags: monitoring, security, updates, multisite management, site health
Requires at least: 5.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects this site to your own Ateliê.deck dashboard so you can watch versions, updates and security settings of many WordPress sites in one place.

== Description ==

**Ateliê.deck Conector** is the small agent that lets the [Ateliê.deck](https://ateliesolutions.com.br/) dashboard (a separate, self-hosted product by Ateliê Solutions) see the state of this WordPress site. It only reads and reports. It never changes the site, never installs or updates anything and never touches your content.

The plugin does nothing until you enter the address of **your own** dashboard, the installation ID and the secret that the dashboard gives you when you register the site.

What it reports, on a schedule (every 6 hours by default, adjustable from 1 to 24 hours) and when you click "Sync now":

* WordPress, PHP, database and web server versions.
* Name, version and state (active or not) of every plugin and theme, and whether an update is available.
* The MD5 checksum of every WordPress core file (wp-admin, wp-includes and the root PHP files), once a day. The dashboard compares them with the official checksums of your exact version and language. Only for core files that differ from the official ones, the plugin also sends the SHA-256, size, modified time and the names and line numbers of known webshell patterns found in them. File content is never sent.
* A one-way fingerprint (a salted hash) of each administrator account, so the dashboard can tell that a new administrator appeared without knowing who it is.
* A few yes/no security settings: whether file editing from the admin is disabled, whether PHP errors are shown on screen, whether XML-RPC is enabled, whether a user called "admin" exists, whether the default table prefix is in use, whether the security keys are set, whether wp-config.php is writable by everyone, whether the site address uses HTTPS and the core auto-update level.

What it never sends: posts, pages, media, comments, user names, e-mails, passwords, the values of your settings or the content of any file.

Every request is signed with HMAC-SHA256 using your secret and travels over HTTPS only (plain HTTP is accepted only if you explicitly allow it for local tests).

The settings screen (Settings > Ateliê.deck Conector) shows the connection status, the next scheduled sync and a history of the last 50 attempts.

= Features =

* Settings screen with connection status, "Sync now" and a sync history tab.
* Configuration in wp-config.php (recommended: the secret stays out of the database) or on the settings screen.
* One automatic retry after 15 minutes when a sync fails for a temporary reason.
* Portuguese (Brazil) translation included.

== Installation ==

1. Register the site in your Ateliê.deck dashboard. It shows the installation ID and the secret once.
2. Upload the `atelie-deck-conector` folder to `/wp-content/plugins/` (or install the zip from Plugins > Add New > Upload) and activate it.
3. Add the connection to `wp-config.php`, above the line "That's all, stop editing!" (recommended):

`define( 'ATELIE_WPM_URL', 'https://deck.example.com' );`
`define( 'ATELIE_WPM_SITE_ID', 'installation-id' );`
`define( 'ATELIE_WPM_SECRET', 'secret' );`

   Or fill in the same three fields in Settings > Ateliê.deck Conector.
4. Click "Sync now" and check the result on the same screen.

== Frequently Asked Questions ==

= Do I need an Ateliê.deck dashboard? =

Yes. This plugin is only the connector. Without a dashboard to receive the data it has nothing to do and sends nothing.

= Does it change my site? =

No. It only reads versions and settings. It does not update, install, delete or edit anything.

= Where does the data go? =

Only to the dashboard address you configure, which you (or your agency) host. Nothing is sent to Ateliê Solutions or to any other third party by this plugin.

= My site has little traffic and syncs late. =

The schedule uses WP-Cron, which only runs when the site gets visits. Use a real server cron that calls `wp-cron.php`, or click "Sync now".

= How do I remove it? =

Deactivate and delete the plugin. Its settings and history are removed from the database. Remove the constants from wp-config.php if you added them, and revoke the site in the dashboard.

== External services ==

This plugin connects to **one external service: the Ateliê.deck dashboard whose address you enter** in the settings or in wp-config.php. It is software you or your agency host yourself; the plugin has no built-in address.

* **What is sent:** the technical inventory described above (versions, plugin and theme names and states, the MD5 checksums of the core files, administrator fingerprints and yes/no security settings), plus the installation ID and an HMAC signature. A sync that includes the integrity scan is a few hundred KB.
* **When:** on the schedule (every 1 to 24 hours), when you click "Sync now", and once more after a failed attempt.
* **Why:** so the dashboard can show updates, risks and the health of the site.
* **Terms and privacy of the product:** [Ateliê Solutions privacy policy](https://ateliesolutions.com.br/politica-de-privacidade/).

To check core file integrity the plugin also asks the WordPress.org API for the official checksums of your WordPress version (through WordPress's own `get_core_checksums()`); this is a standard WordPress function and sends only the version and locale.

== Screenshots ==

1. Overview: connection status, last and next sync.
2. Sync history with result, source, duration and message.

== Changelog ==

= 1.3.0 =
* New: full WordPress core integrity scan. The plugin reports the checksum of each core file (wp-admin, wp-includes, root PHP files) and the dashboard judges them against the official ones: modified, missing and extra files, with history.
* New: for core files that differ from the original, the SHA-256 and known webshell patterns (name and line only) are reported as evidence.
* "Sync now" always runs a fresh scan, and the dashboard can ask for one.
* Links that point outside the WordPress folder are never followed; unreadable or huge files are reported as inaccessible.

= 1.2.0 =
* New: reports yes/no security settings so the dashboard can show a WordPress hardening checklist.
* Prepared for the WordPress.org directory: translatable strings (English source, Portuguese (Brazil) included), readme, "External services" disclosure.
* The settings screen now lists everything the plugin sends.

= 1.1.0 =
* New settings screen with the Ateliê.deck and Ateliê Solutions brands.
* New "Syncs" tab with the history of the last 50 attempts.
* "Settings" shortcut in the plugins list.

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.3.0 =
Adds the core integrity scan. The sync payload grows to a few hundred KB once a day. No action needed.

= 1.2.0 =
Adds security-setting flags to the report and translations. No action needed.
