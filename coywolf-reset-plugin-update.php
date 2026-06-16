<?php
/**
 * Plugin Name:       Coywolf Reset Updates
 * Plugin URI:        https://coywolf.com/notes/a-plugin-to-reset-the-wordpress-plugin-update-cache/
 * Description:       Adds a Tools → Reset Updates page with a button that clears the cached update data and forces WordPress to recheck for new versions — useful when a release was just published and you do not want to wait for the next scheduled check.
 * Version:           1.0.30
 * Requires at least: 5.0
 * Requires PHP:      7.2
 * Author:            Coywolf
 * Author URI:        https://coywolf.com/jon-henshaw/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       coywolf-reset-updates
 *
 * @package CoywolfResetPluginUpdate
 *
 * Coywolf Reset Updates
 * Copyright (C) 2026 Coywolf LLC
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License, version 2, as published
 * by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, see https://www.gnu.org/licenses/gpl-2.0.html.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* wporg-strip:start — GitHub self-updater (removed from the WordPress.org build) */
require_once __DIR__ . '/includes/class-github-updater.php';
/* wporg-strip:end */

final class Coywolf_Reset_Plugin_Update {

	const VERSION    = '1.0.30';
	const SLUG       = 'coywolf-rpu';
	const ACTION     = 'coywolf_rpu_reset';
	const CAPABILITY = 'update_plugins';
	const NOTICE_KEY = 'coywolf_rpu_notice_';

	public static function boot() {
		add_action( 'admin_menu',                 array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_reset' ) );
		add_action( 'admin_notices',              array( __CLASS__, 'maybe_render_notice' ) );
		add_action( 'network_admin_notices',      array( __CLASS__, 'maybe_render_notice' ) );
	}

	public static function register_menu() {
		add_management_page(
			__( 'Reset Updates', 'coywolf-reset-updates' ),
			__( 'Reset Updates', 'coywolf-reset-updates' ),
			self::CAPABILITY,
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'coywolf-reset-updates' ) );
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Reset Updates', 'coywolf-reset-updates' ); ?></h1>

			<p>
				<?php esc_html_e( 'Clears the cached update data and forces WordPress to recheck for new versions. Useful when a release was just published, and you do not want to wait for the next scheduled check.', 'coywolf-reset-updates' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::ACTION ); ?>
				<?php submit_button( __( 'Reset update cache', 'coywolf-reset-updates' ), 'primary', 'submit', true ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the POSTed button click. Clears the cached update data and then
	 * redirects to WordPress's own "Check Again" URL — `update-core.php` with
	 * `force-check=1`. That page's `load-update-core.php` hook calls
	 * `wp_version_check()`, `wp_update_plugins()` and `wp_update_themes()`
	 * with the force flag set, so the re-check is guaranteed to run in the
	 * same request the user lands on. This mirrors the original
	 * `?coywolf_blc_check` snippet's flow (delete transients → redirect to
	 * update-core.php), which is what made the original snippet reliable.
	 *
	 * Security gates (all must pass before any cache mutation happens):
	 *   1. POST request only — refuses GET / HEAD / etc. so a crafted URL
	 *      with a valid nonce in someone's browser history or referer logs
	 *      cannot trigger the reset.
	 *   2. User must be logged in. The `admin_post_{action}` hook already
	 *      implies this (admin-post.php routes unauthenticated requests to
	 *      `admin_post_nopriv_{action}`, which this plugin never registers),
	 *      but the explicit check is defense in depth.
	 *   3. User must hold the `update_plugins` capability. On single-site
	 *      that's administrators only; on multisite, network admins only.
	 *   4. Valid, fresh nonce tied to this specific action.
	 *      `check_admin_referer()` halts with a 403 if missing or expired.
	 */
	public static function handle_reset() {
		// 1. POST-only.
		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
			: '';
		if ( 'POST' !== $method ) {
			wp_die(
				esc_html__( 'This action can only be performed by submitting the Reset Updates form.', 'coywolf-reset-updates' ),
				esc_html__( 'Method Not Allowed', 'coywolf-reset-updates' ),
				array( 'response' => 405 )
			);
		}

		// 2 + 3. Logged-in user with the right cap.
		if ( ! is_user_logged_in() || ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to perform this action.', 'coywolf-reset-updates' ),
				esc_html__( 'Forbidden', 'coywolf-reset-updates' ),
				array( 'response' => 403 )
			);
		}

		// 4. Nonce.
		check_admin_referer( self::ACTION );

		self::clear_update_caches();

		// Re-populate the GitHub self-updaters' release caches synchronously,
		// in this request, before we redirect into the force-check. Without
		// this the force-check finds every cache cold (we just deleted them),
		// defers all the GitHub fetches to a background cron tick, and shows
		// no GitHub-hosted updates — they only surface on the NEXT page load.
		// That is the "nothing shows until I run it and refresh again" symptom.
		self::warm_github_updater_caches();

		// One-shot flag so the success notice appears on the page the user
		// lands on after the redirect (update-core.php).
		set_transient( self::NOTICE_KEY . get_current_user_id(), 1, MINUTE_IN_SECONDS );

		wp_safe_redirect( add_query_arg( 'force-check', '1', self_admin_url( 'update-core.php' ) ) );
		exit;
	}

