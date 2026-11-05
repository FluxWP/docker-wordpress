#!/usr/bin/env python3
"""Builds every compose file from one source.

    python3 tools/build.py          # write coolify/* and compose/*
    python3 tools/build.py --check  # fail if the committed files are out of date (CI)

coolify/<variant>/docker-compose.yml  single file for Coolify, Coolify magic variables.
compose/<variant>/docker-compose.yml  plain Docker Compose with .env.

Config files and the worker script are baked into ghcr.io/fluxwp/wordpress images
(images/*/Dockerfile), so the compose files stay short.

    python3 tools/build.py --matrix  # JSON build matrix for the images workflow
"""

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

REGISTRY = "ghcr.io/fluxwp/wordpress"

# Our images: tag -> (Dockerfile, upstream base). Built by .github/workflows/images.yml.
BUILDS = {
    "7.1-php8.4-apache": ("images/wordpress/Dockerfile", "wordpress:7.1-php8.4-apache"),
    "7.1-php8.4-fpm": ("images/wordpress/Dockerfile", "wordpress:7.1-php8.4-fpm"),
    "worker-php8.4": ("images/worker/Dockerfile", "wordpress:cli-2.12-php8.4"),
    "nginx-1.30": ("images/nginx/Dockerfile", "nginx:1.30-alpine"),
}

IMAGES = {
    "wordpress-apache": f"{REGISTRY}:7.1-php8.4-apache",
    "wordpress-fpm": f"{REGISTRY}:7.1-php8.4-fpm",
    "worker": f"{REGISTRY}:worker-php8.4",
    "nginx": f"{REGISTRY}:nginx-1.30",
    "mariadb": "mariadb:11.8",
    "mysql": "mysql:8.4",
    "redis": "redis:8.8-alpine",
}

VARIANTS = {
    "mariadb": {
        "title": "WordPress + MariaDB (Apache)",
        "db": "mariadb", "redis": False, "web": "apache",
    },
    "mysql": {
        "title": "WordPress + MySQL (Apache)",
        "db": "mysql", "redis": False, "web": "apache",
    },
    "mariadb-redis": {
        "title": "WordPress + MariaDB + Redis object cache (Apache)",
        "db": "mariadb", "redis": True, "web": "apache",
    },
    "nginx-fpm": {
        "title": "WordPress PHP-FPM + nginx + MariaDB + Redis object cache",
        "db": "mariadb", "redis": True, "web": "nginx",
    },
}

DEFAULT = "mariadb-redis"

# Per-mode values. Coolify generates SERVICE_* secrets itself.
MODES = {
    "coolify": {
        "db_user": "$SERVICE_USER_WORDPRESS",
        "db_password": "$SERVICE_PASSWORD_WORDPRESS",
        "db_root_password": "$SERVICE_PASSWORD_ROOT",
        "db_name": "${DB_NAME:-wordpress}",
        "redis_password": "$SERVICE_PASSWORD_REDIS",
        "wp_url": "$SERVICE_URL_WORDPRESS",
        "wp_admin_user": "${WP_ADMIN_USER:-}",
        "wp_admin_password": "${WP_ADMIN_PASSWORD:-}",
        "wp_admin_email": "${WP_ADMIN_EMAIL:-}",
        "wp_auto_install": "${WP_AUTO_INSTALL:-false}",
    },
    "compose": {
        "db_user": "${DB_USER:-wordpress}",
        "db_password": "${DB_PASSWORD:?set DB_PASSWORD in .env}",
        "db_root_password": "${DB_ROOT_PASSWORD:?set DB_ROOT_PASSWORD in .env}",
        "db_name": "${DB_NAME:-wordpress}",
        "redis_password": "${REDIS_PASSWORD:?set REDIS_PASSWORD in .env}",
        "wp_url": "${WP_URL:-}",
        "wp_admin_user": "${WP_ADMIN_USER:-admin}",
        "wp_admin_password": "${WP_ADMIN_PASSWORD:-}",
        "wp_admin_email": "${WP_ADMIN_EMAIL:-}",
        "wp_auto_install": "${WP_AUTO_INSTALL:-false}",
    },
}

def env(lines, indent=6):
    pad = " " * indent
    return "".join(f"{pad}- {line}\n" for line in lines)


