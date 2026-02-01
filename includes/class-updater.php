<?php
/**
 * Plugin Updater Class
 * 
 * Handles automatic updates from GitHub Releases.
 *
 * @package Woo_Nalda_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Updater class
 */
class WNS_Updater {

    /**
     * GitHub repository owner
     */
    const GITHUB_OWNER = '3AG-App';

    /**
     * GitHub repository name
     */
    const GITHUB_REPO = 'woo-nalda-sync';

    /**
     * Product slug
     */
    const PRODUCT_SLUG = 'woo-nalda-sync';

    /**
     * Cache key for update data
     */
    const CACHE_KEY = 'wns_update_data';

    /**
     * Cache expiration in seconds (12 hours)
     */
    const CACHE_EXPIRATION = 43200;

    /**
     * Constructor
     */
    public function __construct() {
        // Hook into WordPress update system
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
        add_filter( 'upgrader_post_install', array( $this, 'after_install' ), 10, 3 );

        // Enable auto-updates by default
        add_filter( 'auto_update_plugin', array( $this, 'enable_auto_update' ), 10, 2 );

        // Schedule periodic update check
        add_action( 'wns_update_check', array( $this, 'scheduled_check' ) );
        if ( ! wp_next_scheduled( 'wns_update_check' ) ) {
            wp_schedule_event( time(), 'twicedaily', 'wns_update_check' );
        }
    }

    /**
     * Enable auto-updates for this plugin by default
     *
     * @param bool|null $update Whether to update the plugin.
     * @param object    $item   The plugin update object.
     * @return bool|null
     */
    public function enable_auto_update( $update, $item ) {
        if ( isset( $item->plugin ) && $item->plugin === WNS_PLUGIN_BASENAME ) {
            return true;
        }
        return $update;
    }

