<?php
/**
 * Plugin Name: FluxWP Realtime
 * Description: Live updates for the FluxWP panel and FluxApp on Docker sites: WordPress publishes small "this changed" signals to Redis and the realtime container passes them to signed-in clients over WebSocket. Without it, clients keep polling. Written by the FluxWP Docker worker.
 * Version: 1.1.0
 * Author: FluxWP
 * License: MIT
 *
 * Managed by the worker container (only when FLX_REALTIME_SECRET is set). Deleted otherwise.
 *
 * API for packages (always guard with function_exists, the file exists only on Docker stacks):
 *   flx_realtime_publish( 'chatbot.inbox', array( 'thread' => 42 ), array( 'inv' => array( 'chatbot', 'inbox' ) ) );
 *   flx_realtime_publish( 'user.event', array(), array( 'users' => array( 5 ) ) );
 *   add_filter( 'flx_realtime_topics', fn( $t ) => $t + array( 'chatbot.' => 'flxchatbot_inbox' ) );
 *   flx_realtime_client_config(); // null, or array( 'url' => 'wss://…/flx-rt', 'ticket' => REST URL ) for clients
 *   flx_realtime_ticket( $user_id ); // 60 second ticket, sent as the first WebSocket message {"auth": ticket}
 *   flx_realtime_online( array( 1, 5 ) ); // users looking at an open screen now (skip phone push for them)
 * Visitors (signed out, no WordPress user):
 *   flx_realtime_publish( 'chatbot.conv.42', array(), array( 'topic' => 'chatbot.conv.42' ) ); // only sockets whose ticket lists this topic
 *   flx_realtime_visitor_ticket( array( 'chatbot.conv.42' ) ); // ticket for a visitor you already authenticated yourself (e.g. by chat token)
 *   add_filter( 'flx_realtime_public_topics', fn( $t ) => array_merge( $t, array( 'public.donate.total' ) ) ); // anyone may listen
 *   flx_realtime_publish( 'public.donate.total', array( 'total' => 1250 ) ); // topics starting with "public." go to the public audience; data must be public
 * Signals carry no content (no message text, names or amounts): clients reload data through WordPress.
 */

defined( 'ABSPATH' ) || exit;

const FLX_REALTIME_VERSION    = '1.1.0';
const FLX_REALTIME_TICKET_TTL = 60;
const FLX_REALTIME_MAX_BYTES  = 4096;

function flx_realtime_env( string $key, string $fallback = '' ): string {
	$v = getenv( $key );
	return false === $v || '' === trim( (string) $v ) ? $fallback : trim( (string) $v );
}

function flx_realtime_secret(): string {
	$s = flx_realtime_env( 'FLX_REALTIME_SECRET' );
	return strlen( $s ) >= 32 ? $s : '';
}

/** Redis channel shared with the realtime container (RT_CHANNEL there). */
function flx_realtime_channel(): string {
	return flx_realtime_env( 'FLX_REALTIME_CHANNEL', 'flx:rt' );
}

/** Publishing works whenever Redis and the secret are there, even if no browser can reach the server. */
function flx_realtime_enabled(): bool {
	return '' !== flx_realtime_secret() && '' !== flx_realtime_redis_host();
}

function flx_realtime_redis_host(): string {
	if ( defined( 'WP_REDIS_HOST' ) && is_string( WP_REDIS_HOST ) && '' !== WP_REDIS_HOST ) {
		return WP_REDIS_HOST;
	}
	return flx_realtime_env( 'WP_REDIS_HOST' );
}

/**
 * Topic prefix => capability needed to receive it. Longest prefix wins; anything not listed needs manage_options.
 * Packages add their own: add_filter( 'flx_realtime_topics', fn( $t ) => $t + array( 'tasks.' => 'edit_posts' ) ).
 */
function flx_realtime_topics(): array {
	$topics = array( 'system.' => 'manage_options' );
	$topics = (array) apply_filters( 'flx_realtime_topics', $topics );
	$out    = array();
	foreach ( $topics as $prefix => $cap ) {
		if ( is_string( $prefix ) && '' !== $prefix && is_string( $cap ) && preg_match( '/^[a-z0-9_]{1,64}$/', $cap ) ) {
			$out[ $prefix ] = $cap;
		}
	}
	return $out;
}

function flx_realtime_topic_cap( string $topic ): string {
	$best = '';
	$cap  = 'manage_options';
	foreach ( flx_realtime_topics() as $prefix => $c ) {
		if ( 0 === strpos( $topic, $prefix ) && strlen( $prefix ) > strlen( $best ) ) {
			$best = $prefix;
			$cap  = $c;
		}
	}
	return $cap;
}

