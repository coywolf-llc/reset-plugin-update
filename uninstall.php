<?php
/**
 * Uninstall cleanup for Coywolf Reset Updates.
 *
 * The plugin does not store any persistent options of its own; it only
 * deletes WordPress's plugin-update site transients on demand. To leave the
 * site in a clean state after the plugin is removed, drop those transients
 * one more time so the next plugin-update check rebuilds from scratch.
 *
 * @package CoywolfResetPluginUpdate
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_site_transient( 'update_plugins' );

if ( function_exists( 'wp_clean_plugins_cache' ) ) {
	wp_clean_plugins_cache( true );
}
