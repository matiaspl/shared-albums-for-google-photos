<?php
/**
 * Core bootstrap for YAPA Google Photo shared albums.
 *
 * Loaded from janzeman-shared-albums-for-google-photos.php (WordPress.org path)
 * or may be used as the primary file if you point the plugin entry there.
 *
 * @package YAGA_Shared_Albums
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'YAGA_CORE_LOADED' ) ) {
	return;
}
define( 'YAGA_CORE_LOADED', true );

if ( ! defined( 'YAGA_PLUGIN_FILE' ) ) {
	define( 'YAGA_PLUGIN_FILE', __FILE__ );
}

define( 'YAGA_VERSION', '1.0.13' );
define( 'YAGA_PLUGIN_DIR', plugin_dir_path( YAGA_PLUGIN_FILE ) );
define( 'YAGA_PLUGIN_URL', plugin_dir_url( YAGA_PLUGIN_FILE ) );

/**
 * Load plugin classes
 */
require_once YAGA_PLUGIN_DIR . 'includes/class-data-provider.php';
require_once YAGA_PLUGIN_DIR . 'includes/class-renderer.php';
require_once YAGA_PLUGIN_DIR . 'includes/class-orchestrator.php';
require_once YAGA_PLUGIN_DIR . 'includes/class-settings-page.php';

/**
 * Initialize the plugin
 */
function yaga_init_plugin() {
	new YAGA_Shared_Albums( YAGA_PLUGIN_FILE );

	if ( is_admin() ) {
		new YAGA_Settings_Page();
	}
}
add_action( 'init', 'yaga_init_plugin' );

/**
 * Activation hook
 */
function yaga_activate() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_yaga_album_%' OR option_name LIKE '_transient_timeout_yaga_album_%' OR option_name LIKE '_transient_jzsa_album_%' OR option_name LIKE '_transient_timeout_jzsa_album_%'" );

	set_transient( 'yaga_activation_redirect', true, 30 );
}
register_activation_hook( YAGA_PLUGIN_FILE, 'yaga_activate' );

/**
 * Redirect to settings page after activation
 */
function yaga_activation_redirect() {
	if ( get_transient( 'yaga_activation_redirect' ) ) {
		delete_transient( 'yaga_activation_redirect' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['activate-multi'] ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=janzeman-shared-albums-for-google-photos' ) );
		exit;
	}
}
add_action( 'admin_init', 'yaga_activation_redirect' );

/**
 * Add Settings link to plugin listing page
 *
 * @param array $links Existing plugin action links.
 * @return array
 */
function yaga_add_settings_link( $links ) {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		admin_url( 'options-general.php?page=janzeman-shared-albums-for-google-photos' ),
		esc_html__( 'Settings', 'janzeman-shared-albums-for-google-photos' )
	);
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( YAGA_PLUGIN_FILE ), 'yaga_add_settings_link' );

/**
 * Deactivation hook
 */
function yaga_deactivate() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_yaga_album_%' OR option_name LIKE '_transient_timeout_yaga_album_%' OR option_name LIKE '_transient_jzsa_album_%' OR option_name LIKE '_transient_timeout_jzsa_album_%'" );
}
register_deactivation_hook( YAGA_PLUGIN_FILE, 'yaga_deactivate' );
