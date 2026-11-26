<?php
/**
 * Download and install the Flux Setup zip.
 * HTTPS only, no redirect to another scheme, size cap, path check, optional SHA-256 pin.
 */
declare(strict_types=1);

const FLX_SETUP_MAX_BYTES = 33554432;
const FLX_SETUP_MAX_UNCOMPRESSED = 134217728;
const FLX_SETUP_MAX_ENTRIES = 4000;
const FLX_SETUP_UPDATER = 'https://updater.fluxwp.com/v1/download/flx-setup/latest.zip';

function flx_setup_fail(string $message): void {
	fwrite(STDERR, "Flux Setup: $message\n");
	exit(1);
}

function flx_setup_https_url(string $url): bool {
	if ($url === '' || preg_match('/[\s\x00-\x1f]/', $url)) {
		return false;
	}
	$parts = parse_url($url);
	if (!is_array($parts)) {
		return false;
	}
	if (isset($parts['user']) || isset($parts['pass'])) {
		return false;
	}
	return strtolower((string) ($parts['scheme'] ?? '')) === 'https' && ($parts['host'] ?? '') !== '';
}

function flx_setup_resolve_location(string $base, string $location): string {
	$location = trim($location);
	if ($location === '' || str_starts_with($location, '//') || preg_match('/[\s\x00-\x1f]/', $location)) {
		return '';
	}
	if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location)) {
		return $location;
	}
	$parts = parse_url($base);
	if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
		return '';
	}
	$origin = $parts['scheme'] . '://' . $parts['host'];
	if (isset($parts['port'])) {
		$origin .= ':' . $parts['port'];
	}
	if (str_starts_with($location, '/')) {
		return $origin . $location;
	}
	$path = $parts['path'] ?? '/';
	$dir = substr($path, 0, (int) strrpos($path, '/') + 1);
	return $origin . $dir . $location;
}

function flx_setup_download(string $url, string $dest): void {
	$current = $url;
	for ($hop = 0; $hop < 5; $hop++) {
		if (!flx_setup_https_url($current)) {
			throw new RuntimeException('URL must be https');
		}
		$context = stream_context_create([
			'http' => [
				'method' => 'GET',
				'timeout' => 30,
				'follow_location' => 0,
				'max_redirects' => 0,
				'ignore_errors' => true,
				'header' => "User-Agent: fluxwp-docker\r\nAccept: application/octet-stream\r\n",
			],
			'ssl' => [
				'verify_peer' => true,
				'verify_peer_name' => true,
			],
		]);
		$handle = @fopen($current, 'rb', false, $context);
		if ($handle === false) {
			throw new RuntimeException('download failed');
		}
		$status = 0;
		$location = '';
		foreach ($http_response_header ?? [] as $header) {
			if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $match)) {
				$status = (int) $match[1];
				$location = '';
			} elseif (stripos($header, 'Location:') === 0) {
				$location = trim(substr($header, 9));
			}
		}
		if ($status >= 300 && $status < 400) {
			fclose($handle);
			if ($location === '') {
				throw new RuntimeException('redirect without a location');
			}
			$next = flx_setup_resolve_location($current, $location);
			if ($next === '' || !flx_setup_https_url($next)) {
				throw new RuntimeException('redirect is not https');
			}
			$current = $next;
			continue;
		}
		if ($status !== 200) {
			fclose($handle);
			throw new RuntimeException('HTTP ' . $status);
		}
		$length = 0;
		foreach ($http_response_header ?? [] as $header) {
			if (stripos($header, 'Content-Length:') === 0) {
				$length = (int) trim(substr($header, 15));
			}
		}
		if ($length > FLX_SETUP_MAX_BYTES) {
			fclose($handle);
			throw new RuntimeException('archive exceeds the size cap');
		}
		$out = fopen($dest, 'wb');
		if ($out === false) {
			fclose($handle);
			throw new RuntimeException('could not store the archive');
		}
		$written = 0;
		while (!feof($handle)) {
			$chunk = fread($handle, 8192);
			if ($chunk === false) {
				fclose($handle);
				fclose($out);
				throw new RuntimeException('download failed');
			}
			if ($chunk === '') {
				break;
			}
			$written += strlen($chunk);
			if ($written > FLX_SETUP_MAX_BYTES) {
				fclose($handle);
				fclose($out);
				throw new RuntimeException('archive exceeds the size cap');
			}
			if (fwrite($out, $chunk) === false) {
				fclose($handle);
				fclose($out);
				throw new RuntimeException('could not store the archive');
			}
		}
		fclose($handle);
		fclose($out);
		return;
	}
	throw new RuntimeException('too many redirects');
}