	/**
	 * Drop the cached plugin-update data and any throttle caches commonly
	 * used by self-hosted GitHub plugin updaters.
	 *
	 * Coywolf plugins' GitHub updater stores its GitHub-release response in
	 * `<slug>_gh_release` and a short negative-response cache in
	 * `<slug>_gh_release_neg`. Other GitHub-updater libraries use
	 * `*_github_release` / `*_github_update`. The LIKE patterns below sweep
	 * all of those (and their `_timeout_` siblings) regardless of where
	 * `_gh_release` appears in the key.
	 */
	private static function clear_update_caches() {
		global $wpdb;

		delete_site_transient( 'update_plugins' );
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( true );
		}

		$like_patterns = array( '%gh_release%', '%github_release%', '%github_update%' );
		$prefixes      = array( '_site_transient_', '_site_transient_timeout_' );

		if ( is_multisite() ) {
			$table  = $wpdb->sitemeta;
			$column = 'meta_key';
		} else {
			$table  = $wpdb->options;
			$column = 'option_name';
		}

		$found = array();
		foreach ( $like_patterns as $pat ) {
			foreach ( $prefixes as $prefix ) {
				// esc_like() the prefix so the `_` characters in
				// `_site_transient_` are treated as literals, not as the
				// MySQL "any single character" wildcard. Without this the
				// query can't use the index on option_name and degrades
				// to a full-table scan; with it, MySQL can range-scan the
				// prefix and only the `%gh_release%` tail forces a scan
				// of the (much smaller) matching range. The `$pat` half
				// is left alone — those `%` are intentional wildcards.
				$like = $wpdb->esc_like( $prefix ) . $pat;
				$sql  = "SELECT {$column} FROM {$table} WHERE {$column} LIKE %s"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = $wpdb->get_col( $wpdb->prepare( $sql, $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-shot admin cache clear: column/table are hardcoded WP internals; the only user-derived value is bound via prepare(%s). A direct, uncached query is required to find transient keys by pattern.
				if ( $rows ) {
					$found = array_merge( $found, $rows );
				}
			}
		}

		foreach ( array_unique( $found ) as $key ) {
			$transient = preg_replace( '/^_site_transient_(?:timeout_)?/', '', $key );
			if ( $transient ) {
				delete_site_transient( $transient );
			}
		}
	}

	/**
	 * Synchronously warm every Coywolf self-updater's GitHub-release cache
	 * before we redirect into WordPress's force-check.
	 *
	 * Why this exists: each Coywolf plugin's updater deliberately keeps the
	 * GitHub request OFF the page-render path. On a cold cache it schedules a
	 * background WP-Cron refresh and injects nothing that round (see
	 * Coywolf_RPU_GitHub_Updater::inject_update). Because clear_update_caches()
	 * just deleted those release caches, the force-check we are about to
	 * redirect into would find every cache cold, defer all the GitHub fetches
	 * to cron, and show no GitHub-hosted updates — they would only appear on
	 * the NEXT admin page load, once the cron tick had populated the caches.
	 *
	 * Clicking the Reset button is a deliberate, one-off user action, which is
	 * exactly the context where a bounded synchronous GitHub fetch is the
	 * right trade (the updater makes the same call for "View details"). Each
	 * Coywolf updater registers a per-plugin cron hook named
	 * `coywolf_<abbr>_refresh_release` whose callback fetches the latest
	 * release and warms the cache; firing those hooks here, in-request, means
	 * the redirected force-check finds warm caches and surfaces the updates on
	 * the very page the user lands on.
	 *
	 * Matching by hook name (rather than via a shared, explicit action hook)
	 * is intentional: it warms updaters exactly as they are ALREADY shipped,
	 * so this works against every installed Coywolf plugin without having to
	 * re-release each one. A registered refresh hook also means the updater is
	 * active, so do_action() never fires against a dormant plugin.
	 */
	private static function warm_github_updater_caches() {
		if ( empty( $GLOBALS['wp_filter'] ) || ! is_array( $GLOBALS['wp_filter'] ) ) {
			return;
		}
		foreach ( array_keys( $GLOBALS['wp_filter'] ) as $hook ) {
			if ( is_string( $hook )
				&& 0 === strpos( $hook, 'coywolf_' )
				&& '_refresh_release' === substr( $hook, -16 ) ) {
				// On a cold cache the callback performs one bounded GitHub
				// fetch and stores the result; on a warm cache it is a no-op.
				do_action( $hook );
			}
		}
	}

	/**
	 * Show a one-shot success notice after the redirect lands on
	 * update-core.php. Deleted on first render so it does not re-appear on
	 * subsequent page loads.
	 */
	public static function maybe_render_notice() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$key = self::NOTICE_KEY . get_current_user_id();
		if ( ! get_transient( $key ) ) {
			return;
		}
		delete_transient( $key );

		printf(
			'<div class="notice notice-success is-dismissible"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html__( 'Plugin update cache cleared and WordPress is re-checking now.', 'coywolf-reset-updates' ),
			esc_url( admin_url( 'plugins.php' ) ),
			esc_html__( 'Go to the Plugins page', 'coywolf-reset-updates' )
		);
	}
}

Coywolf_Reset_Plugin_Update::boot();

/* wporg-strip:start — GitHub self-updater (removed from the WordPress.org build) */
// Self-update from GitHub Releases. Without this, the "Reset plugin update
// cache" button has nothing to surface for THIS plugin — WordPress only
// knows about wp.org-hosted plugins by default. This filter teaches WP to
// look at the project's GitHub releases for newer versions.
( new Coywolf_RPU_GitHub_Updater( __FILE__, Coywolf_Reset_Plugin_Update::VERSION ) )->init();
/* wporg-strip:end */
