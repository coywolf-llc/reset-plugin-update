=== Coywolf Reset Plugin Update ===
Contributors: coywolf
Tags: plugin updates, updates, cache, github, maintenance
Requires at least: 5.0
Tested up to: 6.5
Requires PHP: 7.2
Stable tag: 1.0.16
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

= 1.0.16 =
* Add Atom-feed fallback when the GitHub API is rate-limited (#17).

= 1.0.15 =
* Fix: don't block manual zip uploads (untrusted host error) (#16).

= 1.0.14 =
* Harden guard_pre_download: use hook_extra, fail closed (#15).

= 1.0.13 =
* Perf: honour _neg cache + esc_like LIKE prefixes (#14).

= 1.0.12 =
* Bump Tested up to: 7.0 (#13).

= 1.0.11 =
* Test the v1.0.10 updater-flush refresh-current_version fix (#12).

= 1.0.10 =
* Updater: refresh current_version after upgrade (fix the double-update prompt) (#11).

= 1.0.9 =
* Test the v1.0.8 updater-flush fix (#10).

= 1.0.8 =
* Updater: clear update_plugins transient after self-update (#9).

= 1.0.7 =
* No-op release to verify the plugin icon shows on the Updates row once the site is running an icon-aware updater (1.0.6+).

= 1.0.6 =
* Show the plugin icon on the Updates / Plugins / View-details screens by populating `icons` on the update object and the `plugins_api` response.

= 1.0.5 =
* No-op release to verify the GitHub self-updater surfaces this plugin on the Updates screen.

= 1.0.4 =
* Add GitHub self-updater so the button can surface this plugin's own updates (#5).

= 1.0.3 =
* Harden handle_reset(): POST-only + explicit login check (#4).

= 1.0.2 =
* Fix: redirect to update-core.php to actually trigger the re-check (#3).

= 1.0.1 =
* Fix: point Plugin URI to GitHub repo (#2).

= 1.0.0 =
* Initial release.
