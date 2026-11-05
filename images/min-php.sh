#!/bin/sh
# Refuse to build on a PHP release with known critical RCEs.
# PHP2Shell (CVE-2026-109618 / CVE-2026-109619) is fixed in 8.5.12, 8.4.27, 8.3.36, 8.2.35.
set -eu
php -r '
	$min = array("8.5" => "8.5.12", "8.4" => "8.4.27", "8.3" => "8.3.36", "8.2" => "8.2.35");
	$branch = PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;
	if (!isset($min[$branch]) || version_compare(PHP_VERSION, $min[$branch], "<")) {
		fwrite(STDERR, "PHP " . PHP_VERSION . " is unsupported or has known critical vulnerabilities\n");
		exit(1);
	}
	echo "PHP " . PHP_VERSION . " ok\n";
'
