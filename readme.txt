=== Coywolf Reset Plugin Update ===
Contributors: coywolf
Tags: plugin updates, updates, cache, github, maintenance
Requires at least: 5.0
Tested up to: 6.5
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One-click reset of the plugin update cache so WordPress re-checks every plugin for updates — including GitHub and wordpress.org plugins.

== Description ==

WordPress caches plugin update data and only re-checks every 12 hours. When
you have just published a new release (especially for a GitHub-hosted plugin)
you usually do not want to wait. This plugin gives you a one-click way to
drop that cache and force an immediate re-check.

* Adds a "Reset Updates" sub-menu under Tools.
* The page has a single "Reset plugin update cache" button.
* Clicking it deletes the `update_plugins` site transient, clears the
  plugin-info cache (`wp_clean_plugins_cache`), removes common GitHub-updater
  throttle caches (any site transient whose name ends in `_gh_release`,
  `_github_release`, or `_github_update`), and runs `wp_update_plugins()`
  immediately.
* On success the page redirects back to itself with a confirmation notice and
  a link to the Plugins screen (and to Dashboard -> Updates) so you can act
  on the freshly fetched results.
* Access is gated by the standard `update_plugins` capability — by default
  only administrators.
* Stores no options of its own. `uninstall.php` clears the plugin-update
  caches once more so the site is left clean when the plugin is removed.

== Installation ==

1. Upload the `coywolf-reset-plugin-update` folder to `/wp-content/plugins/`,
   or upload the .zip via Plugins -> Add New -> Upload Plugin.
2. Activate the plugin.
3. Go to Tools -> Reset Updates and click "Reset plugin update cache".

== Frequently Asked Questions ==

= Does this update my plugins? =

No. It only clears the cached "what updates are available" data and asks
WordPress to re-check. Installing the updates themselves still happens on
the Plugins or Dashboard -> Updates screens as usual.

= Will it pick up GitHub-hosted plugins? =

Yes. In addition to the standard `update_plugins` transient, it clears any
site transient whose key ends in `_gh_release`, `_github_release`, or
`_github_update` — the patterns commonly used by self-hosted plugin updaters
to throttle calls to the GitHub Releases API. After clearing them, the next
plugin-update check will hit GitHub instead of returning the cached response.

= Who can use it? =

Anyone with the `update_plugins` capability — typically administrators only.
On a multisite, only users who could already update plugins network-wide
will see the Tools menu item.

== Changelog ==

= 1.0.0 =
* Initial release.