/**
 * Queues a signal; everything queued in one request goes to Redis once, at shutdown (duplicates merged).
 *
 * @param string $topic Dotted name, e.g. "chatbot.inbox". [a-z0-9_.:-], up to 80 characters.
 * @param array  $data  Small, non-sensitive hints (ids, counts). Never message text, names or amounts.
 * @param array  $args  users: int[] (only these users), cap: string (overrides the topic's capability),
 *                      topic: string (visitor topic: only sockets whose visitor ticket lists it; "public.*" topics default to it),
 *                      inv: string[] query key prefix for clients to reload (default: topic split on dots).
 */
function flx_realtime_publish( string $topic, array $data = array(), array $args = array() ): bool {
	if ( ! flx_realtime_enabled() || ! preg_match( '/^[a-z0-9_.:-]{1,80}$/', $topic ) ) {
		return false;
	}
	$users = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $args['users'] ?? array() ) ) ) ) );
	$vt    = is_string( $args['topic'] ?? null ) ? $args['topic'] : ( 0 === strpos( $topic, 'public.' ) ? $topic : '' );
	if ( '' !== $vt && ! preg_match( '/^[a-z0-9_.:-]{1,80}$/', $vt ) ) {
		return false;
	}
	if ( $users ) {
		$aud = array( 'users' => $users );
	} elseif ( '' !== $vt ) {
		$aud = array( 'topic' => $vt ); // Visitors who hold a ticket for this topic. Staff do not get it through their capability.
	} else {
		$aud = array( 'cap' => is_string( $args['cap'] ?? null ) ? $args['cap'] : flx_realtime_topic_cap( $topic ) );
	}
	$inv   = isset( $args['inv'] ) ? array_values( array_map( 'strval', (array) $args['inv'] ) ) : explode( '.', $topic );
	$env   = array(
		'v'   => 1,
		't'   => $topic,
		'at'  => time(),
		'inv' => $inv,
		'aud' => $aud,
	);
	if ( $data ) {
		$env['d'] = $data;
	}
	$json = wp_json_encode( $env );
	if ( ! is_string( $json ) || strlen( $json ) > FLX_REALTIME_MAX_BYTES ) {
		return false;
	}
	$GLOBALS['flx_realtime_queue'][ md5( $json ) ] = $json;
	if ( empty( $GLOBALS['flx_realtime_hooked'] ) ) {
		$GLOBALS['flx_realtime_hooked'] = true;
		add_action( 'shutdown', 'flx_realtime_flush', 1 );
	}
	return true;
}