    /**
     * Get GitHub API URL for latest release
     *
     * @return string
     */
    private function get_github_api_url() {
        return sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            self::GITHUB_OWNER,
            self::GITHUB_REPO
        );
    }

    /**
     * Get GitHub repository URL
     *
     * @return string
     */
    private function get_github_repo_url() {
        return sprintf(
            'https://github.com/%s/%s',
            self::GITHUB_OWNER,
            self::GITHUB_REPO
        );
    }

    /**
     * Check for updates via GitHub API
     *
     * @param object $transient Update transient.
     * @return object
     */
    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        // Get update data (cached or fresh)
        $update_data = $this->get_update_data();

        if ( ! $update_data || empty( $update_data['version'] ) ) {
            return $transient;
        }

        // Get current version
        $current_version = WNS_VERSION;

        // Compare versions
        if ( version_compare( $current_version, $update_data['version'], '<' ) ) {
            $transient->response[ WNS_PLUGIN_BASENAME ] = (object) array(
                'slug'         => self::PRODUCT_SLUG,
                'plugin'       => WNS_PLUGIN_BASENAME,
                'new_version'  => $update_data['version'],
                'url'          => $this->get_github_repo_url(),
                'package'      => $update_data['download_url'],
                'icons'        => array(
                    '1x' => WNS_PLUGIN_URL . 'assets/images/icon-128x128.png',
                    '2x' => WNS_PLUGIN_URL . 'assets/images/icon-256x256.png',
                ),
                'banners'      => array(
                    'low'  => WNS_PLUGIN_URL . 'assets/images/banner-772x250.png',
                    'high' => WNS_PLUGIN_URL . 'assets/images/banner-1544x500.png',
                ),
                'tested'       => '6.7',
                'requires_php' => '7.4',
                'requires'     => '5.8',
            );
        } else {
            // No update available, but still add to no_update list
            $transient->no_update[ WNS_PLUGIN_BASENAME ] = (object) array(
                'slug'        => self::PRODUCT_SLUG,
                'plugin'      => WNS_PLUGIN_BASENAME,
                'new_version' => $current_version,
                'url'         => $this->get_github_repo_url(),
            );
        }

        return $transient;
    }

    /**
     * Get update data from GitHub API or cache
     *
     * @param bool $force Force refresh.
     * @return array|null
     */
    public function get_update_data( $force = false ) {
        // Check cache first
        if ( ! $force ) {
            $cached = get_transient( self::CACHE_KEY );
            if ( false !== $cached ) {
                return $cached;
            }
        }

        // Make GitHub API request
        $response = wp_remote_get(
            $this->get_github_api_url(),
            array(
                'timeout' => 30,
                'headers' => array(
                    'Accept'     => 'application/vnd.github.v3+json',
                    'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            error_log( 'WNS GitHub Update Check Error: ' . $response->get_error_message() );
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( 200 !== $code || empty( $data ) ) {
            if ( 404 === $code ) {
                error_log( 'WNS GitHub Update Check: No releases found' );
            } else {
                error_log( 'WNS GitHub Update Check HTTP ' . $code . ': ' . ( $data['message'] ?? 'Unknown error' ) );
            }
            return null;
        }

        // Extract version from tag_name (remove 'v' prefix if present)
        $version = isset( $data['tag_name'] ) ? ltrim( $data['tag_name'], 'v' ) : null;

        // Find the zip download URL from assets
        $download_url = null;
        if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
            foreach ( $data['assets'] as $asset ) {
                // Look for the latest zip file
                if ( isset( $asset['name'] ) && strpos( $asset['name'], '-latest.zip' ) !== false ) {
                    $download_url = $asset['browser_download_url'];
                    break;
                }
                // Fallback to versioned zip
                if ( isset( $asset['name'] ) && preg_match( '/\.zip$/', $asset['name'] ) ) {
                    $download_url = $asset['browser_download_url'];
                }
            }
        }

        // Fallback to zipball_url if no asset found
        if ( empty( $download_url ) && ! empty( $data['zipball_url'] ) ) {
            $download_url = $data['zipball_url'];
        }

        $update_data = array(
            'version'      => $version,
            'download_url' => $download_url,
            'changelog'    => $data['body'] ?? '',
            'release_date' => $data['published_at'] ?? null,
            'checked'      => time(),
        );

        // Cache the result
        set_transient( self::CACHE_KEY, $update_data, self::CACHE_EXPIRATION );

        return $update_data;
    }

    /**
     * Provide plugin info for the WordPress plugin info popup
     *
     * @param false|object|array $result Result.
     * @param string             $action Action.
     * @param object             $args   Arguments.
     * @return object|false
     */
    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }

        if ( ! isset( $args->slug ) || self::PRODUCT_SLUG !== $args->slug ) {
            return $result;
        }

        $update_data = $this->get_update_data();

        $plugin_info = (object) array(
            'name'           => 'WooCommerce Nalda Marketplace Sync',
            'slug'           => self::PRODUCT_SLUG,
            'version'        => $update_data['version'] ?? WNS_VERSION,
            'author'         => '<a href="https://github.com/' . self::GITHUB_OWNER . '">3AG Systems</a>',
            'author_profile' => 'https://github.com/' . self::GITHUB_OWNER,
            'homepage'       => $this->get_github_repo_url(),
            'requires'       => '5.8',
            'tested'         => '6.7',
            'requires_php'   => '7.4',
            'downloaded'     => 0,
            'last_updated'   => $update_data['release_date'] ?? gmdate( 'Y-m-d H:i:s' ),
            'sections'       => array(
                'description'  => $this->get_plugin_description(),
                'installation' => $this->get_installation_instructions(),
                'changelog'    => $this->get_changelog( $update_data ),
            ),
            'download_link'  => $update_data['download_url'] ?? '',
            'banners'        => array(
                'low'  => WNS_PLUGIN_URL . 'assets/images/banner-772x250.png',
                'high' => WNS_PLUGIN_URL . 'assets/images/banner-1544x500.png',
            ),
        );

        return $plugin_info;
    }

    /**
     * Get plugin description
     *
     * @return string
     */
    private function get_plugin_description() {
        return '<p>WooCommerce Nalda Marketplace Sync is a premium WordPress plugin that automatically synchronizes your WooCommerce products and orders with Nalda.com marketplace.</p>
        <h4>Key Features:</h4>
        <ul>
            <li><strong>Product Export</strong> - Export products to Nalda marketplace via SFTP CSV</li>
            <li><strong>Order Import</strong> - Import orders from Nalda via API</li>
            <li><strong>Order Status Sync</strong> - Export order status updates back to Nalda</li>
            <li><strong>Flexible Scheduling</strong> - Schedule syncs from every 5 minutes to weekly</li>
            <li><strong>Per-Product Control</strong> - Include or exclude products individually</li>
            <li><strong>Watchdog Cron</strong> - Automatic recovery if scheduled sync stops working</li>
            <li><strong>Detailed Logs</strong> - Complete history of all sync operations</li>
            <li><strong>Modern UI</strong> - Clean, intuitive admin interface</li>
        </ul>
        <p><a href="' . esc_url( $this->get_github_repo_url() ) . '" target="_blank">View on GitHub</a></p>';
    }

    /**
     * Get installation instructions
     *
     * @return string
     */
    private function get_installation_instructions() {
        return '<ol>
            <li>Download the latest release from <a href="' . esc_url( $this->get_github_repo_url() . '/releases' ) . '">GitHub Releases</a></li>
            <li>Upload the plugin files to <code>/wp-content/plugins/woo-nalda-sync</code></li>
            <li>Activate the plugin through the \'Plugins\' screen in WordPress</li>
            <li>Go to Nalda Sync → License to activate your license key</li>
            <li>Configure your SFTP and API credentials in Nalda Sync → Settings</li>
            <li>Enable scheduled sync or run a manual sync from the Dashboard</li>
        </ol>';
    }

    /**
     * Get changelog from GitHub release or readme.txt
     *
     * @param array $update_data Update data.
     * @return string
     */
    private function get_changelog( $update_data = null ) {
        // First try to use GitHub release notes
        if ( ! empty( $update_data['changelog'] ) ) {
            // Convert markdown to basic HTML
            $changelog = $update_data['changelog'];

            // Convert headers
            $changelog = preg_replace( '/^### (.+)$/m', '<h4>$1</h4>', $changelog );
            $changelog = preg_replace( '/^## (.+)$/m', '<h3>$1</h3>', $changelog );

            // Convert bullet points
            $changelog = preg_replace( '/^[*-] (.+)$/m', '<li>$1</li>', $changelog );
            $changelog = preg_replace( '/(<li>.+<\/li>\n?)+/s', '<ul>$0</ul>', $changelog );

            // Convert line breaks
            $changelog = nl2br( $changelog );

            return $changelog;
        }

        // Fallback to readme.txt
        $readme_file = WNS_PLUGIN_DIR . 'readme.txt';

        if ( ! file_exists( $readme_file ) ) {
            return '<p>See the <a href="' . esc_url( $this->get_github_repo_url() . '/releases' ) . '">GitHub releases</a> for full changelog.</p>';
        }

        $readme = file_get_contents( $readme_file );

        // Extract changelog section
        if ( preg_match( '/== Changelog ==(.+?)(?:== |$)/s', $readme, $matches ) ) {
            $changelog = trim( $matches[1] );

            // Convert to HTML
            $changelog = preg_replace( '/^= (.+?) =$/m', '<h4>$1</h4>', $changelog );
            $changelog = preg_replace( '/^\* (.+)$/m', '<li>$1</li>', $changelog );
            $changelog = preg_replace( '/(<li>.+<\/li>\n?)+/s', '<ul>$0</ul>', $changelog );

            return $changelog;
        }

        return '<p>See the <a href="' . esc_url( $this->get_github_repo_url() . '/releases' ) . '">GitHub releases</a> for full changelog.</p>';
    }

    /**
     * After plugin install, rename directory if needed
     *
     * @param bool  $response   Response.
     * @param array $hook_extra Hook extra data.
     * @param array $result     Result.
     * @return bool
     */
    public function after_install( $response, $hook_extra, $result ) {
        global $wp_filesystem;

        // Only handle our plugin
        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== WNS_PLUGIN_BASENAME ) {
            return $response;
        }

        // Initialize WP_Filesystem if not already
        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . '/wp-admin/includes/file.php';
            WP_Filesystem();
        }

        // Verify filesystem is available
        if ( empty( $wp_filesystem ) || ! is_object( $wp_filesystem ) ) {
            return $response;
        }

        // Move plugin to correct directory
        $plugin_dir = WP_PLUGIN_DIR . '/' . dirname( WNS_PLUGIN_BASENAME );

        if ( $wp_filesystem->exists( $result['destination'] ) && $result['destination'] !== $plugin_dir ) {
            $wp_filesystem->move( $result['destination'], $plugin_dir );
            $result['destination'] = $plugin_dir;
        }

        // Reactivate plugin
        activate_plugin( WNS_PLUGIN_BASENAME );

        return $response;
    }

    /**
     * Scheduled update check
     */
    public function scheduled_check() {
        // Force refresh update data
        $this->get_update_data( true );
    }

    /**
     * Clear update cache
     */
    public function clear_cache() {
        delete_transient( self::CACHE_KEY );
    }

    /**
     * Force check for updates
     */
    public function force_check() {
        $this->clear_cache();
        delete_site_transient( 'update_plugins' );
        wp_update_plugins();
    }
}
