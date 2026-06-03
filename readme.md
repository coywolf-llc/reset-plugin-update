<img src=".wordpress-org/icon-256x256.png" alt="Coywolf Reset Updates logo" width="128" />

# Coywolf Reset Updates

Adds a **Tools → Reset Updates** page with a button that clears the cached update data and forces WordPress to recheck for new versions — useful when a release was just published and you do not want to wait for the next scheduled check.

- **Version:** 1.0.27
- **Requires WordPress:** 5.0 or later
- **Tested up to:** 7.0
- **Requires PHP:** 7.2 or later
- **License:** [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)

## Description

WordPress caches plugin update data and only re-checks every 12 hours. When you have just published a new release (especially for a GitHub-hosted plugin) you usually do not want to wait. This plugin gives you a one-click way to drop that cache and force an immediate re-check.

- Adds a **Reset Updates** sub-menu under **Tools**.
- The page has a single **Reset update cache** button.
- Clicking it deletes the `update_plugins` site transient, clears the plugin-info cache (`wp_clean_plugins_cache`), and sweeps common GitHub-updater throttle caches (any site transient whose key contains `gh_release`, `github_release`, or `github_update` — including the `_neg` negative-response variants Coywolf's own updater uses).
- The handler then redirects to WordPress's own **Check Again** URL (`update-core.php?force-check=1`). That page's `load-update-core.php` hook calls `wp_version_check()`, `wp_update_plugins()`, and `wp_update_themes()` with `force = true`, so the re-check is guaranteed to run in the same request the user lands on — same flow the original `?coywolf_blc_check` snippet relied on.
- A one-shot success notice appears on the Updates page with a link to the **Plugins** screen so you can act on the freshly fetched results.
- Access is gated by the standard `update_plugins` capability — by default only administrators.
- Stores no options of its own. `uninstall.php` clears the plugin-update caches once more so the site is left clean when the plugin is removed.
- Self-updates from its own GitHub releases via the standard **Dashboard → Updates** flow (releases cached for 6 hours, downloads pinned to a GitHub host allowlist) — so the **Reset update cache** button can find newer versions of this plugin itself.

## Installation

1. Upload the `coywolf-reset-plugin-update` folder to `/wp-content/plugins/`, or upload the .zip via **Plugins → Add New → Upload Plugin**.
2. Activate the plugin.
3. Go to **Tools → Reset Updates** and click **Reset update cache**.

## Frequently Asked Questions

### Does this update my plugins?

No. It only clears the cached "what updates are available" data and asks WordPress to re-check. Installing the updates themselves still happens on the **Plugins** or **Dashboard → Updates** screens as usual.

### Will it pick up GitHub-hosted plugins?

Yes. In addition to the standard `update_plugins` transient, it sweeps any site transient whose key contains `gh_release`, `github_release`, or `github_update` — the patterns commonly used by self-hosted plugin updaters to throttle calls to the GitHub Releases API (including Coywolf's own `_gh_release` and `_gh_release_neg` keys). After clearing them, the forced re-check on `update-core.php` will hit GitHub instead of returning the cached response.

### Who can use it?

Anyone with the `update_plugins` capability — typically administrators only. On a multisite, only users who could already update plugins network-wide will see the Tools menu item.

## Changelog

### 1.0.27
- Resolve remaining Plugin Check warnings (#28).

### 1.0.26
- Tighten the plugin description to match the page copy (#27).

### 1.0.25
- Update the Reset Updates page copy (#26).

### 1.0.24
- Rename to "Coywolf Reset Updates" to drop the restricted term "plugin" (#25).

### 1.0.23
- CI: upgrade actions to Node 24-native versions (#24).

### 1.0.22
- CI: run bundled JS actions on Node 24 (#23).

### 1.0.21
- Set distinct Plugin URI and Author URI (#22).

### 1.0.20
- Fix readme Contributors/Tags header line (#21).

### 1.0.19
- Set readme Contributors to jonhenshaw (#20).

### 1.0.18
- Fix all WordPress.org Plugin Check errors (#19).

### 1.0.17
- Add WordPress.org variant build + gated SVN deploy (#18).

### 1.0.16
- Add Atom-feed fallback when the GitHub API is rate-limited (#17).

### 1.0.15
- Fix: don't block manual zip uploads (untrusted host error) (#16).

### 1.0.14
- Harden guard_pre_download: use hook_extra, fail closed (#15).

### 1.0.13
- Perf: honour _neg cache + esc_like LIKE prefixes (#14).

### 1.0.12
- Bump Tested up to: 7.0 (#13).

### 1.0.11
- Test the v1.0.10 updater-flush refresh-current_version fix (#12).

### 1.0.10
- Updater: refresh current_version after upgrade (fix the double-update prompt) (#11).

### 1.0.9
- Test the v1.0.8 updater-flush fix (#10).

### 1.0.8
- Updater: clear update_plugins transient after self-update (#9).

### 1.0.7
- No-op release to verify the plugin icon shows on the Updates row once the site is running an icon-aware updater (1.0.6+).

### 1.0.6
- Show the plugin icon on the Updates / Plugins / View-details screens by populating `icons` on the update object and the `plugins_api` response.

### 1.0.5
- No-op release to verify the GitHub self-updater surfaces this plugin on the Updates screen.

### 1.0.4
- Add GitHub self-updater so the button can surface this plugin's own updates (#5).

### 1.0.3
- Harden handle_reset(): POST-only + explicit login check (#4).

### 1.0.2
- Fix: redirect to update-core.php to actually trigger the re-check (#3).

### 1.0.1
- Fix: point Plugin URI to GitHub repo (#2).

### 1.0.0
- Initial release.