function flx_setup_zip_name_ok(string $name): bool {
	if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\')) {
		return false;
	}
	if ($name[0] === '/' || preg_match('#^[A-Za-z]:#', $name) || str_contains($name, ':')) {
		return false;
	}
	if ($name !== 'flx-setup/' && !str_starts_with($name, 'flx-setup/')) {
		return false;
	}
	foreach (explode('/', $name) as $part) {
		if ($part === '..') {
			return false;
		}
	}
	return true;
}

function flx_setup_install_zip(string $zipPath, string $pluginDir, string $pin): void {
	if ($pin !== '' && !preg_match('/^[a-f0-9]{64}$/', $pin)) {
		throw new RuntimeException('FLUXWP_SETUP_SHA256 must be 64 hex characters');
	}
	$actual = hash_file('sha256', $zipPath);
	if ($actual === false) {
		throw new RuntimeException('could not hash the archive');
	}
	if ($pin !== '' && !hash_equals($pin, $actual)) {
		throw new RuntimeException('SHA-256 does not match FLUXWP_SETUP_SHA256');
	}
	$zip = new ZipArchive();
	if ($zip->open($zipPath) !== true) {
		throw new RuntimeException('not a zip archive');
	}
	$hasMain = false;
	$uncompressed = 0;
	$count = $zip->numFiles;
	if ($count < 1 || $count > FLX_SETUP_MAX_ENTRIES) {
		$zip->close();
		throw new RuntimeException('zip entry count is not allowed');
	}
	for ($i = 0; $i < $count; $i++) {
		$name = $zip->getNameIndex($i);
		$stat = $zip->statIndex($i);
		if (!is_string($name) || !is_array($stat) || !flx_setup_zip_name_ok($name)) {
			$zip->close();
			throw new RuntimeException('zip entry path is not allowed');
		}
		$opsys = 0;
		$attr = 0;
		if (!$zip->getExternalAttributesIndex($i, $opsys, $attr)) {
			$attr = (int) ($stat['external_attributes'] ?? 0);
		}
		$mode = ($attr >> 16) & 0170000;
		if ($mode === 0120000) {
			$zip->close();
			throw new RuntimeException('zip symlink is not allowed');
		}
		$uncompressed += (int) $stat['size'];
		if ($uncompressed > FLX_SETUP_MAX_UNCOMPRESSED) {
			$zip->close();
			throw new RuntimeException('uncompressed zip exceeds the size cap');
		}
		if ($name === 'flx-setup/flx-setup.php' && (int) $stat['size'] > 0) {
			$hasMain = true;
		}
	}
	if (!$hasMain) {
		$zip->close();
		throw new RuntimeException('flx-setup/flx-setup.php is missing');
	}
	$stage = $pluginDir . '/.flx-setup-stage-' . bin2hex(random_bytes(4));
	if (!mkdir($stage, 0700)) {
		$zip->close();
		throw new RuntimeException('could not prepare the plugin directory');
	}
	$extracted = $zip->extractTo($stage);
	$zip->close();
	if ($extracted !== true || !is_file($stage . '/flx-setup/flx-setup.php') || flx_setup_tree_has_link($stage)) {
		flx_setup_remove_tree($stage);
		throw new RuntimeException('could not extract the archive');
	}
	$final = $pluginDir . '/flx-setup';
	if (file_exists($final)) {
		flx_setup_remove_tree($stage);
		throw new RuntimeException('flx-setup is already present');
	}
	if (!rename($stage . '/flx-setup', $final)) {
		flx_setup_remove_tree($stage);
		throw new RuntimeException('could not install the plugin');
	}
	flx_setup_remove_tree($stage);
}

