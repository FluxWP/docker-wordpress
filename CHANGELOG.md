# Changelog

## 2026.11.10.1

- Wake compatible Flux background jobs through due-time shared-volume signals, with lightweight configurable polling and the existing cron fallback.
- Expose per-plugin concurrency (default 3), total concurrency (default 9) and poll interval (default 2 seconds) in all generated deployment variants.
- Advertise wake capability in the worker image; older plugin/image combinations keep native cron behavior.
- Add wake behavior checks to validation.
