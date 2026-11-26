"""Run the real sync_site_url shell function against a fake wp-cli (no PHP, no site)."""
from pathlib import Path
import os
import shlex
import stat
import subprocess
import tempfile
import unittest

SOURCE = (Path(__file__).resolve().parents[1] / 'scripts/worker.sh').read_text()
START = SOURCE.index('\nurl_origin() {') + 1
FUNCTIONS = SOURCE[START:SOURCE.index('\nrun_cron() {', START)]
HELPERS = (
    'log() { echo "[worker] $*"; }\n'
    "truthy() { case \"$(echo \"${1:-}\" | tr '[:upper:]' '[:lower:]')\" in 1|true|yes|on) return 0 ;; *) return 1 ;; esac; }\n"
)
# Fake wp: options live in files, every call is logged.
FAKE_WP = r'''#!/bin/sh
d="$FAKE_DIR"
echo "$*" >> "$d/calls"
case "$1 $2" in
  "option get") cat "$d/opt_$3" 2>/dev/null; [ -f "$d/opt_$3" ] ;;
  "option update") printf '%s' "$4" > "$d/opt_$3" ;;
  "config has") [ -f "$d/const_$3" ] ;;
  *) exit 0 ;;
esac
'''


class UrlSyncTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.dir = Path(self.tmp.name)
        wp = self.dir / 'wp'
        wp.write_text(FAKE_WP)
        wp.chmod(wp.stat().st_mode | stat.S_IEXEC)
        self.set('siteurl', 'https://sekoyam.tech/')
        self.set('home', 'https://sekoyam.tech')

    def tearDown(self):
        self.tmp.cleanup()

    def set(self, name, value):
        (self.dir / f'opt_{name}').write_text(value)

    def run_sync(self, wp_url, **env):
        script = HELPERS + 'wp() { "$FAKE_DIR/wp" "$@"; }\n' + FUNCTIONS + '\nsync_site_url\n'
        out = subprocess.run(['sh', '-c', script], env={**os.environ, 'FAKE_DIR': str(self.dir), 'WP_URL': wp_url, **env},
                             capture_output=True, text=True)
        self.assertEqual(out.returncode, 0, out.stderr)
        calls = (self.dir / 'calls').read_text().splitlines() if (self.dir / 'calls').exists() else []
        return out.stdout, [c for c in calls if c.startswith('search-replace')]

    def test_domain_change_replaces_once(self):
        out, replaces = self.run_sync('https://licc.lv')
        self.assertIn('site address changed: https://sekoyam.tech -> https://licc.lv', out)
        self.assertEqual(len(replaces), 2)  # plain + JSON-escaped, one old origin
        self.assertTrue(all('--skip-columns=guid' in c and '--all-tables' in c for c in replaces))
        self.assertTrue(replaces[0].startswith('search-replace https://sekoyam.tech https://licc.lv '))
        self.assertTrue(replaces[1].startswith(r'search-replace https:\/\/sekoyam.tech https:\/\/licc.lv '))
        self.assertEqual((self.dir / 'opt_flx_docker_url').read_text(), 'https://licc.lv')

    def test_second_run_does_nothing(self):
        self.run_sync('https://licc.lv')
        (self.dir / 'calls').unlink()
        _, replaces = self.run_sync('https://licc.lv/')
        self.assertEqual(replaces, [])

    def test_manual_change_is_kept_when_env_is_unchanged(self):
        (self.dir / 'opt_flx_docker_url').write_text('https://licc.lv')
        self.set('siteurl', 'https://www.licc.lv')
        _, replaces = self.run_sync('https://licc.lv')
        self.assertEqual(replaces, [])

    def test_matching_site_only_records_the_url(self):
        self.set('siteurl', 'https://licc.lv')
        self.set('home', 'https://licc.lv')
        _, replaces = self.run_sync('https://licc.lv')
        self.assertEqual(replaces, [])
        self.assertEqual((self.dir / 'opt_flx_docker_url').read_text(), 'https://licc.lv')

    def test_scheme_change(self):
        self.set('siteurl', 'http://licc.lv')
        self.set('home', 'http://licc.lv')
        _, replaces = self.run_sync('https://licc.lv')
        self.assertEqual(len(replaces), 2)
        self.assertTrue(replaces[0].startswith('search-replace http://licc.lv https://licc.lv '))

    def test_path_in_url_and_opt_out_are_skipped(self):
        _, replaces = self.run_sync('https://licc.lv/blog')
        self.assertEqual(replaces, [])
        _, replaces = self.run_sync('https://licc.lv', WP_URL_SYNC='false')
        self.assertEqual(replaces, [])

    def test_constant_in_wp_config_is_warned_about(self):
        (self.dir / 'const_WP_HOME').write_text('1')
        out, _ = self.run_sync('https://licc.lv')
        self.assertIn('WP_HOME is defined in wp-config.php', out)


if __name__ == '__main__':
    unittest.main()