def wp_env(mode, v):
    m = MODES[mode]
    db_host = v["db"]
    lines = [
        f"WORDPRESS_DB_HOST={db_host}",
        f"WORDPRESS_DB_USER={m['db_user']}",
        f"WORDPRESS_DB_PASSWORD={m['db_password']}",
        f"WORDPRESS_DB_NAME={m['db_name']}",
        "WORDPRESS_TABLE_PREFIX=${WORDPRESS_TABLE_PREFIX:-wp_}",
        "PHP_MEMORY_LIMIT=${PHP_MEMORY_LIMIT:-256M}",
        "PHP_UPLOAD_MAX_FILESIZE=${PHP_UPLOAD_MAX_FILESIZE:-128M}",
        "PHP_POST_MAX_SIZE=${PHP_POST_MAX_SIZE:-128M}",
        "PHP_MAX_EXECUTION_TIME=${PHP_MAX_EXECUTION_TIME:-300}",
        "PHP_OPCACHE_MEMORY=${PHP_OPCACHE_MEMORY:-192}",
        "PHP_TIMEZONE=${PHP_TIMEZONE:-UTC}",
        "WP_ENVIRONMENT_TYPE=${WP_ENVIRONMENT_TYPE:-production}",
    ]
    if v["redis"]:
        lines += [
            "WP_REDIS_HOST=redis",
            f"WP_REDIS_PASSWORD={m['redis_password']}",
        ]
    return lines


def header(mode, name, v):
    lines = [
        f"# FluxWP docker-wordpress: {v['title']}",
        "# https://github.com/FluxWP/docker-wordpress",
        "#",
    ]
    if mode == "coolify":
        lines += [
            "# Coolify: Projects > Add Resource > Docker Compose Empty > paste this file > Save.",
            "# Coolify generates a domain for the '" + ("nginx" if v["web"] == "nginx" else "wordpress") + "' service; put your own there if you like, then Deploy.",
            "# After the first deploy open your domain and create the admin account on the WordPress",
            "# install screen. Optional: set WP_ADMIN_USER, WP_ADMIN_PASSWORD, WP_ADMIN_EMAIL to install",
            "# automatically instead.",
            "# FLUXWP_SETUP=true installs the Flux Setup plugin (FluxWP installer + updates).",
        ]
    else:
        lines += [
            "# Usage: cp .env.example .env  (set passwords)  &&  docker compose up -d",
        ]
    lines.append("# Generated by tools/build.py - edit that, not this file.")
    return "\n".join(lines) + "\n\n"


def db_service(mode, v):
    m = MODES[mode]
    if v["db"] == "mariadb":
        return (
            "  mariadb:\n"
            f"    image: '{IMAGES['mariadb']}'\n"
            "    restart: unless-stopped\n"
            "    command:\n"
            "      - --character-set-server=utf8mb4\n"
            "      - --collation-server=utf8mb4_unicode_ci\n"
            "      - --innodb-buffer-pool-size=${DB_BUFFER_POOL_SIZE:-256M}\n"
            "      - --max-connections=${DB_MAX_CONNECTIONS:-100}\n"
            "      - --max-allowed-packet=64M\n"
            "      - --tmp-table-size=32M\n"
            "      - --max-heap-table-size=32M\n"
            "      - --skip-name-resolve\n"
            "      - --performance-schema=OFF\n"
            "    volumes:\n"
            "      - 'mariadb-data:/var/lib/mysql'\n"
            "    environment:\n"
            + env([
                f"MARIADB_ROOT_PASSWORD={m['db_root_password']}",
                f"MARIADB_DATABASE={m['db_name']}",
                f"MARIADB_USER={m['db_user']}",
                f"MARIADB_PASSWORD={m['db_password']}",
                "MARIADB_AUTO_UPGRADE=1",
            ])
            + "    healthcheck:\n"
            "      test: ['CMD', 'healthcheck.sh', '--connect', '--innodb_initialized']\n"
            "      interval: 10s\n"
            "      timeout: 5s\n"
            "      retries: 10\n"
            "      start_period: 30s\n"
            "    security_opt:\n"
            "      - no-new-privileges:true\n"
        )
    return (
        "  mysql:\n"
        f"    image: '{IMAGES['mysql']}'\n"
        "    restart: unless-stopped\n"
        "    command:\n"
        "      - --character-set-server=utf8mb4\n"
        "      - --collation-server=utf8mb4_unicode_ci\n"
        "      - --innodb-buffer-pool-size=${DB_BUFFER_POOL_SIZE:-256M}\n"
        "      - --max-connections=${DB_MAX_CONNECTIONS:-100}\n"
        "      - --max-allowed-packet=64M\n"
        "      - --skip-name-resolve\n"
        "      - --performance-schema=OFF\n"
        "      - --disable-log-bin\n"
        "    volumes:\n"
        "      - 'mysql-data:/var/lib/mysql'\n"
        "    environment:\n"
        + env([
            f"MYSQL_ROOT_PASSWORD={m['db_root_password']}",
            f"MYSQL_DATABASE={m['db_name']}",
            f"MYSQL_USER={m['db_user']}",
            f"MYSQL_PASSWORD={m['db_password']}",
        ])
        + "    healthcheck:\n"
        "      test: ['CMD-SHELL', 'mysqladmin ping -h 127.0.0.1 -uroot -p\"$$MYSQL_ROOT_PASSWORD\" --silent']\n"
        "      interval: 10s\n"
        "      timeout: 5s\n"
        "      retries: 10\n"
        "      start_period: 40s\n"
        "    security_opt:\n"
        "      - no-new-privileges:true\n"
    )


