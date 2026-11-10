#!/bin/sh
# FluxWP docker-wordpress - worker container.
# Runtime revision: 2026.11.10.2
#
# 1. Waits for the WordPress files and the database.
# 2. One-time bootstrap (idempotent, safe on every restart):
#      - installs WordPress when WP_URL is set and WP_ADMIN_EMAIL or WP_AUTO_INSTALL
#        (default true) is set. The install screen stays closed in the web server.
#        A generated admin password is written to /run/flx-private/admin-password
#        (mode 0600, only this worker can read it), not the logs:
#          docker compose exec worker cat /run/flx-private/admin-password
#        Change it after the first login, then delete the file:
#          docker compose exec worker rm /run/flx-private/admin-password
#      - installs the site language (WP_LOCALE),
#      - enables the Redis object cache when WP_REDIS_HOST is set,
#      - Flux Setup (FLUXWP_SETUP, default true): downloaded before the install screen,
#        activated on install, its wizard opens on the first wp-admin visit.
# 3. Runs due cron on shared-volume wake signals (poll 2s), with a 60s fallback.
#
# Installed in the image as /usr/local/bin/flx-worker; runs in the wordpress:cli image as www-data (33).

set -u

ROOT=/var/www/html
INTERVAL="${WP_CRON_INTERVAL:-60}"
POLL="${WP_BACKGROUND_POLL_INTERVAL:-2}"
export FLX_BACKGROUND_WAKE=1
case "$INTERVAL" in ''|*[!0-9]*) INTERVAL=60 ;; esac
case "$POLL" in ''|*[!0-9]*) POLL=2 ;; esac
[ "$INTERVAL" -ge 1 ] && [ "$INTERVAL" -le 3600 ] || INTERVAL=60
[ "$POLL" -ge 1 ] && [ "$POLL" -le 60 ] || POLL=2
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
	# WP_AUTO_INSTALL defaults to true: install without WP_ADMIN_EMAIL,
	# admin e-mail falls back to admin@<site host>.
	if [ -z "${WP_ADMIN_EMAIL:-}" ] && [ -n "${WP_URL:-}" ] && truthy "${WP_AUTO_INSTALL:-true}"; then
		host="$(echo "$WP_URL" | sed -E 's#^[a-zA-Z]+://##; s#[/:].*$##')"
		WP_ADMIN_EMAIL="admin@${host:-example.com}"
	fi
	if [ -z "${WP_URL:-}" ] || [ -z "${WP_ADMIN_EMAIL:-}" ]; then
		if [ -z "${FLX_WARNED:-}" ]; then
			log "WordPress is not installed. The install screen is closed."
			log "Leave WP_AUTO_INSTALL=true (default) or set WP_ADMIN_EMAIL to install from this worker,"
			log "or set WP_INSTALL_TOKEN to open /wp-admin/install.php in the browser."
			FLX_WARNED=1
		fi
		return 1
	fi
	log "installing WordPress at $WP_URL"
	generated=0
	if [ -z "${WP_ADMIN_PASSWORD:-}" ]; then
		generated=1
		pass="$(php -r 'echo bin2hex(random_bytes(18));')"
		write_admin_password "$pass" || { unset pass; return 1; }
		unset pass
	fi
	# --prompt reads the password from stdin so it stays out of the process list.
	# wp-cli repeats the resolved command on stdout; that stream is discarded.
	old_umask="$(umask)"
	umask 077
	if [ "$generated" -eq 1 ]; then
		wp core install --url="$WP_URL" --title="${WP_TITLE:-WordPress}" \
			--admin_user="${WP_ADMIN_USER:-admin}" --admin_email="$WP_ADMIN_EMAIL" \
			--skip-email --prompt=admin_password >/tmp/flx-wp-install.out 2>&1 < /run/flx-private/admin-password
		status=$?
	else
		printf '%s\n' "$WP_ADMIN_PASSWORD" | wp core install --url="$WP_URL" --title="${WP_TITLE:-WordPress}" \
			--admin_user="${WP_ADMIN_USER:-admin}" --admin_email="$WP_ADMIN_EMAIL" \
			--skip-email --prompt=admin_password >/tmp/flx-wp-install.out 2>&1
		status=$?
	fi
	umask "$old_umask"
	rm -f /tmp/flx-wp-install.out
	if [ "$status" -ne 0 ]; then
		log "wp core install failed"
		return 1
	fi
	log "WordPress installed: $WP_URL/wp-admin  user: ${WP_ADMIN_USER:-admin}  admin e-mail: $WP_ADMIN_EMAIL"
	if [ "$generated" -eq 1 ]; then
		log "Generated admin password was not logged. Read it with: docker compose exec worker cat /run/flx-private/admin-password"
		log "Change it after the first login, then: docker compose exec worker rm /run/flx-private/admin-password"
	else
		log "password: the WP_ADMIN_PASSWORD variable"
	fi
	wp rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || true
}

# Store a generated password where only this worker can read it. Never print it.
write_admin_password() {
	dir=/run/flx-private
	dest="$dir/admin-password"
	if [ ! -d "$dir" ] || [ ! -w "$dir" ]; then
		log "admin password directory $dir is missing or not writable"
		return 1
	fi
	old_umask="$(umask)"
	umask 077
	tmp="$dir/admin-password.tmp.$$"
	if ! printf '%s\n' "$1" > "$tmp"; then
		umask "$old_umask"
		rm -f "$tmp"
		log "could not write the admin password file"
		return 1
	fi
	if ! mv "$tmp" "$dest"; then
		umask "$old_umask"
		rm -f "$tmp"
		log "could not write the admin password file"
		return 1
	fi
	if ! chmod 0600 "$dest"; then
		umask "$old_umask"
		rm -f "$dest"
		log "could not set the admin password file mode"
		return 1
	fi
	umask "$old_umask"
	return 0
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
	log "downloading Flux Setup"
	FLX_DIR="$ROOT/wp-content/plugins" php /usr/local/bin/flx-setup-zip.php || { log "Flux Setup download failed, will retry"; return 1; }
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

# Check tiny shared-volume notifications without bootstrapping PHP/WordPress.
# Remove due signals before running cron, so arrivals during a pass survive.
wake_due() {
	now="$(date +%s)"
	found=1
	for signal in "$ROOT/wp-content/.flx-background-wake/"[0-9]*; do
		[ -f "$signal" ] || continue
		due="${signal##*/}"
		case "$due" in ''|*[!0-9]*) continue ;; esac
		[ "${#due}" -le 12 ] || continue
		if [ "$due" -le "$now" ]; then
			rm -f "$signal"
			found=0
		fi
	done
	return "$found"
}

wait_for_work() {
	deadline=$(( $(date +%s) + INTERVAL ))
	while [ "$(date +%s)" -lt "$deadline" ]; do
		wake_due && return 0
		sleep "$POLL"
	done
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
	wait_for_work
done
