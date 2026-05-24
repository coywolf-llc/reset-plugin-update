<img src=".wordpress-org/icon-256x256.png" alt="Coywolf Reset Plugin Update logo" width="128" />

# Coywolf Reset Plugin Update

Adds a **Tools → Reset Updates** page with a single button that flushes the plugin update cache so WordPress re-checks every installed plugin for new versions — including plugins hosted on GitHub and on the wordpress.org plugin repository.

- **Version:** 1.0.2
- **Requires WordPress:** 5.0 or later
- **Tested up to:** 6.5
- **Requires PHP:** 7.2 or later
- **License:** [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)

## Description

WordPress caches plugin update data and only re-checks every 12 hours. When you have just published a new release (especially for a GitHub-hosted plugin) you usually do not want to wait. This plugin gives you a one-click way to drop that cache and force an immediate re-check.

- Adds a **Reset Updates** sub-menu under **Tools**.
- The page has a single **Reset plugin update cache** button.
- Clicking it deletes the `update_plugins` site transient, clears the plugin-info cache (`wp_clean_plugins_cache`), and sweeps common GitHub-updater throttle caches (any site transient whose key contains `gh_release`, `github_release`, or `github_update` — including the `_neg` negative-response variants Coywolf's own updater uses).
- The handler then redirects to WordPress's own **Check Again** URL (`update-core.php?force-check=1`). That page's `load-update-core.php` hook calls `wp_version_check()`, `wp_update_plugins()`, and `wp_update_themes()` with `force = true`, so the re-check is guaranteed to run in the same request the user lands on — same flow the original `?coywolf_blc_check` snippet relied on.
- A one-shot success notice appears on the Updates page with a link to the **Plugins** screen so you can act on the freshly fetched results.
- Access is gated by the standard `update_plugins` capability — by default only administrators.
- Stores no options of its own. `uninstall.php` clears the plugin-update caches once more so the site is left clean when the plugin is removed.

## Installation

1. Upload the `coywolf-reset-plugin-update` folder to `/wp-content/plugins/`, or upload the .zip via **Plugins → Add New → Upload Plugin**.
2. Activate the plugin.
3. Go to **Tools → Reset Updates** and click **Reset plugin update cache**.

## Frequently Asked Questions

### Does this update my plugins?

No. It only clears the cached "what updates are available" data and asks WordPress to re-check. Installing the updates themselves still happens on the **Plugins** or **Dashboard → Updates** screens as usual.

### Will it pick up GitHub-hosted plugins?

Yes. In addition to the standard `update_plugins` transient, it sweeps any site transient whose key contains `gh_release`, `github_release`, or `github_update` — the patterns commonly used by self-hosted plugin updaters to throttle calls to the GitHub Releases API (including Coywolf's own `_gh_release` and `_gh_release_neg` keys). After clearing them, the forced re-check on `update-core.php` will hit GitHub instead of returning the cached response.

### Who can use it?

Anyone with the `update_plugins` capability — typically administrators only. On a multisite, only users who could already update plugins network-wide will see the Tools menu item.

## Changelog

### 1.0.2
- Fix: redirect to update-core.php to actually trigger the re-check (#3).

### 1.0.1
- Fix: point Plugin URI to GitHub repo (#2).

### 1.0.0
- Initial release.
