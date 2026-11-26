<?php
// php tests/realtime.php  — mu-plugin flx-realtime.php against a real Redis (REDIS_HOST/PORT/PASSWORD env)
// and the real realtime server's ticket format (the Bun tests cover the server side).
// Minimal WordPress stubs; exits non-zero on the first failure.

define( 'ABSPATH', __DIR__ );
$GLOBALS['actions'] = array();
function add_action( $h, $cb ) { $GLOBALS['actions'][ $h ][] = $cb; }
function apply_filters( $h, $v ) { return $v; }
function wp_json_encode( $v ) { return json_encode( $v, JSON_UNESCAPED_SLASHES ); }
function user_can( $id, $cap ) { return 1 === $id || 'read' === $cap; }
function get_current_user_id() { return 0; }
function do_action( $h ) { foreach ( $GLOBALS['actions'][ $h ] ?? array() as $cb ) { $cb(); } }

putenv( 'WP_REDIS_HOST=' . ( getenv( 'REDIS_HOST' ) ?: '127.0.0.1' ) );
putenv( 'WP_REDIS_PORT=' . ( getenv( 'REDIS_PORT' ) ?: '6379' ) );
putenv( 'WP_REDIS_PASSWORD=' . ( getenv( 'REDIS_PASSWORD' ) ?: '' ) );
putenv( 'FLX_REALTIME_CHANNEL=flx:rt:phptest' );
require dirname( __DIR__ ) . '/config/wordpress/flx-realtime.php';

$fail = static function ( $msg ) { fwrite( STDERR, "FAIL: $msg\n" ); exit( 1 ); };
$ok   = static function ( $c, $msg ) use ( $fail ) { $c ? print( "ok   $msg\n" ) : $fail( $msg ); };

$ok( ! flx_realtime_enabled() && ! flx_realtime_publish( 'x.y' ), 'off without secret' );
putenv( 'FLX_REALTIME_SECRET=short' );
$ok( ! flx_realtime_enabled(), 'off with a short secret' );
$secret = 'php-test-secret-0123456789abcdef0123456789';
putenv( 'FLX_REALTIME_SECRET=' . $secret );
$ok( flx_realtime_enabled(), 'on with secret + Redis host' );

$ok( array( 'PONG' ) === flx_realtime_redis( array( array( 'PING' ) ) ), 'RESP ping (with AUTH)' );
$ok( null === flx_realtime_redis( array( array( 'PING' ) ), '', 0, 'wrong-password' ) || '' === (string) getenv( 'REDIS_PASSWORD' ), 'wrong password fails' );
$ok( null === flx_realtime_redis( array( array( 'PING' ) ), '127.0.0.1', 1 ), 'closed port fails fast' );

// Publish: subscriber in a child process, then queue + shutdown flush, duplicates merged.
$host = getenv( 'WP_REDIS_HOST' ); $port = (int) getenv( 'WP_REDIS_PORT' ); $pw = (string) getenv( 'WP_REDIS_PASSWORD' );
$fp = fsockopen( $host, $port, $e, $es, 1 );
stream_set_timeout( $fp, 2 );
fwrite( $fp, ( '' !== $pw ? "*2\r\n\$4\r\nAUTH\r\n\$" . strlen( $pw ) . "\r\n$pw\r\n" : '' ) . "*2\r\n\$9\r\nSUBSCRIBE\r\n\$14\r\nflx:rt:phptest\r\n" );
if ( '' !== $pw ) { fgets( $fp ); }
for ( $i = 0; $i < 6; $i++ ) { fgets( $fp ); } // *3 subscribe confirmation
$ok( flx_realtime_publish( 'chatbot.inbox', array( 'thread' => 4 ) ), 'queued' );
flx_realtime_publish( 'chatbot.inbox', array( 'thread' => 4 ) );
flx_realtime_publish( 'user.ping', array(), array( 'users' => array( 3, '3', 0 ) ) );
$ok( ! flx_realtime_publish( 'Bad Topic' ), 'bad topic refused' );
$ok( ! flx_realtime_publish( 'big', array( 'x' => str_repeat( 'a', 5000 ) ) ), 'oversized refused' );
do_action( 'shutdown' );
$got = array();
for ( $n = 0; $n < 2; $n++ ) {
	for ( $i = 0; $i < 6; $i++ ) { fgets( $fp ); }
	$got[] = json_decode( trim( fgets( $fp ) ), true );
}
stream_set_timeout( $fp, 0, 300000 );
$ok( false === fgets( $fp ), 'duplicates merged (2 messages, not 3)' );
$ok( 'chatbot.inbox' === $got[0]['t'] && array( 'cap' => 'manage_options' ) === $got[0]['aud'] && array( 'chatbot', 'inbox' ) === $got[0]['inv'] && 4 === $got[0]['d']['thread'], 'envelope: default cap, inv from topic, data' );
$ok( array( 'users' => array( 3 ) ) === $got[1]['aud'], 'users audience deduped' );