def redis_service(mode):
    m = MODES[mode]
    return (
        "  redis:\n"
        f"    image: '{IMAGES['redis']}'\n"
        "    restart: unless-stopped\n"
        "    # Cache only: no persistence, oldest keys are evicted when full.\n"
        "    command:\n"
        "      - redis-server\n"
        "      - --maxmemory\n"
        "      - ${REDIS_MAXMEMORY:-128mb}\n"
        "      - --maxmemory-policy\n"
        "      - allkeys-lru\n"
        "      - --save\n"
        "      - ''\n"
        "      - --appendonly\n"
        "      - 'no'\n"
        "      - --requirepass\n"
        f"      - {m['redis_password']}\n"
        "    environment:\n"
        + env([f"REDISCLI_AUTH={m['redis_password']}"])
        + "    healthcheck:\n"
        "      test: ['CMD-SHELL', 'redis-cli ping | grep -q PONG']\n"
        "      interval: 10s\n"
        "      timeout: 5s\n"
        "      retries: 10\n"
        "    security_opt:\n"
        "      - no-new-privileges:true\n"
    )


def depends(v, extra=()):
    out = "    depends_on:\n"
    out += f"      {v['db']}:\n        condition: service_healthy\n"
    if v["redis"]:
        out += "      redis:\n        condition: service_healthy\n"
    for name, cond in extra:
        out += f"      {name}:\n        condition: {cond}\n"
    return out


def wordpress_service(mode, v):
    apache = v["web"] == "apache"
    out = (
        "  wordpress:\n"
        f"    image: '{IMAGES['wordpress-apache' if apache else 'wordpress-fpm']}'\n"
        "    restart: unless-stopped\n"
        "    volumes:\n"
        "      - 'wordpress-files:/var/www/html'\n"
    )
    out += "    environment:\n"
    lines = []
    if mode == "coolify" and apache:
        lines.append("SERVICE_URL_WORDPRESS")
    lines += wp_env(mode, v)
    if apache:
        lines.append("APACHE_MAX_WORKERS=${APACHE_MAX_WORKERS:-20}")
    else:
        lines.append("PHP_FPM_MAX_CHILDREN=${PHP_FPM_MAX_CHILDREN:-10}")
    out += env(lines)
    if mode == "compose" and apache:
        out += "    ports:\n      - '${HTTP_PORT:-8080}:80'\n"
    out += depends(v)
    if apache:
        out += (
            "    healthcheck:\n"
            "      test: ['CMD', 'curl', '-fsS', '-o', '/dev/null', 'http://127.0.0.1/wp-includes/images/blank.gif']\n"
        )
    else:
        out += (
            "    healthcheck:\n"
            "      test: ['CMD', 'php', '-r', \"exit(@fsockopen('127.0.0.1', 9000) ? 0 : 1);\"]\n"
        )
    out += (
        "      interval: 15s\n"
        "      timeout: 5s\n"
        "      retries: 10\n"
        "      start_period: 30s\n"
        "    security_opt:\n"
        "      - no-new-privileges:true\n"
    )
    return out


