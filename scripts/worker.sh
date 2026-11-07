#!/bin/sh
# FluxWP docker-wordpress - worker container.
#
# 1. Waits for the WordPress files and the database.
# 2. One-time bootstrap (idempotent, safe on every restart):
#      - installs WordPress when WP_URL + WP_ADMIN_EMAIL (or WP_AUTO_INSTALL=true) are set,
#        so the install screen is never public,
#      - installs the site language (WP_LOCALE),
#      - enables the Redis object cache when WP_REDIS_HOST is set,
#      - Flux Setup (FLUXWP_SETUP, default true): downloaded before the install screen,
#        activated on install, its wizard opens on the first wp-admin visit.
# 3. Runs due WP-Cron events every WP_CRON_INTERVAL seconds (default 60).
#
# Installed in the image as /usr/local/bin/flx-worker; runs in the wordpress:cli image as www-data (33).

set -u

ROOT=/var/www/html
INTERVAL="${WP_CRON_INTERVAL:-60}"
FLAG=flx_docker_bootstrap

log() { echo "[worker] $(date -u '+%Y-%m-%d %H:%M:%S') $*"; }
wp() { command wp --path="$ROOT" "$@"; }
truthy() { case "$(echo "${1:-}" | tr '[:upper:]' '[:lower:]')" in 1|true|yes|on) return 0 ;; *) return 1 ;; esac; }

wait_files() {
	n=0
	until [ -f "$ROOT/wp-config.php" ] && [ -f "$ROOT/wp-settings.php" ]; do
		[ $((n % 10)) -eq 0 ] && log "waiting for WordPress files"
		n=$((n + 1)); sleep 3
	done
}

# Plain PHP mysqli check: no mysql client, no TLS/auth-plugin surprises.
db_ready() {
	php -r '
		mysqli_report(MYSQLI_REPORT_OFF);
		$h = getenv("WORDPRESS_DB_HOST") ?: "localhost";
		$p = 3306;
		if (preg_match("/^(.+):(\d+)$/", $h, $m)) { $h = $m[1]; $p = (int) $m[2]; }
		$c = @mysqli_connect($h, getenv("WORDPRESS_DB_USER"), getenv("WORDPRESS_DB_PASSWORD"), getenv("WORDPRESS_DB_NAME"), $p);
		exit($c ? 0 : 1);
	' >/dev/null 2>&1
}

wait_db() {
	n=0
	until db_ready; do
		[ $((n % 10)) -eq 0 ] && log "waiting for database"
		n=$((n + 1)); sleep 3
	done
}

install_core() {
	if wp core is-installed >/dev/null 2>&1; then
		return 0
	fi
	# WP_AUTO_INSTALL=true (default on Coolify): install without any input,
	# admin e-mail falls back to admin@<site host>.
	if [ -z "${WP_ADMIN_EMAIL:-}" ] && [ -n "${WP_URL:-}" ] && truthy "${WP_AUTO_INSTALL:-false}"; then
		host="$(echo "$WP_URL" | sed -E 's#^[a-zA-Z]+://##; s#[/:].*$##')"
		WP_ADMIN_EMAIL="admin@${host:-example.com}"
	fi
	if [ -z "${WP_URL:-}" ] || [ -z "${WP_ADMIN_EMAIL:-}" ]; then
		if [ -z "${FLX_WARNED:-}" ]; then
			log "WordPress is not installed yet: open ${WP_URL:-your site} and finish the install screen,"
			log "or set WP_ADMIN_EMAIL (+ WP_ADMIN_USER, WP_ADMIN_PASSWORD) to install automatically."
			FLX_WARNED=1
		fi
		return 1
	fi
	log "installing WordPress at $WP_URL"
	set -- --url="$WP_URL" --title="${WP_TITLE:-WordPress}" \
		--admin_user="${WP_ADMIN_USER:-admin}" --admin_email="$WP_ADMIN_EMAIL" --skip-email
	if [ -n "${WP_ADMIN_PASSWORD:-}" ]; then
		set -- "$@" --admin_password="$WP_ADMIN_PASSWORD"
	else
		log "WP_ADMIN_PASSWORD is empty: a random password is printed below, save it."
	fi
	wp core install "$@" || return 1
	log "WordPress installed: $WP_URL/wp-admin  user: ${WP_ADMIN_USER:-admin}  admin e-mail: $WP_ADMIN_EMAIL"
	[ -n "${WP_ADMIN_PASSWORD:-}" ] && log "password: the WP_ADMIN_PASSWORD variable"
	wp rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || true
}

setup_locale() {
	[ -n "${WP_LOCALE:-}" ] && [ "$WP_LOCALE" != "en_US" ] || return 0
	log "installing language $WP_LOCALE"
	wp language core install "$WP_LOCALE" --activate >/dev/null 2>&1 || log "language $WP_LOCALE could not be installed"
	wp option update timezone_string "${WP_TIMEZONE:-UTC}" >/dev/null 2>&1 || true
}

setup_redis() {
	[ -n "${WP_REDIS_HOST:-}" ] || return 0
	if [ -f "$ROOT/wp-content/object-cache.php" ]; then
		return 0
	fi
	log "enabling Redis object cache"
	if ! wp plugin is-installed redis-cache >/dev/null 2>&1; then
		wp plugin install redis-cache --activate >/dev/null || { log "redis-cache could not be installed"; return 1; }
	else
		wp plugin activate redis-cache >/dev/null 2>&1 || true
	fi
	wp redis enable || return 1
}

