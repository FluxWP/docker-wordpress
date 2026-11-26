<?php
/**
 * FluxWP Docker - extra wp-config settings.
 *
 * Installed in the image as /usr/local/etc/flux/wp-config-extra.php (outside the web root) and
 * loaded from wp-config.php through WORDPRESS_CONFIG_EXTRA. Driven by the
 * container environment, so the same file works for every variant.
 */

if ( ! function_exists( 'flx_docker_env' ) ) {
	function flx_docker_env( $name, $default = null ) {
		$value = getenv( $name );
		return ( false === $value || '' === $value ) ? $default : $value;
	}
}

if ( ! function_exists( 'flx_docker_bool' ) ) {
	function flx_docker_bool( $name, $default ) {
		$value = flx_docker_env( $name );
		return null === $value ? $default : in_array( strtolower( $value ), array( '1', 'true', 'yes', 'on' ), true );
	}
}

defined( 'WP_ENVIRONMENT_TYPE' ) || define( 'WP_ENVIRONMENT_TYPE', flx_docker_env( 'WP_ENVIRONMENT_TYPE', 'production' ) );

// WP-Cron runs in the "worker" container every minute instead of on page loads.
if ( 'worker' === flx_docker_env( 'WP_CRON_MODE', 'worker' ) ) {
	defined( 'DISABLE_WP_CRON' ) || define( 'DISABLE_WP_CRON', true );
}

// Memory: match the PHP limit so WordPress does not lower it.
defined( 'WP_MEMORY_LIMIT' ) || define( 'WP_MEMORY_LIMIT', flx_docker_env( 'PHP_MEMORY_LIMIT', '256M' ) );
defined( 'WP_MAX_MEMORY_LIMIT' ) || define( 'WP_MAX_MEMORY_LIMIT', flx_docker_env( 'PHP_MEMORY_LIMIT', '256M' ) );

// Hardening: no theme/plugin file editor in wp-admin.
defined( 'DISALLOW_FILE_EDIT' ) || define( 'DISALLOW_FILE_EDIT', flx_docker_bool( 'WP_DISALLOW_FILE_EDIT', true ) );

// Files are owned by the web server user, so updates can write directly.
defined( 'FS_METHOD' ) || define( 'FS_METHOD', 'direct' );

defined( 'WP_POST_REVISIONS' ) || define( 'WP_POST_REVISIONS', (int) flx_docker_env( 'WP_POST_REVISIONS', 20 ) );
defined( 'WP_AUTO_UPDATE_CORE' ) || define( 'WP_AUTO_UPDATE_CORE', flx_docker_env( 'WP_AUTO_UPDATE_CORE', 'minor' ) );

// Redis object cache (Redis Object Cache plugin), only when a Redis host is set.
if ( flx_docker_env( 'WP_REDIS_HOST' ) ) {
	defined( 'WP_REDIS_HOST' ) || define( 'WP_REDIS_HOST', flx_docker_env( 'WP_REDIS_HOST' ) );
	defined( 'WP_REDIS_PORT' ) || define( 'WP_REDIS_PORT', (int) flx_docker_env( 'WP_REDIS_PORT', 6379 ) );
	if ( flx_docker_env( 'WP_REDIS_PASSWORD' ) ) {
		defined( 'WP_REDIS_PASSWORD' ) || define( 'WP_REDIS_PASSWORD', flx_docker_env( 'WP_REDIS_PASSWORD' ) );
	}
	defined( 'WP_REDIS_DATABASE' ) || define( 'WP_REDIS_DATABASE', (int) flx_docker_env( 'WP_REDIS_DATABASE', 0 ) );
	defined( 'WP_REDIS_PREFIX' ) || define( 'WP_REDIS_PREFIX', flx_docker_env( 'WP_REDIS_PREFIX', flx_docker_env( 'WORDPRESS_DB_NAME', 'wp' ) . ':' ) );
	defined( 'WP_REDIS_TIMEOUT' ) || define( 'WP_REDIS_TIMEOUT', 1 );
	defined( 'WP_REDIS_READ_TIMEOUT' ) || define( 'WP_REDIS_READ_TIMEOUT', 1 );
	defined( 'WP_REDIS_MAXTTL' ) || define( 'WP_REDIS_MAXTTL', (int) flx_docker_env( 'WP_REDIS_MAXTTL', 86400 ) );
}
