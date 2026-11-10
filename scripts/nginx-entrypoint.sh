#!/bin/sh
# Reject a WP_INSTALL_TOKEN that would break the nginx template, then start nginx.
set -eu
token="${WP_INSTALL_TOKEN-}"
if [ -n "$token" ]; then
	case "$token" in
		*[!A-Za-z0-9]*)
			echo "flx-nginx: WP_INSTALL_TOKEN must be alphanumeric" >&2
			exit 1
			;;
	esac
	if [ "${#token}" -gt 128 ]; then
		echo "flx-nginx: WP_INSTALL_TOKEN must be at most 128 characters" >&2
		exit 1
	fi
fi
exec /docker-entrypoint.sh "$@"
