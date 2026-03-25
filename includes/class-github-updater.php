<?php
/**
 * GitHub Updater — checks GitHub releases for new plugin versions
 * and integrates with WordPress's built-in update system.
 *
 * WordPress will show "Update Available" on the Plugins page
 * whenever you push a new GitHub release with a higher version tag.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AI_Alt_Text_GitHub_Updater {

    private $file;           // Full path to main plugin file
    private $plugin;         // Plugin basename (e.g. folder/file.php)
    private $plugin_data;    // Plugin header data
    private $github_owner;   // GitHub username
    private $github_repo;    // GitHub repo name
    private $github_response; // Cached API response

    public function __construct( $file, $owner, $repo ) {
        $this->file         = $file;
        $this->github_owner = $owner;
        $this->github_repo  = $repo;

        add_action( 'admin_init', array( $this, 'set_plugin_data' ) );
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
        add_filter( 'upgrader_post_install', array( $this, 'after_install' ), 10, 3 );
    }

    /**
     * Load plugin header data.
     */
    public function set_plugin_data() {
        $this->plugin      = plugin_basename( $this->file );
        $this->plugin_data = get_plugin_data( $this->file );
    }

    /**
     * Fetch the latest release info from GitHub API.
     */
    private function get_github_release() {
        if ( ! empty( $this->github_response ) ) {
            return $this->github_response;
        }

        $url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            $this->github_owner,
            $this->github_repo
        );

        $response = wp_remote_get( $url, array(
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ),
            ),
            'timeout' => 15,
        ) );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ) );
        if ( empty( $body ) || ! isset( $body->tag_name ) ) {
            return false;
        }

        $this->github_response = $body;
        return $body;
    }

    /**
     * Hook into WordPress update check — tell WP a new version exists.
     */
    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $this->set_plugin_data();
        $release = $this->get_github_release();

        if ( ! $release ) {
            return $transient;
        }

        // Strip "v" prefix from tag (e.g. "v1.2.0" → "1.2.0")
        $remote_version = ltrim( $release->tag_name, 'v' );
        $local_version  = $this->plugin_data['Version'];

        if ( version_compare( $remote_version, $local_version, '>' ) ) {
            $download_url = sprintf(
                'https://github.com/%s/%s/archive/refs/tags/%s.zip',
                $this->github_owner,
                $this->github_repo,
                $release->tag_name
            );

            $plugin_info = (object) array(
                'slug'        => dirname( $this->plugin ),
                'plugin'      => $this->plugin,
                'new_version' => $remote_version,
                'url'         => $this->plugin_data['PluginURI'],
                'package'     => $download_url,
                'icons'       => array(),
                'banners'     => array(),
            );

            $transient->response[ $this->plugin ] = $plugin_info;
        }

        return $transient;
    }

    /**
     * Provide plugin info when WordPress asks (View Details link).
     */
    public function plugin_info( $result, $action, $args ) {
        if ( $action !== 'plugin_information' ) {
            return $result;
        }

        if ( ! isset( $args->slug ) || $args->slug !== dirname( $this->plugin ) ) {
            return $result;
        }

        $this->set_plugin_data();
        $release = $this->get_github_release();

        if ( ! $release ) {
            return $result;
        }

        $remote_version = ltrim( $release->tag_name, 'v' );

        $info = (object) array(
            'name'              => $this->plugin_data['Name'],
            'slug'              => dirname( $this->plugin ),
            'version'           => $remote_version,
            'author'            => $this->plugin_data['AuthorName'],
            'homepage'          => $this->plugin_data['PluginURI'],
            'requires'          => '5.0',
            'tested'            => get_bloginfo( 'version' ),
            'requires_php'      => '7.4',
            'download_link'     => sprintf(
                'https://github.com/%s/%s/archive/refs/tags/%s.zip',
                $this->github_owner,
                $this->github_repo,
                $release->tag_name
            ),
            'sections'          => array(
                'description'  => $this->plugin_data['Description'],
                'changelog'    => nl2br( esc_html( $release->body ) ),
            ),
            'last_updated'      => $release->published_at,
        );

        return $info;
    }

    /**
     * After WordPress downloads and extracts the zip, rename the folder
     * to match the expected plugin directory name.
     *
     * GitHub zips extract as "repo-name-tag/" but WordPress expects
     * the folder name to match the existing plugin directory.
     */
    public function after_install( $response, $hook_extra, $result ) {
        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin ) {
            return $result;
        }

        global $wp_filesystem;

        $plugin_dir    = WP_PLUGIN_DIR . '/' . dirname( $this->plugin );
        $installed_dir = $result['destination'];

        // Move to the correct directory name
        $wp_filesystem->move( $installed_dir, $plugin_dir );
        $result['destination'] = $plugin_dir;

        // Re-activate the plugin
        activate_plugin( $this->plugin );

        return $result;
    }
}