# Latest Flux Setup zip: FluxWP update server first, GitHub release as fallback.
fluxwp_setup_url() {
	if [ -n "${FLUXWP_SETUP_URL:-}" ]; then
		echo "$FLUXWP_SETUP_URL"
		return 0
	fi
	php -r '
		$ctx = stream_context_create(array("http" => array("method" => "HEAD", "timeout" => 10, "ignore_errors" => true)));
		$u = "https://updater.fluxwp.com/v1/download/flx-setup/latest.zip";
		$h = @get_headers($u, false, $ctx);
		if ($h && preg_match("#\s(200|30\d)\s#", $h[0])) { echo $u; exit(0); }
		$ctx = stream_context_create(array("http" => array("timeout" => 15, "header" => "User-Agent: fluxwp-docker-wordpress\r\nAccept: application/vnd.github+json\r\n")));
		$j = json_decode((string) @file_get_contents("https://api.github.com/repos/FluxWP/FluxWP/releases?per_page=100", false, $ctx), true);
		foreach ((array) $j as $r) {
			if (empty($r["draft"]) && empty($r["prerelease"]) && 0 === strpos((string) $r["tag_name"], "flx-setup-v")) {
				foreach ($r["assets"] as $a) { if ("flx-setup.zip" === $a["name"]) { echo $a["browser_download_url"]; exit(0); } }
			}
		}
		exit(1);
	'
}

MU_SRC=/usr/local/etc/flux/flx-docker.php
MU_DST="$ROOT/wp-content/mu-plugins/flx-docker.php"

# Before the install screen is used: Flux Setup files in place + the mu-plugin that
# activates it on install and opens its wizard on the first wp-admin visit.
prepare_fluxwp() {
	if ! truthy "${FLUXWP_SETUP:-true}"; then
		[ -f "$MU_DST" ] && rm -f "$MU_DST"
		return 0
	fi
	if [ -f "$MU_SRC" ] && ! cmp -s "$MU_SRC" "$MU_DST" 2>/dev/null; then
		mkdir -p "$ROOT/wp-content/mu-plugins" && cp "$MU_SRC" "$MU_DST" || log "mu-plugin could not be written"
	fi
	[ -f "$ROOT/wp-content/plugins/flx-setup/flx-setup.php" ] && return 0
	url="$(fluxwp_setup_url)" || { log "Flux Setup download address not found"; return 1; }
	log "downloading Flux Setup from $url"
	FLX_URL="$url" FLX_DIR="$ROOT/wp-content/plugins" php -r '
		$zip = tempnam(sys_get_temp_dir(), "flx");
		$ctx = stream_context_create(array("http" => array("timeout" => 60, "follow_location" => 1, "header" => "User-Agent: fluxwp-docker-wordpress\r\n")));
		$ok = @copy(getenv("FLX_URL"), $zip, $ctx) && filesize($zip) > 1000;
		$z = new ZipArchive();
		$ok = $ok && true === $z->open($zip) && false !== $z->locateName("flx-setup/flx-setup.php");
		$ok = $ok && $z->extractTo(getenv("FLX_DIR"));
		@unlink($zip);
		exit($ok ? 0 : 1);
	' || { log "Flux Setup download failed, will retry"; return 1; }
}

# After WordPress is installed: activate (the mu-plugin usually did it already).
setup_fluxwp() {
	truthy "${FLUXWP_SETUP:-true}" || return 0
	prepare_fluxwp || return 1
	if ! wp plugin is-active flx-setup >/dev/null 2>&1; then
		wp plugin activate flx-setup || return 1
		# Activated from the CLI: open the wizard on the first wp-admin visit.
		if [ "$(wp option get flxk_onboarding 2>/dev/null)" = "pending" ]; then
			wp option update flx_docker_open_wizard 1 --autoload=no >/dev/null
		fi
	fi
}

bootstrap() {
	install_core || return 1
	rc=0
	setup_redis || { log "Redis setup failed, will retry"; rc=1; }
	# Language and Flux Setup only once, so a later choice in wp-admin is kept.
	if [ "$(wp option get "$FLAG" 2>/dev/null)" != "done" ]; then
		setup_locale
		if setup_fluxwp; then
			wp option update "$FLAG" done --autoload=no >/dev/null
		else
			log "Flux Setup install failed, will retry"
			rc=1
		fi
	fi
	return $rc
}

run_cron() {
	[ "${WP_CRON_MODE:-worker}" = "worker" ] || return 0
	wp core is-installed >/dev/null 2>&1 || return 0
	wp cron event run --due-now --quiet 2>&1 | grep -v '^$' || true
}

wait_files
wait_db
log "ready (cron every ${INTERVAL}s)"

# Bootstrap until it succeeds; give up after 30 tries (~30 min) and only run cron.
tries=0
while :; do
	if [ "$tries" -ge 0 ]; then
		wp core is-installed >/dev/null 2>&1 || prepare_fluxwp
		if bootstrap; then
			log "bootstrap finished"
			tries=-1
		else
			tries=$((tries + 1))
			# Waiting for the browser install screen does not count as a failure.
			wp core is-installed >/dev/null 2>&1 || tries=0
			if [ "$tries" -ge 30 ]; then
				log "bootstrap gave up after 30 tries; restart the worker to try again"
				tries=-1
			fi
		fi
	fi
	run_cron
	sleep "$INTERVAL"
done