// Ticket: format the Bun server verifies.
$t = flx_realtime_ticket( 1 );
[ $v, $body, $sig ] = explode( '.', $t );
$ok( 'v1' === $v && hash_equals( flx_realtime_b64url( hash_hmac( 'sha256', "v1.$body", $secret, true ) ), $sig ), 'ticket signature' );
$p = json_decode( base64_decode( strtr( $body, '-_', '+/' ) ), true );
$ok( 1 === $p['uid'] && array( 'manage_options' ) === $p['caps'] && $p['exp'] > time() && $p['exp'] <= time() + 60 && strlen( $p['jti'] ) === 32, 'ticket payload' );
$ok( '' === flx_realtime_ticket( 0 ), 'no ticket when signed out' );

// Visitors: topic audience and the visitor ticket (no user, no capabilities, only listed topics).
$ok( flx_realtime_publish( 'chatbot.conv.42', array(), array( 'topic' => 'chatbot.conv.42' ) ), 'visitor topic queued' );
$ok( flx_realtime_publish( 'public.donate.total', array( 'total' => 5 ) ), 'public topic queued' );
$ok( ! flx_realtime_publish( 'x.y', array(), array( 'topic' => 'Bad Topic' ) ), 'bad visitor topic refused' );
$q = array_map( 'json_decode', array_values( $GLOBALS['flx_realtime_queue'] ), array_fill( 0, count( $GLOBALS['flx_realtime_queue'] ), true ) );
$ok( array( 'topic' => 'chatbot.conv.42' ) === $q[0]['aud'] && array( 'topic' => 'public.donate.total' ) === $q[1]['aud'], 'visitor audience is the topic, not a capability' );
$GLOBALS['flx_realtime_queue'] = array();
$vt = flx_realtime_visitor_ticket( array( 'chatbot.conv.42', 'Bad Topic' ) );
[ $v, $body, $sig ] = explode( '.', $vt );
$ok( hash_equals( flx_realtime_b64url( hash_hmac( 'sha256', "v1.$body", $secret, true ) ), $sig ), 'visitor ticket signature' );
$p = json_decode( base64_decode( strtr( $body, '-_', '+/' ) ), true );
$ok( 0 === $p['uid'] && array( 'chatbot.conv.42' ) === $p['topics'] && ! isset( $p['caps'] ) && $p['exp'] <= time() + 60, 'visitor ticket payload' );
$ok( '' === flx_realtime_visitor_ticket( array( 'Bad Topic' ) ), 'no visitor ticket without a valid topic' );

// Presence.
flx_realtime_redis( array( array( 'SET', 'flx:rt:phptest:online:8', '1', 'EX', '5' ) ) );
$ok( array( 8 ) === flx_realtime_online( array( 7, 8, 8 ) ), 'online users' );
echo "all passed\n";
