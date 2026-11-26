# Changelog

## 2026.11.26.1

- The Docker stacks moved back from `FluxWP/FluxWP` (`docker/`) to this repository, with the realtime and wpapi stacks, the local compose file and every change since the move. Plugin and theme releases in the monorepo no longer redeploy Docker sites on Coolify. Images and tags (`ghcr.io/fluxwp/wordpress:*`) are unchanged. For an existing Coolify app: repository `FluxWP/docker-wordpress`, Base Directory `/`, Watch Paths empty.

## 2026.11.25.1

- PhpRedis 6 with igbinary is built into the `wordpress` (apache, fpm) and `worker` images (`igbinary` + `redis` PECL, redis compiled with igbinary). Redis object cache plugins pick it up on their own (Redis Object Cache: set `WP_REDIS_SERIALIZER=igbinary`). Rebuild/redeploy to get it.

## 2026.11.20.3

- Realtime serves visitors too (image `realtime-bun1.4` rebuilt, mu-plugin FluxWP Realtime 1.1.0): visitor tickets (`flx_realtime_visitor_ticket()`) that carry only the topics WordPress listed, never users or capabilities; `flx_realtime_publish( ..., array( 'topic' => ... ) )` reaches exactly those sockets; public topics (`public.*`) through the `flx_realtime_public_topics` filter and `POST /wp-json/flx-realtime/v1/public-ticket`. `RT_MAX_VISITORS` (default 300) caps visitor sockets, `RT_VISITOR_LIFE_MINUTES` (default 60) their lifetime. Redeploy the stack to pick up the new image and mu-plugin.

## 2026.11.20.2

- New optional `realtime` service in every stack with Redis (`mariadb-redis`, `nginx-fpm`, `wpapi`, and the default `docker-compose.yml`), image `ghcr.io/fluxwp/wordpress:realtime-bun1.4`: WebSocket live updates for the FluxWP panel and FluxApp. WordPress publishes small signals to Redis (no content), the service passes them to signed-in clients on the site's own domain at `/flx-rt` (Coolify routes it; `nginx-fpm` proxies it). Coolify generates the shared secret; plain Docker sets `FLX_REALTIME_SECRET`. Without it, or if the service is down, clients poll as before.
- New mu-plugin FluxWP Realtime 1.0.0 (`flx-realtime.php`, written by the worker only when `FLX_REALTIME_SECRET` is set): `flx_realtime_publish()`, single-use 60 second tickets (`POST /wp-json/flx-realtime/v1/ticket`), presence (`flx_realtime_online()`), sockets closed on logout / role / password change, and `wp flx realtime status|test`.

## 2026.11.20.1

- The worker follows a domain change. When `WP_URL` (Coolify's domain) differs from the `siteurl`/`home` stored in WordPress, it replaces the old address in the database once (`wp search-replace`, serialized-safe, `guid` skipped, JSON-escaped copies included), flushes the cache and records the address in the `flx_docker_url` option. Logged, off with `WP_URL_SYNC=false`; a change made by hand in wp-admin is kept while `WP_URL` stays the same. Warns when `WP_HOME`/`WP_SITEURL` in `wp-config.php` override the database.
- README (EN/TR): "Changing the domain" section with the automatic behaviour, a checklist for a redirect to the old domain, and the manual WP-CLI commands, including the `wp-cli.phar` way for the `wordpress` container, which has no WP-CLI.

## 2026.11.19.2

- `WP_AUTO_INSTALL` now defaults to false in every stack: the domain shows WordPress's own install screen (language, site name, admin) again, open without a token (Apache and nginx). `WP_AUTO_INSTALL=true` keeps the unattended worker install and the closed install screen (`WP_INSTALL_TOKEN` still opens it). Anyone who reaches a brand-new domain first can run the install, so open it right after the deploy.

- FluxWP Docker mu-plugin 1.3.0: the first time an administrator opens wp-admin while Flux Setup's wizard is still pending, they are sent to the wizard once, even if the activation flag or Flux Setup's 60 second redirect was missed (seen on the `wpapi` stack: login went straight to the dashboard). Filter `flx_docker_first_run_redirect` turns it off.

## 2026.11.19.1

- Coolify Git deploys of a non-default stack: README now says to use `/coolify/<stack>/docker-compose.yml` as the Compose Location. `compose/<stack>/` (plain Docker) has no `SERVICE_URL_*`, so Coolify found no domain there. The plain files now carry a warning line saying so.

## 2026.11.18.1

- First open of a brand-new site: the FluxWP Docker mu-plugin (1.2.0) sends visitors to the WordPress login, where Flux Setup's wizard opens, until the wizard is done or skipped, a preset is applied or the site has content. Before the worker has installed WordPress, the closed install screen now shows a short "being set up" page (auto-refresh, points to the worker log) instead of a bare 403, in the Apache and nginx images.

## 2026.11.17.1

- New `wpapi` stack (`compose/wpapi`, `coolify/wpapi`): `mariadb-redis` plus the FluxWP WhatsApp API (flx-wpapi) as an internal `wpapi` service, image `ghcr.io/fluxwp/wordpress:wpapi-node22`. One shared `WPAPI_KEY` is used by both sides; the FluxWP Docker mu-plugin adds the WhatsApp connection in Flux Mail, so nothing is linked by hand. The default stack and the other stacks are unchanged.
- OPcache default raised from 192 MB to 256 MB (`PHP_OPCACHE_MEMORY`). A FluxWP site with WooCommerce and the Flux packages already fills ~155 MB after one pass over its screens; updates leave wasted memory on top, and a full OPcache stops caching new files.

## 2026.11.14.1

- `docker/docker-compose.local.yml` and `docker/.env.example`: the default stack (MariaDB + Redis) for plain Docker next to the Coolify `docker-compose.yml`. Run `cp .env.example .env && docker compose -f docker-compose.local.yml up -d` in `docker/`.

## 2026.11.13.1

- Moved from `FluxWP/docker-wordpress` into the FluxWP monorepo under `docker/`. Image names and tags (`ghcr.io/fluxwp/wordpress:*`) are unchanged; Coolify Git deploys use Base Directory `/docker` and Watch Paths `docker/**`.
- Image builds and checks run from `.github/workflows/docker-images.yml` and `docker-validate.yml`, path-filtered to `docker/` so they never start a plugin or theme release.

## 2026.11.10.2

- Close `/wp-admin/install.php` and `/wp-admin/setup-config.php` unless `WP_INSTALL_TOKEN` is presented. `WP_AUTO_INSTALL` now defaults to true.
- Download Flux Setup over HTTPS only, reject HTTP errors and redirects to a non-HTTPS URL, cap the archive size, and install it only when the zip contains `flx-setup/flx-setup.php` and no escaping paths. Optional `FLUXWP_SETUP_SHA256` pin.
- Store a generated admin password in a file only the worker can read (`/run/flx-private/admin-password`, mode 0600) instead of container logs.

## 2026.11.10.1

- Wake compatible Flux background jobs through due-time shared-volume signals, with lightweight configurable polling and the existing cron fallback.
- Expose per-plugin concurrency (default 3), total concurrency (default 9) and poll interval (default 2 seconds) in all generated deployment variants.
- Advertise wake capability in the worker image; older plugin/image combinations keep native cron behavior.
- Add wake behavior checks to validation.
- Wait for Redis readiness independently of Flux Setup activation in smoke validation.
