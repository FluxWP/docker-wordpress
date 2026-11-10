# Changelog

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