def nginx_service(mode):
    out = (
        "  nginx:\n"
        f"    image: '{IMAGES['nginx']}'\n"
        "    restart: unless-stopped\n"
        "    volumes:\n"
        "      - 'wordpress-files:/var/www/html:ro'\n"
    )
    if mode == "coolify":
        out += "    environment:\n" + env(["SERVICE_URL_NGINX"])
    else:
        out += "    ports:\n      - '${HTTP_PORT:-8080}:80'\n"
    out += (
        "    depends_on:\n"
        "      wordpress:\n"
        "        condition: service_healthy\n"
        "    healthcheck:\n"
        "      test: ['CMD', 'wget', '-q', '-O', '/dev/null', 'http://127.0.0.1/fpm-ping']\n"
        "      interval: 15s\n"
        "      timeout: 5s\n"
        "      retries: 10\n"
        "      start_period: 10s\n"
    )
    return out


def worker_service(mode, v):
    m = MODES[mode]
    out = (
        "  # One-time setup (auto install, language, Redis, Flux Setup) + WP-Cron every minute.\n"
        "  worker:\n"
        f"    image: '{IMAGES['worker']}'\n"
        "    restart: unless-stopped\n"
        "    volumes:\n"
        "      - 'wordpress-files:/var/www/html'\n"
        "    environment:\n"
        + env(wp_env(mode, v) + [
            f"WP_URL={m['wp_url'].replace('WORDPRESS', 'NGINX') if v['web'] == 'nginx' else m['wp_url']}",
            "WP_TITLE=${WP_TITLE:-WordPress}",
            f"WP_ADMIN_USER={m['wp_admin_user']}",
            f"WP_ADMIN_PASSWORD={m['wp_admin_password']}",
            f"WP_ADMIN_EMAIL={m['wp_admin_email']}",
            f"WP_AUTO_INSTALL={m['wp_auto_install']}",
            "WP_LOCALE=${WP_LOCALE:-en_US}",
            "WP_TIMEZONE=${WP_TIMEZONE:-UTC}",
            "WP_CRON_INTERVAL=${WP_CRON_INTERVAL:-60}",
            "FLUXWP_SETUP=${FLUXWP_SETUP:-false}",
            "FLUXWP_SETUP_URL=${FLUXWP_SETUP_URL:-}",
        ])
        + depends(v, [("wordpress", "service_started")])
        + "    security_opt:\n"
        "      - no-new-privileges:true\n"
    )
    if mode == "coolify":
        out += "    exclude_from_hc: true\n"
    return out


def build(mode, name, v):
    out = header(mode, name, v) + "services:\n"
    out += wordpress_service(mode, v)
    if v["web"] == "nginx":
        out += nginx_service(mode)
    out += worker_service(mode, v)
    out += db_service(mode, v)
    if v["redis"]:
        out += redis_service(mode)
    out += "\nvolumes:\n  wordpress-files:\n"
    out += f"  {v['db']}-data:\n"
    return out


