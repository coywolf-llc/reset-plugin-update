<?php
/**
 * GitHub Releases-based plugin updater.
 *
 * Teaches WordPress's built-in plugin update flow to pull this plugin's
 * updates from the project's GitHub releases. When a release exists whose
 * tag (with optional "v" prefix stripped) is newer than the installed
 * version, the plugin shows up in Dashboard → Updates and the standard
 * one-click "Update Now" works.
 *
 * Requires no auth (the repo is public). The latest release is cached in
 * a transient for {@see self::CACHE_HOURS} so the GitHub API is not hit
 * on every admin pageload.
 *
 * @package CoywolfResetPluginUpdate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Coywolf_RPU_GitHub_Updater {

	const REPO          = 'coywolf-llc/reset-plugin-update';
	const TRANSIENT_KEY = 'coywolf_rpu_gh_release';
	const CACHE_HOURS   = 6;

	/**
	 * Plugin file relative to wp-content/plugins, e.g.
	 * "coywolf-broken-link-checker/coywolf-broken-link-checker.php".
	 *
	 * @var string
	 */
	private $plugin_basename;

	/**
	 * Plugin slug (the containing folder name).
	 *
	 * @var string
	 */
	private $plugin_slug;

	/**
	 * Installed plugin version.
	 *
	 * @var string
	 */
	private $current_version;

	/**
	 * @param string $plugin_file     Absolute path to the main plugin file.
	 * @param string $current_version Currently installed version.
	 */
	public function __construct( $plugin_file, $current_version ) {
		$this->plugin_basename = plugin_basename( $plugin_file );
		$this->plugin_slug     = dirname( $this->plugin_basename );
		if ( '.' === $this->plugin_slug ) {
			$this->plugin_slug = basename( $plugin_file, '.php' );
		}
		$this->current_version = (string) $current_version;
	}

	/**
	 * Wire into the WordPress update flow.
	 */
	public function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dirname' ), 10, 4 );
		add_filter( 'plugin_row_meta', array( $this, 'override_view_details' ), 10, 2 );
		add_filter( 'upgrader_pre_download', array( $this, 'guard_pre_download' ), 10, 3 );
	}

	/**
	 * Last-line defence before WordPress actually downloads the update
	 * package: refuse to fetch a URL that is not on the GitHub-owned host
	 * allowlist. Catches a transient cache that was tampered with after
	 * inject_update() ran, or any other plugin that filters the package
	 * URL between the update check and the download step.
	 *
	 * Returning a WP_Error makes WP abort this upgrade and surface the
	 * message in the admin notice; returning $reply (false by default)
	 * lets WP proceed with its normal download.
	 *
	 * @param mixed         $reply    Filtered reply (default false → "go ahead").
	 * @param string        $package  Package URL WP is about to download.
	 * @param WP_Upgrader   $upgrader Upgrader instance (unused).
	 * @return mixed
	 */
	public function guard_pre_download( $reply, $package, $upgrader ) {
		unset( $upgrader );
		// Only inspect updates that are clearly ours.
		if ( ! is_string( $package ) || '' === $package ) {
			return $reply;
		}
		$parts = wp_parse_url( $package );
		if ( ! is_array( $parts ) || empty( $parts['path'] ) ) {
			return $reply;
		}
		$looks_like_ours = ( false !== stripos( $parts['path'], self::REPO ) )
			|| ( false !== stripos( $parts['path'], $this->plugin_slug ) );
		if ( ! $looks_like_ours ) {
			return $reply;
		}
		if ( '' === $this->validate_package_url( $package ) ) {
			return new WP_Error(
				'coywolf_rpu_untrusted_package',
				__( 'Refusing to download a plugin update from an untrusted host.', 'coywolf-rpu' )
			);
		}
		return $reply;
	}

	/**
	 * Replace the auto-generated "View details" link on the Plugins list row
	 * (which would otherwise open the plugins_api thickbox iframe) with a
	 * direct link to the project's GitHub repository.
	 *
	 * The {@see plugin_info()} handler is kept registered because WordPress
	 * still calls plugins_api during the update flow itself; only the row
	 * meta link is rerouted.
	 *
	 * @param string[] $plugin_meta Row meta links.
	 * @param string   $plugin_file Plugin basename being rendered.
	 * @return string[]
	 */
	public function override_view_details( $plugin_meta, $plugin_file ) {
		if ( $plugin_file !== $this->plugin_basename || ! is_array( $plugin_meta ) ) {
			return $plugin_meta;
		}
		$repo_url = 'https://github.com/' . self::REPO;
		foreach ( $plugin_meta as $i => $item ) {
			// WP's "View details" entry uses the plugin-install thickbox iframe.
			// Match on either the URL or the thickbox class so a different
			// translation of "View details" still gets caught.
			if ( false !== strpos( $item, 'plugin-install.php?tab=plugin-information' )
				|| false !== strpos( $item, 'class="thickbox' )
				|| false !== strpos( $item, "class='thickbox" ) ) {
				$plugin_meta[ $i ] = sprintf(
					'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
					esc_url( $repo_url ),
					esc_html__( 'View details', 'coywolf-rpu' )
				);
			}
		}
		return $plugin_meta;
	}

	/**
	 * If a newer GitHub release exists, append this plugin to the list of
	 * available updates so WordPress shows it on the Updates screen.
	 *
	 * @param object $transient The update_plugins transient.
	 * @return object
	 */
	public function inject_update( $transient ) {
		if ( empty( $transient ) || ! is_object( $transient ) ) {
			$transient = new stdClass();
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$remote_version = $this->normalize_version( $release['tag_name'] );
		if ( '' === $remote_version ) {
			return $transient;
		}

		$update_obj = $this->build_update_obj( $release, $remote_version );

		// Refuse to advertise an update we wouldn't actually install: if
		// the release's package URL didn't survive validate_package_url
		// (host not on the GitHub allowlist), there's nothing safe to
		// download — leave the transient untouched so WP doesn't show
		// a broken Update Now button.
		if ( empty( $update_obj->package ) ) {
			return $transient;
		}

		// Only offer an update when the remote version is strictly newer.
		if ( version_compare( $remote_version, $this->current_version, '<=' ) ) {
			if ( isset( $transient->no_update ) ) {
				$transient->no_update[ $this->plugin_basename ] = $update_obj;
			}
			return $transient;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		$transient->response[ $this->plugin_basename ] = $update_obj;

		return $transient;
	}

	/**
	 * Build the update object WordPress expects in the update_plugins
	 * transient.
	 */
	private function build_update_obj( $release, $remote_version ) {
		$obj                = new stdClass();
		$obj->id            = self::REPO;
		$obj->slug          = $this->plugin_slug;
		$obj->plugin        = $this->plugin_basename;
		$obj->new_version   = $remote_version;
		$obj->url           = 'https://github.com/' . self::REPO;
		$obj->package       = $this->pick_package_url( $release );
		$obj->icons         = array();
		$obj->banners       = array();
		$obj->banners_rtl   = array();
		$obj->tested        = '';
		$obj->requires_php  = '';
		$obj->compatibility = new stdClass();
		return $obj;
	}

	/**
	 * Populate the "View details" modal on the Plugins / Updates screen.
	 *
	 * @param mixed  $result Filtered value (false by default).
	 * @param string $action Requested action.
	 * @param object $args   Request args.
	 * @return mixed
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( empty( $args->slug ) || $args->slug !== $this->plugin_slug ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $result;
		}

		$info                = new stdClass();
		$info->name          = 'Coywolf Reset Plugin Update';
		$info->slug          = $this->plugin_slug;
		$info->version       = $this->normalize_version( $release['tag_name'] );
		$info->author        = '<a href="https://coywolf.com/">Coywolf</a>';
		$info->homepage      = 'https://github.com/' . self::REPO;
		$info->download_link = $this->pick_package_url( $release );
		$info->last_updated  = isset( $release['published_at'] ) ? $release['published_at'] : '';
		$info->sections      = array(
			'description' => 'Clears the WordPress plugin update cache so plugins hosted on GitHub or wordpress.org are re-checked immediately.',
			'changelog'   => $this->render_changelog( isset( $release['body'] ) ? $release['body'] : '' ),
		);
		return $info;
	}

	/**
	 * Convert the release notes (GitHub-flavored markdown) into very simple
	 * HTML for the "View details" modal. We avoid a full markdown parser —
	 * just turn headings, lists, code spans, and links into HTML.
	 */
	private function render_changelog( $markdown ) {
		$md = trim( (string) $markdown );
		if ( '' === $md ) {
			return '<p>See the GitHub release for changelog details.</p>';
		}
		$lines = preg_split( '/\r\n|\r|\n/', $md );
		$html  = '';
		$in_ul = false;
		foreach ( $lines as $line ) {
			$line = rtrim( $line );
			if ( preg_match( '/^(#{1,6})\s+(.*)$/', $line, $m ) ) {
				if ( $in_ul ) { $html .= '</ul>'; $in_ul = false; }
				$level = strlen( $m[1] );
				$html .= '<h' . $level . '>' . esc_html( $m[2] ) . '</h' . $level . '>';
			} elseif ( preg_match( '/^[*-]\s+(.*)$/', $line, $m ) ) {
				if ( ! $in_ul ) { $html .= '<ul>'; $in_ul = true; }
				$html .= '<li>' . $this->inline_md( $m[1] ) . '</li>';
			} elseif ( '' === $line ) {
				if ( $in_ul ) { $html .= '</ul>'; $in_ul = false; }
			} else {
				if ( $in_ul ) { $html .= '</ul>'; $in_ul = false; }
				$html .= '<p>' . $this->inline_md( $line ) . '</p>';
			}
		}
		if ( $in_ul ) { $html .= '</ul>'; }
		return $html;
	}

	/**
	 * Inline markdown: backtick code, [text](url), and entity-safe escaping.
	 */
	private function inline_md( $text ) {
		$text = esc_html( $text );
		$text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',
			function ( $m ) {
				return '<a href="' . esc_url( $m[2] ) . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>';
			},
			$text
		);
		return $text;
	}

	/**
	 * After WordPress extracts the downloaded zip but before it moves it
	 * into place, rename the source directory to match this plugin's slug.
	 *
	 * GitHub's auto-generated zipballs unpack to "<owner>-<repo>-<sha>/",
	 * which would otherwise be installed as a different plugin. A zip
	 * asset whose top-level folder is already correct is left untouched.
	 *
	 * @param string       $source        Extracted source path.
	 * @param string       $remote_source Remote source path.
	 * @param WP_Upgrader  $upgrader      Upgrader instance.
	 * @param array        $hook_extra    Extra data; includes 'plugin' on plugin upgrades.
	 * @return string|WP_Error
	 */
	public function fix_source_dirname( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
			return $source;
		}

		global $wp_filesystem;
		$expected = trailingslashit( dirname( $source ) ) . $this->plugin_slug;
		$source   = untrailingslashit( $source );

		if ( $source === $expected ) {
			return trailingslashit( $source );
		}

		if ( $wp_filesystem && $wp_filesystem->move( $source, $expected, true ) ) {
			return trailingslashit( $expected );
		}

		return new WP_Error(
			'coywolf_rpu_rename_failed',
			__( 'Could not rename the downloaded update folder to match the plugin slug.', 'coywolf-rpu' )
		);
	}

	/**
	 * Read the cached latest-release data, or fetch it from the GitHub API
	 * if the cache is empty.
	 *
	 * @return array|null Decoded release object, or null on failure.
	 */
	private function get_latest_release() {
		$cached = get_site_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$url = 'https://api.github.com/repos/' . self::REPO . '/releases/latest';
		$res = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			// Cache a tiny negative result so we don't hammer GitHub when
			// there are no releases yet (404) or we're rate-limited.
			set_site_transient( self::TRANSIENT_KEY . '_neg', $code, 15 * MINUTE_IN_SECONDS );
			return null;
		}
		$body = wp_remote_retrieve_body( $res );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			return null;
		}

		// Reduce stored payload — we only need a few fields.
		$keep = array(
			'tag_name'     => isset( $data['tag_name'] ) ? (string) $data['tag_name'] : '',
			'name'         => isset( $data['name'] ) ? (string) $data['name'] : '',
			'body'         => isset( $data['body'] ) ? (string) $data['body'] : '',
			'published_at' => isset( $data['published_at'] ) ? (string) $data['published_at'] : '',
			'html_url'     => isset( $data['html_url'] ) ? (string) $data['html_url'] : '',
			'zipball_url'  => isset( $data['zipball_url'] ) ? (string) $data['zipball_url'] : '',
			'assets'       => array(),
		);
		if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
			foreach ( $data['assets'] as $a ) {
				if ( ! is_array( $a ) ) { continue; }
				$keep['assets'][] = array(
					'name'                 => isset( $a['name'] ) ? (string) $a['name'] : '',
					'browser_download_url' => isset( $a['browser_download_url'] ) ? (string) $a['browser_download_url'] : '',
					'content_type'         => isset( $a['content_type'] ) ? (string) $a['content_type'] : '',
				);
			}
		}

		set_site_transient( self::TRANSIENT_KEY, $keep, self::CACHE_HOURS * HOUR_IN_SECONDS );
		return $keep;
	}

	/**
	 * Pick the best zip URL from the release: prefer a .zip asset (which
	 * we control the layout of); fall back to GitHub's auto-zipball.
	 */
	private function pick_package_url( $release ) {
		$candidate = '';
		if ( ! empty( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( ! empty( $asset['name'] ) && '.zip' === strtolower( substr( $asset['name'], -4 ) ) ) {
					$candidate = isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : '';
					break;
				}
			}
		}
		if ( '' === $candidate ) {
			$candidate = isset( $release['zipball_url'] ) ? (string) $release['zipball_url'] : '';
		}
		return $this->validate_package_url( $candidate );
	}

	/**
	 * Reject anything we wouldn't recognise as a GitHub-served release
	 * artefact. The Releases API can in principle return any URL (the
	 * cached transient is also writable by other privileged code), so
	 * before WordPress's upgrader downloads and executes whatever is at
	 * the other end, confirm the host is one GitHub actually uses for
	 * release zips and auto-zipballs.
	 *
	 * Returns an empty string when the URL is unacceptable, which causes
	 * WP to skip the update gracefully rather than installing it from a
	 * stranger.
	 *
	 * @param string $url Candidate package URL.
	 * @return string Validated URL, or '' if not trusted.
	 */
	private function validate_package_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		if ( empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) ) {
			return '';
		}
		if ( empty( $parts['host'] ) ) {
			return '';
		}
		$host = strtolower( $parts['host'] );
		$allowed = array(
			'github.com',
			'api.github.com',
			'codeload.github.com',
			'objects.githubusercontent.com',
			'release-assets.githubusercontent.com',
		);
		if ( ! in_array( $host, $allowed, true ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * Strip a leading "v" from a tag and return a clean semver-ish string.
	 */
	private function normalize_version( $tag ) {
		$tag = trim( (string) $tag );
		if ( '' !== $tag && ( 'v' === $tag[0] || 'V' === $tag[0] ) ) {
			$tag = substr( $tag, 1 );
		}
		return $tag;
	}
}
