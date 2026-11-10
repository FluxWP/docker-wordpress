"""Exercise the actual shell wake functions without starting PHP or a site."""
from pathlib import Path
import shlex
import subprocess
import tempfile
import time
import unittest

SOURCE = (Path(__file__).resolve().parents[1] / 'scripts/worker.sh').read_text()
START = SOURCE.index('\nwake_due() {') + 1
FUNCTIONS = SOURCE[START:SOURCE.index('\nwait_files\n', START)]


class WakeTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.root = Path(self.directory.name)
        self.signals = self.root / 'wp-content/.flx-background-wake'
        self.signals.mkdir(parents=True)
        self.script = 'ROOT=' + shlex.quote(str(self.root)) + '\nINTERVAL=2\nPOLL=1\n' + FUNCTIONS

    def tearDown(self):
        self.directory.cleanup()

    def test_due_signal_is_consumed_before_the_next_pass(self):
        signal = self.signals / str(int(time.time()) - 1)
        signal.touch()
        subprocess.run(['sh', '-c', self.script + '\nwake_due'], check=True)
        self.assertFalse(signal.exists())
        signal.touch()  # A new arrival after consumption must survive.
        self.assertTrue(signal.exists())

    def test_new_work_wakes_a_sleeping_worker(self):
        started = time.monotonic()
        process = subprocess.Popen(['sh', '-c', self.script.replace('INTERVAL=2', 'INTERVAL=60') + '\nwait_for_work'])
        try:
            time.sleep(0.15)
            (self.signals / str(int(time.time()))).touch()
            process.wait(timeout=4)
            self.assertLess(time.monotonic() - started, 3)
        finally:
            if process.poll() is None:
                process.kill()
                process.wait()

    def test_future_job_does_not_run_early(self):
        (self.signals / str(int(time.time()) + 3)).touch()
        started = time.monotonic()
        subprocess.run(['sh', '-c', self.script.replace('INTERVAL=2', 'INTERVAL=60') + '\nwait_for_work'], check=True, timeout=6)
        self.assertGreaterEqual(time.monotonic() - started, 1.5)

    def test_periodic_fallback_still_runs_without_signals(self):
        started = time.monotonic()
        subprocess.run(['sh', '-c', self.script + '\nwait_for_work'], check=True, timeout=5)
        self.assertGreaterEqual(time.monotonic() - started, 1)

    def test_invalid_names_and_future_signals_are_preserved(self):
        for name in ['123-invalid', '99999999999999999999999999', str(int(time.time()) + 100)]:
            (self.signals / name).touch()
        result = subprocess.run(['sh', '-c', self.script + '\nwake_due'])
        self.assertEqual(result.returncode, 1)
        self.assertEqual(len(list(self.signals.iterdir())), 3)


if __name__ == '__main__':
    unittest.main()
