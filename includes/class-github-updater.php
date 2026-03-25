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

    private $file;
    private $basename;
    private $github_owner;
    private $github_repo;
    private $github_response;

    public function __construct( $file, $owner, $repo ) {
        $this->file         = $file;
        $this->basename     = plugin_basename( $file );
        $this->github_owner = $owner;
        $this->github_repo  = $repo;

        // Block WordPress.org from ever seeing this plugin
        add_filter( 'http_request_args', array( $this, 'exclude_from_wporg' ), 5, 2 );

        // Remove any .org update data that slipped through (runs every time transient is read)
        add_filter( 'site_transient_update_plugins', array( $this, 'clean_wporg_updates' ) );

        // Inject our GitHub update info
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );

        // Show our own plugin details in the "View Details" popup
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );

        // Fix folder name after GitHub zip extraction
        add_filter( 'upgrader_post_install', array( $this, 'after_install' ), 10, 3 );
    }

    /**
     * Strip this plugin out of the request WordPress sends to api.wordpress.org
     * so .org never returns update data for it.
     */
    public function exclude_from_wporg( $args, $url ) {
        if ( strpos( $url, 'api.wordpress.org/plugins/update-check' ) === false ) {
            return $args;
        }

        if ( ! isset( $args['body']['plugins'] ) ) {
            return $args;
        }

        $plugins = json_decode( $args['body']['plugins'], true );

        if ( isset( $plugins['plugins'][ $this->basename ] ) ) {
            unset( $plugins['plugins'][ $this->basename ] );
        }

        if ( isset( $plugins['active'] ) && is_array( $plugins['active'] ) ) {
            $plugins['active'] = array_values( array_diff( $plugins['active'], array( $this->basename ) ) );
        }

        $args['body']['plugins'] = wp_json_encode( $plugins );

        return $args;
    }

    /**
     * Safety net: if WordPress.org somehow returned update data for our
     * plugin basename (from a cached transient, for example), strip it out
     * every time the transient is read.
     */
    public function clean_wporg_updates( $transient ) {
        // If .org put something in response for our basename, check if it's
        // actually from .org (not our GitHub data). Our GitHub data has our
        // repo URL in 'package'. If 'package' doesn't contain 'github.com',
        // it came from .org — remove it.
        if ( isset( $transient->response[ $this->basename ] ) ) {
            $pkg = '';
            if ( is_object( $transient->response[ $this->basename ] ) && isset( $transient->response[ $this->basename ]->package ) ) {
                $pkg = $transient->response[ $this->basename ]->package;
            }
            if ( strpos( $pkg, 'github.com' ) === false ) {
                unset( $transient->response[ $this->basename ] );
            }
        }

        return $transient;
    }

    /**
     * Fetch the latest release info from GitHub API (cached per request).
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

        $release = $this->get_github_release();
        if ( ! $release ) {
            return $transient;
        }

        $remote_version = ltrim( $release->tag_name, 'v' );
        $plugin_data    = get_plugin_data( $this->file );
        $local_version  = $plugin_data['Version'];

        if ( version_compare( $remote_version, $local_version, '>' ) ) {
            $download_url = sprintf(
                'https://github.com/%s/%s/archive/refs/tags/%s.zip',
                $this->github_owner,
                $this->github_repo,
                $release->tag_name
            );

            $transient->response[ $this->basename ] = (object) array(
                'slug'        => dirname( $this->basename ),
                'plugin'      => $this->basename,
                'new_version' => $remote_version,
                'url'         => $plugin_data['PluginURI'],
                'package'     => $download_url,
                'icons'       => array(),
                'banners'     => array(),
            );
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

        if ( ! isset( $args->slug ) || $args->slug !== dirname( $this->basename ) ) {
            return $result;
        }

        $release = $this->get_github_release();
        if ( ! $release ) {
            return $result;
        }

        $plugin_data    = get_plugin_data( $this->file );
        $remote_version = ltrim( $release->tag_name, 'v' );

        return (object) array(
            'name'          => $plugin_data['Name'],
            'slug'          => dirname( $this->basename ),
            'version'       => $remote_version,
            'author'        => $plugin_data['AuthorName'],
            'homepage'      => $plugin_data['PluginURI'],
            'requires'      => '5.0',
            'tested'        => get_bloginfo( 'version' ),
            'requires_php'  => '7.4',
            'download_link' => sprintf(
                'https://github.com/%s/%s/archive/refs/tags/%s.zip',
                $this->github_owner,
                $this->github_repo,
                $release->tag_name
            ),
            'sections'      => array(
                'description' => $plugin_data['Description'],
                'changelog'   => nl2br( esc_html( $release->body ) ),
            ),
            'last_updated'  => $release->published_at,
        );
    }

    /**
     * After WordPress downloads and extracts the zip, rename the folder
     * to match the expected plugin directory name.
     *
     * GitHub zips extract as "repo-name-tag/" but WordPress expects
     * the folder name to match the existing plugin directory.
     */
    public function after_install( $response, $hook_extra, $result ) {
        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
            return $result;
        }

        global $wp_filesystem;

        $plugin_dir    = WP_PLUGIN_DIR . '/' . dirname( $this->basename );
        $installed_dir = $result['destination'];

        $wp_filesystem->move( $installed_dir, $plugin_dir );
        $result['destination'] = $plugin_dir;

        activate_plugin( $this->basename );

        return $result;
    }
}
