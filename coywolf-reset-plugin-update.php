<?php
/**
 * Plugin Name:       Coywolf Reset Plugin Update
 * Plugin URI:        https://github.com/coywolf-llc/reset-plugin-update
 * Description:       Adds a Tools → Reset Updates page with a button that flushes the plugin update cache, forcing WordPress to re-check for updates on plugins hosted on GitHub or the wordpress.org plugin repository.
 * Version:           1.0.0
 * Requires at least: 5.0
 * Requires PHP:      7.2
 * Author:            Coywolf
 * Author URI:        https://coywolf.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       coywolf-rpu
 *
 * @package CoywolfResetPluginUpdate
 *
 * Coywolf Reset Plugin Update
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

final class Coywolf_Reset_Plugin_Update {

	const VERSION   = '1.0.0';
	const SLUG      = 'coywolf-rpu';
	const ACTION    = 'coywolf_rpu_reset';
	const CAPABILITY = 'update_plugins';

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_reset' ) );
	}

	public static function register_menu() {
		add_management_page(
			__( 'Reset Updates', 'coywolf-rpu' ),
			__( 'Reset Updates', 'coywolf-rpu' ),
			self::CAPABILITY,
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'coywolf-rpu' ) );
		}

		$updated = isset( $_GET['updated'] ) && '1' === $_GET['updated']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Reset Plugin Updates', 'coywolf-rpu' ); ?></h1>

			<?php if ( $updated ) : ?>
				<div class="notice notice-success">
					<p>
						<?php esc_html_e( 'Plugin update cache cleared. WordPress will check for updates on the next request.', 'coywolf-rpu' ); ?>
						<a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>"><?php esc_html_e( 'Go to the Plugins page', 'coywolf-rpu' ); ?></a>
						<?php esc_html_e( 'or', 'coywolf-rpu' ); ?>
						<a href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>"><?php esc_html_e( 'view the Updates screen', 'coywolf-rpu' ); ?></a>.
					</p>
				</div>
			<?php endif; ?>

			<p>
				<?php esc_html_e( 'Clears the cached plugin update data so WordPress re-checks every plugin for new versions — including plugins hosted on GitHub and on the wordpress.org plugin repository. Useful when a release was just published and you do not want to wait for the next scheduled check.', 'coywolf-rpu' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::ACTION ); ?>
				<?php submit_button( __( 'Reset plugin update cache', 'coywolf-rpu' ), 'primary', 'submit', true ); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_reset() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'coywolf-rpu' ) );
		}
		check_admin_referer( self::ACTION );

		self::reset_plugin_update_cache();

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'updated' => '1' ), admin_url( 'tools.php' ) ) );
		exit;
	}

	/**
	 * Drop the cached plugin update data, including throttle caches commonly
	 * used by GitHub-hosted plugin updaters, then ask WordPress to re-check.
	 */
	private static function reset_plugin_update_cache() {
		global $wpdb;

		delete_site_transient( 'update_plugins' );
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( true );
		}

		// Clear site transients that look like GitHub-release throttle caches
		// used by self-hosted plugin updaters (e.g. "*_gh_release",
		// "*_github_release", "*_github_update"). Both single and multisite
		// keep site transients in the sitemeta / options table.
		$patterns = array(
			'_site_transient_%_gh_release',
			'_site_transient_%_github_release',
			'_site_transient_%_github_update',
			'_site_transient_timeout_%_gh_release',
			'_site_transient_timeout_%_github_release',
			'_site_transient_timeout_%_github_update',
		);

		if ( is_multisite() ) {
			$table  = $wpdb->sitemeta;
			$column = 'meta_key';
		} else {
			$table  = $wpdb->options;
			$column = 'option_name';
		}

		$keys = array();
		foreach ( $patterns as $pattern ) {
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT {$column} FROM {$table} WHERE {$column} LIKE %s", $pattern ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $rows ) {
				$keys = array_merge( $keys, $rows );
			}
		}

		foreach ( array_unique( $keys ) as $key ) {
			$transient = preg_replace( '/^_site_transient_(timeout_)?/', '', $key );
			if ( $transient ) {
				delete_site_transient( $transient );
			}
		}

		// Ask WordPress to run the plugin update check immediately so the
		// Updates / Plugins screens reflect the new state on the redirect.
		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}
	}
}

Coywolf_Reset_Plugin_Update::boot();
