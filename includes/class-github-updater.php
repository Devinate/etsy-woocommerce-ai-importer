<?php
/**
 * GitHub Updater
 *
 * Handles automatic updates from GitHub releases.
 *
 * @package Etsy_WooCommerce_AI_Importer
 */

namespace EtsyWooCommerceAIImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub Updater class.
 */
class GitHubUpdater {

	/**
	 * GitHub username.
	 *
	 * @var string
	 */
	private $username;

	/**
	 * GitHub repository name.
	 *
	 * @var string
	 */
	private $repository;

	/**
	 * Plugin file path.
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * Plugin slug.
	 *
	 * @var string
	 */
	private $plugin_slug;

	/**
	 * Current plugin version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * GitHub API URL.
	 *
	 * @var string
	 */
	private $github_api_url;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_file Full path to the plugin file.
	 * @param string $username GitHub username.
	 * @param string $repository GitHub repository name.
	 * @param string $version Current plugin version.
	 */
	public function __construct( $plugin_file, $username, $repository, $version ) {
		$this->plugin_file    = $plugin_file;
		$this->username       = $username;
		$this->repository     = $repository;
		$this->version        = $version;
		$this->plugin_slug    = plugin_basename( $plugin_file );
		$this->github_api_url = "https://api.github.com/repos/{$username}/{$repository}/releases/latest";

		// Hook into WordPress update system.
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_post_install', array( $this, 'after_install' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_directory' ), 10, 3 );
	}

	/**
	 * Check for plugin updates.
	 *
	 * @param object $transient Update transient.
	 * @return object Modified transient.
	 */
	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		// Get latest release from GitHub.
		$release = $this->get_latest_release();

		if ( ! $release ) {
			return $transient;
		}

		// Compare versions.
		if ( version_compare( $this->version, $release->tag_name, '<' ) ) {
			$plugin_data = array(
				'slug'        => dirname( $this->plugin_slug ),
				'new_version' => $release->tag_name,
				'url'         => $release->html_url,
				'package'     => $release->zipball_url,
				'tested'      => get_bloginfo( 'version' ),
			);

			$transient->response[ $this->plugin_slug ] = (object) $plugin_data;
		}

		return $transient;
	}

	/**
	 * Get plugin information for the update details screen.
	 *
	 * @param false|object|array $result The result object or array.
	 * @param string             $action The type of information being requested.
	 * @param object             $args Plugin API arguments.
	 * @return false|object Modified result.
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( dirname( $this->plugin_slug ) !== $args->slug ) {
			return $result;
		}

		$release = $this->get_latest_release();

		if ( ! $release ) {
			return $result;
		}

		// Get plugin data.
		$plugin_data = get_plugin_data( $this->plugin_file );

		$result = (object) array(
			'name'          => $plugin_data['Name'],
			'slug'          => dirname( $this->plugin_slug ),
			'version'       => $release->tag_name,
			'author'        => $plugin_data['Author'],
			'homepage'      => $plugin_data['PluginURI'],
			'requires'      => $plugin_data['RequiresWP'] ?? '6.0',
			'tested'        => get_bloginfo( 'version' ),
			'downloaded'    => 0,
			'last_updated'  => $release->published_at,
			'sections'      => array(
				'description' => $plugin_data['Description'],
				'changelog'   => $this->parse_changelog( $release->body ),
			),
			'download_link' => $release->zipball_url,
		);

		return $result;
	}

	/**
	 * Fix source directory name after download.
	 *
	 * GitHub downloads have a different directory structure than WordPress expects.
	 *
	 * @param string      $source File source location.
	 * @param string      $remote_source Remote file source location.
	 * @param WP_Upgrader $upgrader WP_Upgrader instance.
	 * @return string Modified source location.
	 */
	public function fix_source_directory( $source, $remote_source, $upgrader ) {
		global $wp_filesystem;

		// Check if we're updating this plugin.
		if ( ! isset( $upgrader->skin->plugin ) || $upgrader->skin->plugin !== $this->plugin_slug ) {
			return $source;
		}

		// Rename the source directory to match the plugin slug.
		$plugin_folder = dirname( $this->plugin_slug );
		$new_source    = trailingslashit( $remote_source ) . $plugin_folder;

		if ( $wp_filesystem->move( $source, $new_source ) ) {
			return $new_source;
		}

		return $source;
	}

	/**
	 * Actions to perform after plugin installation.
	 *
	 * @param bool  $response Installation response.
	 * @param array $hook_extra Extra arguments passed to hooked filters.
	 * @param array $result Installation result data.
	 * @return array Modified result.
	 */
	public function after_install( $response, $hook_extra, $result ) {
		global $wp_filesystem;

		// Check if we're updating this plugin.
		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_slug ) {
			return $result;
		}

		// Activate the plugin if it was active before.
		$was_activated = is_plugin_active( $this->plugin_slug );

		if ( $was_activated ) {
			activate_plugin( $this->plugin_slug );
		}

		return $result;
	}

	/**
	 * Get latest release from GitHub API.
	 *
	 * @return object|false Release object or false on failure.
	 */
	private function get_latest_release() {
		// Check cache first.
		$cache_key = 'etsy_importer_github_release';
		$release   = get_transient( $cache_key );

		if ( false !== $release ) {
			return $release;
		}

		// Fetch from GitHub API.
		$response = wp_remote_get(
			$this->github_api_url,
			array(
				'headers' => array(
					'Accept' => 'application/vnd.github.v3+json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body );

		if ( empty( $data ) || ! isset( $data->tag_name ) ) {
			return false;
		}

		// Cache for 6 hours.
		set_transient( $cache_key, $data, 6 * HOUR_IN_SECONDS );

		return $data;
	}

	/**
	 * Parse changelog from release body.
	 *
	 * @param string $body Release body markdown.
	 * @return string HTML changelog.
	 */
	private function parse_changelog( $body ) {
		if ( empty( $body ) ) {
			return '<p>See the <a href="' . esc_url( "https://github.com/{$this->username}/{$this->repository}/releases" ) . '" target="_blank">GitHub releases page</a> for details.</p>';
		}

		// Convert markdown to basic HTML.
		$html = wpautop( $body );
		$html = str_replace( '## ', '<h3>', $html );
		$html = str_replace( "\n", '</h3>', $html );

		return $html;
	}

	/**
	 * Clear the update cache.
	 */
	public function clear_cache() {
		delete_transient( 'etsy_importer_github_release' );
	}
}