/** Sends the queue now (also called at shutdown). Safe to call from wp-cli and cron. */
function flx_realtime_flush(): bool {
	$queue = array_values( (array) ( $GLOBALS['flx_realtime_queue'] ?? array() ) );
	$GLOBALS['flx_realtime_queue'] = array();
	if ( ! $queue ) {
		return true;
	}
	$ok = null !== flx_realtime_redis( array_map( static fn( $m ) => array( 'PUBLISH', flx_realtime_channel(), $m ), $queue ) );
	if ( ! $ok && empty( $GLOBALS['flx_realtime_logged'] ) ) {
		$GLOBALS['flx_realtime_logged'] = true;
		error_log( 'FluxWP Realtime: could not publish to Redis; clients fall back to polling' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	}
	return $ok;
}

/**
 * Minimal Redis client: optional AUTH + pipelined commands over RESP, half a second budget. No phpredis needed.
 * Returns one reply per command (string, int, null, or array for MGET), or null if anything failed.
 *
 * @param array[] $commands e.g. array( array( 'PUBLISH', 'flx:rt', '{...}' ) )
 */
function flx_realtime_redis( array $commands, string $host = '', int $port = 0, ?string $password = null ): ?array {
	$host     = '' !== $host ? $host : flx_realtime_redis_host();
	$port     = $port > 0 ? $port : ( defined( 'WP_REDIS_PORT' ) ? (int) WP_REDIS_PORT : (int) flx_realtime_env( 'WP_REDIS_PORT', '6379' ) );
	$password = null !== $password ? $password : ( defined( 'WP_REDIS_PASSWORD' ) && is_string( WP_REDIS_PASSWORD ) ? WP_REDIS_PASSWORD : flx_realtime_env( 'WP_REDIS_PASSWORD' ) );
	if ( '' === $host || ! $commands ) {
		return null;
	}
	$errno  = 0;
	$errstr = '';
	$fp     = @fsockopen( $host, $port, $errno, $errstr, 0.5 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( ! $fp ) {
		return null;
	}
	stream_set_timeout( $fp, 0, 500000 );
	if ( '' !== $password ) {
		array_unshift( $commands, array( 'AUTH', $password ) );
	}
	$buf = '';
	foreach ( $commands as $parts ) {
		$buf .= '*' . count( $parts ) . "\r\n";
		foreach ( $parts as $p ) {
			$buf .= '$' . strlen( (string) $p ) . "\r\n" . $p . "\r\n";
		}
	}
	$read = static function () use ( $fp, &$read ) {
		$line = fgets( $fp );
		if ( false === $line || strlen( $line ) < 3 ) {
			throw new RuntimeException( 'read' );
		}
		$type = $line[0];
		$rest = substr( $line, 1, -2 );
		switch ( $type ) {
			case '+':
				return $rest;
			case ':':
				return (int) $rest;
			case '-':
				throw new RuntimeException( $rest );
			case '$':
				if ( (int) $rest < 0 ) {
					return null;
				}
				$data = '';
				$want = (int) $rest + 2;
				while ( strlen( $data ) < $want ) {
					$chunk = fread( $fp, $want - strlen( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					if ( false === $chunk || '' === $chunk ) {
						throw new RuntimeException( 'read' );
					}
					$data .= $chunk;
				}
				return substr( $data, 0, -2 );
			case '*':
				$out = array();
				for ( $i = 0, $n = (int) $rest; $i < $n; $i++ ) {
					$out[] = $read();
				}
				return $out;
		}
		throw new RuntimeException( 'protocol' );
	};
	$replies = null;
	try {
		if ( false === fwrite( $fp, $buf ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			throw new RuntimeException( 'write' );
		}
		$replies = array();
		foreach ( $commands as $unused ) {
			$replies[] = $read();
		}
		if ( '' !== $password ) {
			array_shift( $replies );
		}
	} catch ( Throwable $e ) {
		$replies = null;
	}
	fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	return $replies;
}

/**
 * Which of these users have a realtime socket open right now (the realtime server keeps a 90 s presence key).
 * flx_notify() uses it to skip phone pushes for messages an open screen already showed.
 *
 * @param int[] $user_ids
 * @return int[]
 */
function flx_realtime_online( array $user_ids ): array {
	$user_ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
	if ( ! $user_ids || ! flx_realtime_enabled() ) {
		return array();
	}
	$keys    = array_map( static fn( $id ) => flx_realtime_channel() . ':online:' . $id, $user_ids );
	$replies = flx_realtime_redis( array( array_merge( array( 'MGET' ), $keys ) ) );
	if ( ! is_array( $replies ) || ! is_array( $replies[0] ?? null ) ) {
		return array();
	}
	$out = array();
	foreach ( $replies[0] as $i => $v ) {
		if ( null !== $v ) {
			$out[] = $user_ids[ $i ];
		}
	}
	return $out;
}

function flx_realtime_b64url( string $s ): string {
	return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
}

/** Capabilities a user's socket listens to: the ones the topic map needs that this user has. */
function flx_realtime_user_caps( int $user_id ): array {
	$caps = array_unique( array_merge( array( 'manage_options' ), array_values( flx_realtime_topics() ) ) );
	return array_values( array_filter( $caps, static fn( $c ) => user_can( $user_id, $c ) ) );
}

/** 60 second, single-use ticket for the WebSocket's first message. '' if realtime is off or nobody is signed in. */
function flx_realtime_ticket( int $user_id = 0 ): string {
	$user_id = $user_id ? $user_id : get_current_user_id();
	$secret  = flx_realtime_secret();
	if ( ! $user_id || '' === $secret ) {
		return '';
	}
	$body = flx_realtime_b64url(
		(string) wp_json_encode(
			array(
				'uid'  => $user_id,
				'caps' => flx_realtime_user_caps( $user_id ),
				'exp'  => time() + FLX_REALTIME_TICKET_TTL,
				'jti'  => bin2hex( random_bytes( 16 ) ),
			)
		)
	);
	return 'v1.' . $body . '.' . flx_realtime_b64url( hash_hmac( 'sha256', 'v1.' . $body, $secret, true ) );
}

/**
 * Ticket for a visitor socket (no WordPress user): it only lets the socket hear the listed topics (up to 5), never
 * staff signals. Authenticate the visitor yourself first (chat token, order key ...) and list only topics that
 * belong to them. '' if realtime is off or no valid topic.
 *
 * @param string[] $topics e.g. array( 'chatbot.conv.42' )
 */
function flx_realtime_visitor_ticket( array $topics ): string {
	$secret = flx_realtime_secret();
	$topics = array_values( array_filter( array_slice( array_map( 'strval', $topics ), 0, 5 ), static fn( $t ) => (bool) preg_match( '/^[a-z0-9_.:-]{1,80}$/', $t ) ) );
	if ( '' === $secret || ! $topics ) {
		return '';
	}
	$body = flx_realtime_b64url(
		(string) wp_json_encode(
			array(
				'uid'    => 0,
				'topics' => $topics,
				'exp'    => time() + FLX_REALTIME_TICKET_TTL,
				'jti'    => bin2hex( random_bytes( 16 ) ),
			)
		)
	);
	return 'v1.' . $body . '.' . flx_realtime_b64url( hash_hmac( 'sha256', 'v1.' . $body, $secret, true ) );
}

/** Topics anyone may listen to without signing in. Each must start with "public."; payloads must be fit for the public. */
function flx_realtime_public_topics(): array {
	$out = array();
	foreach ( (array) apply_filters( 'flx_realtime_public_topics', array() ) as $t ) {
		if ( is_string( $t ) && 0 === strpos( $t, 'public.' ) && preg_match( '/^[a-z0-9_.:-]{1,80}$/', $t ) ) {
			$out[] = $t;
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * For visitor widgets: null unless realtime is configured, reachable from outside and healthy (no ticket URL, because
 * each feature issues its own ticket after its own check).
 */
function flx_realtime_visitor_config(): ?array {
	$c = flx_realtime_client_config();
	return $c ? array( 'url' => $c['url'], 'ttl' => $c['ttl'] ) : null;
}

/** Internal health of the realtime container, cached (60 s when up, 300 s when down). */
function flx_realtime_health( bool $fresh = false ): array {
	$cached = $fresh ? false : get_transient( 'flx_realtime_health' );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$url = flx_realtime_env( 'FLX_REALTIME_INTERNAL', 'http://realtime/healthz' );
	$res = wp_remote_get( $url, array( 'timeout' => 1, 'redirection' => 0 ) );
	$h   = is_wp_error( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
	$h   = is_array( $h ) ? $h : array();
	$h['ok'] = ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) && ! empty( $h['enabled'] ) && ! empty( $h['redis'] );
	set_transient( 'flx_realtime_health', $h, $h['ok'] ? 60 : 300 );
	return $h;
}

/** Public WebSocket address from FLX_REALTIME_URL (a path like /flx-rt, or a full ws(s):// URL). '' = no browser access. */
function flx_realtime_public_url(): string {
	$u = flx_realtime_env( 'FLX_REALTIME_URL' );
	if ( '' === $u ) {
		return '';
	}
	if ( preg_match( '#^wss?://#', $u ) ) {
		return $u;
	}
	if ( '/' !== $u[0] ) {
		return '';
	}
	$home = wp_parse_url( home_url( '/' ) );
	if ( empty( $home['host'] ) ) {
		return '';
	}
	$scheme = ( 'https' === ( $home['scheme'] ?? '' ) || is_ssl() ) ? 'wss' : 'ws';
	return $scheme . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . $u;
}

/**
 * For clients (flx-app transport, panel): null unless realtime is configured, reachable from outside and healthy.
 * Filter flx_realtime_client_config can turn it off (return null) or change it.
 */
function flx_realtime_client_config(): ?array {
	if ( ! flx_realtime_enabled() ) {
		return null;
	}
	$url = flx_realtime_public_url();
	if ( '' === $url || empty( flx_realtime_health()['ok'] ) ) {
		return null;
	}
	return apply_filters(
		'flx_realtime_client_config',
		array(
			'url'    => $url,
			'ticket' => rest_url( 'flx-realtime/v1/ticket' ),
			'ttl'    => FLX_REALTIME_TICKET_TTL,
		)
	);
}

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'flx-realtime/v1',
			'/ticket',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn() => is_user_logged_in(),
				'callback'            => static function () {
					$ticket = flx_realtime_ticket();
					if ( '' === $ticket ) {
						return new WP_Error( 'flx_realtime_off', 'Realtime is not available on this site.', array( 'status' => 503 ) );
					}
					$res = rest_ensure_response(
						array(
							'ticket' => $ticket,
							'url'    => flx_realtime_public_url(),
							'ttl'    => FLX_REALTIME_TICKET_TTL,
						)
					);
					$res->header( 'Cache-Control', 'no-store' );
					return $res;
				},
			)
		);
	}
);

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'flx-realtime/v1',
			'/public-ticket',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $r ) {
					$want   = array_values( array_filter( array_map( 'strval', (array) $r->get_param( 'topics' ) ) ) );
					$public = flx_realtime_public_topics();
					if ( ! $want || array_diff( $want, $public ) ) {
						return new WP_Error( 'flx_realtime_topic', 'Unknown topic.', array( 'status' => 400 ) );
					}
					// Anonymous endpoint: a small per-address budget (the realtime server rate limits sockets too).
					$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
					$key = 'flx_rt_pub_' . md5( $ip );
					$n   = (int) get_transient( $key );
					if ( $n >= 30 ) {
						return new WP_Error( 'flx_realtime_rate', 'Too many requests.', array( 'status' => 429 ) );
					}
					set_transient( $key, $n + 1, MINUTE_IN_SECONDS );
					$ticket = flx_realtime_visitor_ticket( $want );
					$cfg    = flx_realtime_visitor_config();
					if ( '' === $ticket || ! $cfg ) {
						return new WP_Error( 'flx_realtime_off', 'Realtime is not available on this site.', array( 'status' => 503 ) );
					}
					$res = rest_ensure_response( array( 'ticket' => $ticket, 'url' => $cfg['url'], 'ttl' => $cfg['ttl'] ) );
					$res->header( 'Cache-Control', 'no-store' );
					return $res;
				},
			)
		);
	}
);