function flx_setup_tree_has_link(string $dir): bool {
	if (is_link($dir)) {
		return true;
	}
	if (!is_dir($dir)) {
		return false;
	}
	$items = scandir($dir);
	if ($items === false) {
		return true;
	}
	foreach ($items as $item) {
		if ($item === '.' || $item === '..') {
			continue;
		}
		$path = $dir . '/' . $item;
		if (is_link($path) || (is_dir($path) && flx_setup_tree_has_link($path))) {
			return true;
		}
	}
	return false;
}

function flx_setup_remove_tree(string $dir): void {
	if (is_link($dir) || !is_dir($dir)) {
		return;
	}
	$items = scandir($dir);
	if ($items === false) {
		return;
	}
	foreach ($items as $item) {
		if ($item === '.' || $item === '..') {
			continue;
		}
		$path = $dir . '/' . $item;
		if (is_link($path) || !is_dir($path)) {
			unlink($path);
			continue;
		}
		flx_setup_remove_tree($path);
	}
	rmdir($dir);
}

function flx_setup_github_url(): string {
	$context = stream_context_create([
		'http' => [
			'timeout' => 15,
			'follow_location' => 0,
			'max_redirects' => 0,
			'ignore_errors' => true,
			'header' => "User-Agent: fluxwp-docker\r\nAccept: application/vnd.github+json\r\n",
		],
	]);
	$body = @file_get_contents('https://api.github.com/repos/FluxWP/FluxWP/releases?per_page=100', false, $context);
	$status = 0;
	foreach ($http_response_header ?? [] as $header) {
		if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $match)) {
			$status = (int) $match[1];
		}
	}
	if ($status !== 200 || !is_string($body)) {
		return '';
	}
	$releases = json_decode($body, true);
	if (!is_array($releases)) {
		return '';
	}
	foreach ($releases as $release) {
		if (!is_array($release) || !empty($release['draft']) || !empty($release['prerelease'])) {
			continue;
		}
		if (!str_starts_with((string) ($release['tag_name'] ?? ''), 'flx-setup-v')) {
			continue;
		}
		foreach ($release['assets'] ?? [] as $asset) {
			if (is_array($asset) && ($asset['name'] ?? '') === 'flx-setup.zip') {
				$url = (string) ($asset['browser_download_url'] ?? '');
				return flx_setup_https_url($url) ? $url : '';
			}
		}
	}
	return '';
}

function flx_setup_pin(): string {
	$pin = strtolower(trim((string) getenv('FLUXWP_SETUP_SHA256')));
	if ($pin === '') {
		return '';
	}
	if (!preg_match('/^[a-f0-9]{64}$/', $pin)) {
		flx_setup_fail('FLUXWP_SETUP_SHA256 must be 64 hex characters');
	}
	return $pin;
}

function flx_setup_try(string $url, string $dir, string $pin): void {
	$tmp = tempnam(sys_get_temp_dir(), 'flx');
	if ($tmp === false) {
		throw new RuntimeException('could not store the archive');
	}
	try {
		flx_setup_download($url, $tmp);
		flx_setup_install_zip($tmp, $dir, $pin);
	} catch (Throwable $e) {
		if (is_file($tmp)) {
			unlink($tmp);
		}
		throw $e;
	}
	unlink($tmp);
	fwrite(STDERR, "Flux Setup: installed from $url\n");
}

function flx_setup_main(): void {
	$dir = (string) getenv('FLX_DIR');
	if ($dir === '' || !is_dir($dir)) {
		flx_setup_fail('plugin directory is missing');
	}
	$pin = flx_setup_pin();
	$explicit = trim((string) getenv('FLUXWP_SETUP_URL'));
	if ($explicit !== '') {
		if (!flx_setup_https_url($explicit)) {
			flx_setup_fail('FLUXWP_SETUP_URL must be https');
		}
		try {
			flx_setup_try($explicit, $dir, $pin);
		} catch (Throwable $e) {
			flx_setup_fail($e->getMessage());
		}
		exit(0);
	}
	try {
		flx_setup_try(FLX_SETUP_UPDATER, $dir, $pin);
		exit(0);
	} catch (Throwable $e) {
		$updaterError = $e->getMessage();
	}
	$github = flx_setup_github_url();
	if ($github === '') {
		flx_setup_fail($updaterError);
	}
	try {
		flx_setup_try($github, $dir, $pin);
	} catch (Throwable $e) {
		flx_setup_fail($e->getMessage());
	}
	exit(0);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
	flx_setup_main();
}
