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
# Used inside a quoted nginx string: anything but letters and digits counts as "not true".
case "${WP_AUTO_INSTALL-}" in
	*[!A-Za-z0-9]*) WP_AUTO_INSTALL=false ;;
esac
export WP_AUTO_INSTALL="${WP_AUTO_INSTALL-false}"
exec /docker-entrypoint.sh "$@"
