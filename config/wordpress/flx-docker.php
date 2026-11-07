<?php
/**
 * Plugin Name: FluxWP Docker
 * Description: Activates Flux Setup right after the WordPress install screen and opens its setup wizard on the first wp-admin visit. Written by the FluxWP docker-wordpress worker.
 * Version: 1.0.0
 * Author: FluxWP
 * License: MIT
 *
 * Managed by the worker container (FLUXWP_SETUP=true). Deleted when FLUXWP_SETUP is false.
 */

defined( 'ABSPATH' ) || exit;

const FLX_DOCKER_SETUP_PLUGIN = 'flx-setup/flx-setup.php';
const FLX_DOCKER_WIZARD_FLAG  = 'flx_docker_open_wizard';

/**
 * Activates Flux Setup, ignoring anything that goes wrong (the worker retries with wp-cli).
 */
function flx_docker_activate_setup(): void {
	if ( ! is_file( WP_PLUGIN_DIR . '/' . FLX_DOCKER_SETUP_PLUGIN ) ) {
		return;
	}
	if ( ! function_exists( 'activate_plugin' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	try {
		if ( ! is_plugin_active( FLX_DOCKER_SETUP_PLUGIN ) ) {
			activate_plugin( FLX_DOCKER_SETUP_PLUGIN ); // Not silent: Flux Setup's activation hook marks the wizard as pending.
		}
	} catch ( Throwable $e ) {
		error_log( 'FluxWP Docker: Flux Setup activation failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		return;
	}
	update_option( FLX_DOCKER_WIZARD_FLAG, 1, false );
}

// The browser install screen (wp-admin/install.php) fires this after the admin user is created.
add_action( 'wp_install', 'flx_docker_activate_setup', 99 );

/**
 * First wp-admin visit after the install: hand over to Flux Setup's own one-time redirect.
 * The plugin's transient lives only 60 seconds, which the "Log in" step can outlast.
 */
add_action(
	'admin_init',
	static function () {
		if ( ! get_option( FLX_DOCKER_WIZARD_FLAG ) ) {
			return;
		}
		if ( wp_doing_ajax() || ! current_user_can( 'install_plugins' ) ) {
			return;
		}
		delete_option( FLX_DOCKER_WIZARD_FLAG );
		if ( 'pending' === get_option( 'flxk_onboarding' ) ) {
			set_transient( 'flxk_onboarding_redirect', 1, 60 );
		}
	},
	1
);