/** Signed out, role changed, password reset, deleted: close that user's open sockets at once. */
function flx_realtime_revoke( $user_id ): void {
	$user_id = (int) ( is_object( $user_id ) ? ( $user_id->ID ?? 0 ) : $user_id );
	if ( $user_id > 0 ) {
		flx_realtime_publish( 'auth.revoke', array(), array( 'users' => array( $user_id ) ) );
	}
}
add_action( 'wp_logout', 'flx_realtime_revoke' );
add_action( 'set_user_role', 'flx_realtime_revoke' );
add_action( 'add_user_role', 'flx_realtime_revoke' );
add_action( 'remove_user_role', 'flx_realtime_revoke' );
add_action( 'after_password_reset', 'flx_realtime_revoke' );
add_action( 'deleted_user', 'flx_realtime_revoke' );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * FluxWP Realtime status and end-to-end test.
	 *
	 * ## EXAMPLES
	 *     wp flx realtime status
	 *     wp flx realtime test
	 */
	WP_CLI::add_command(
		'flx realtime',
		static function ( $args ) {
			$what = $args[0] ?? 'status';
			if ( ! flx_realtime_enabled() ) {
				WP_CLI::error( 'Realtime is off: FLX_REALTIME_SECRET (32+ characters) or Redis is missing.' );
			}
			$h = flx_realtime_health( true );
			if ( 'status' === $what ) {
				WP_CLI::line( 'Public URL: ' . ( flx_realtime_public_url() ? flx_realtime_public_url() : '(none, clients poll)' ) );
				WP_CLI::line( 'Server: ' . wp_json_encode( $h ) );
				$h['ok'] ? WP_CLI::success( 'Realtime server is healthy.' ) : WP_CLI::error( 'Realtime server is not healthy.' );
				return;
			}
			if ( 'test' !== $what ) {
				WP_CLI::error( 'Use: wp flx realtime status|test' );
			}
			if ( empty( $h['ok'] ) ) {
				WP_CLI::error( 'Realtime server is not healthy: ' . wp_json_encode( $h ) );
			}
			$before = (int) ( $h['received'] ?? 0 );
			flx_realtime_publish( 'system.test', array( 'n' => wp_rand() ) );
			if ( ! flx_realtime_flush() ) {
				WP_CLI::error( 'Publishing to Redis failed.' );
			}
			for ( $i = 0; $i < 20; $i++ ) {
				usleep( 100000 );
				$after = (int) ( flx_realtime_health( true )['received'] ?? 0 );
				if ( $after > $before ) {
					WP_CLI::success( 'WordPress -> Redis -> realtime works.' );
					return;
				}
			}
			WP_CLI::error( 'The realtime server did not receive the test signal (channel ' . flx_realtime_channel() . ').' );
		}
	);
}