def env_example(name, v):
    lines = [
        f"# FluxWP docker-wordpress: {v['title']}",
        "# Copy to .env and fill in. Generate passwords with: openssl rand -base64 24",
        "",
        "# Port on the host (put a reverse proxy with HTTPS in front for production).",
        "HTTP_PORT=8080",
        "",
        "# Database",
        "DB_NAME=wordpress",
        "DB_USER=wordpress",
        "DB_PASSWORD=",
        "DB_ROOT_PASSWORD=",
        "DB_BUFFER_POOL_SIZE=256M",
        "DB_MAX_CONNECTIONS=100",
    ]
    if v["redis"]:
        lines += ["", "# Redis object cache", "REDIS_PASSWORD=", "REDIS_MAXMEMORY=128mb"]
    lines += [
        "",
        "# Automatic install: set WP_ADMIN_EMAIL, or WP_AUTO_INSTALL=true (e-mail admin@<host>).",
        "# Leave both empty to use the browser install screen.",
        "# Empty WP_ADMIN_PASSWORD = random password, printed once in `docker compose logs worker`.",
        "WP_URL=http://localhost:8080",
        "WP_TITLE=WordPress",
        "WP_ADMIN_USER=admin",
        "WP_ADMIN_PASSWORD=",
        "WP_ADMIN_EMAIL=",
        "WP_AUTO_INSTALL=false",
        "WP_LOCALE=en_US",
        "WP_TIMEZONE=UTC",
        "",
        "# Install the Flux Setup plugin (FluxWP installer and updates).",
        "FLUXWP_SETUP=false",
        "# Optional custom zip address (default: updater.fluxwp.com, then the GitHub release).",
        "FLUXWP_SETUP_URL=",
        "",
        "# PHP",
        "PHP_MEMORY_LIMIT=256M",
        "PHP_UPLOAD_MAX_FILESIZE=128M",
        "PHP_POST_MAX_SIZE=128M",
        "PHP_MAX_EXECUTION_TIME=300",
        "PHP_OPCACHE_MEMORY=192",
        "PHP_TIMEZONE=UTC",
    ]
    if v["web"] == "apache":
        lines += ["# Apache processes (each ~64 MB RAM).", "APACHE_MAX_WORKERS=20"]
    else:
        lines += ["# PHP-FPM workers (each ~64 MB RAM).", "PHP_FPM_MAX_CHILDREN=10"]
    lines += [
        "",
        "WP_ENVIRONMENT_TYPE=production",
        "WP_CRON_INTERVAL=60",
        "WORDPRESS_TABLE_PREFIX=wp_",
    ]
    return "\n".join(lines) + "\n"


def coolify_template():
    """Submission file for coollabsio/coolify templates/compose (their conventions:
    metadata header, no restart, no empty top-level volumes)."""
    v = VARIANTS["mariadb-redis"]
    body = build("coolify", "mariadb-redis", v)
    body = body[body.index("services:"):]
    body = body.replace("    restart: unless-stopped\n", "")
    body = body[:body.index("\nvolumes:\n")] + "\n"
    head = (
        "# documentation: https://github.com/FluxWP/docker-wordpress\n"
        "# slogan: FluxWP WordPress: production-tuned WordPress with Redis object cache, WP-Cron worker and automatic install.\n"
        "# category: cms\n"
        "# tags: wordpress, cms, blog, woocommerce, mariadb, redis, fluxwp\n"
        "# logo: svgs/fluxwp-wordpress.svg\n"
        "# port: 80\n\n"
    )
    return head + body


def outputs():
    files = {"coolify-template/fluxwp-wordpress.yaml": coolify_template()}
    # Default stack in the repository root for Coolify's Git deployments: Coolify reads
    # /docker-compose.yaml by default, so a repository resource gets a domain with no setup.
    files["docker-compose.yaml"] = build("coolify", DEFAULT, VARIANTS[DEFAULT]).replace(
        "# Coolify: Projects > Add Resource > Docker Compose Empty > paste this file > Save.",
        "# Default stack (same as coolify/" + DEFAULT + "). Coolify: add this repository as a resource\n"
        "# (Docker Compose build pack, default location /docker-compose.yaml), or paste this file into\n"
        "# Docker Compose Empty. Plain Docker: use compose/" + DEFAULT + "/ instead.",
    )
    for name, v in VARIANTS.items():
        files[f"coolify/{name}/docker-compose.yml"] = build("coolify", name, v)
        files[f"compose/{name}/docker-compose.yml"] = build("compose", name, v)
        files[f"compose/{name}/.env.example"] = env_example(name, v)
    return files


def matrix():
    import json
    return json.dumps({"include": [
        {"tag": tag, "dockerfile": df, "base": base} for tag, (df, base) in BUILDS.items()
    ]})


def main():
    if "--matrix" in sys.argv:
        print(matrix())
        return
    check = "--check" in sys.argv
    stale = []
    for rel, text in outputs().items():
        path = ROOT / rel
        if check:
            if not path.exists() or path.read_text() != text:
                stale.append(rel)
            continue
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text)
        print("wrote", rel)
    if stale:
        print("Out of date, run python3 tools/build.py:\n  " + "\n  ".join(stale))
        sys.exit(1)


if __name__ == "__main__":
    main()
