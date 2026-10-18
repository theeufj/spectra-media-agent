import json
import os
import pathlib
import shutil
import subprocess
import tempfile
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[2]
EXPORTER = ROOT / 'bin/frontend-build-config'


@unittest.skipUnless(shutil.which('php'), 'PHP is required for the public-config exporter')
class FrontendBuildConfigTests(unittest.TestCase):
    def export(self, source, extra_env=None):
        with tempfile.TemporaryDirectory() as directory:
            env_file = pathlib.Path(directory) / 'deployment.env'
            env_file.write_text(source)
            return subprocess.run(
                ['php', str(EXPORTER), str(env_file)],
                cwd=ROOT,
                env={**os.environ, **(extra_env or {})},
                capture_output=True,
                text=True,
                timeout=15,
                check=False,
            )

    def assert_rejected(self, source, secret=None):
        result = self.export(source)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(result.stdout, '')
        self.assertIn('Unable to export public frontend configuration', result.stderr)
        if secret:
            self.assertNotIn(secret, result.stderr)

    def test_resolves_interpolation_and_exports_only_public_allowlist(self):
        result = self.export('''APP_NAME="Site to Spend"
STRIPE_KEY=pk_live_public123
VITE_APP_NAME="${APP_NAME}"
VITE_STRIPE_KEY="${STRIPE_KEY}"
APP_KEY=base64:private_application_key
STRIPE_SECRET=sk_live_private_secret
VITE_UNRELATED=do_not_export
''')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout, '{"VITE_APP_NAME":"Site to Spend","VITE_STRIPE_KEY":"pk_live_public123"}\n')
        self.assertEqual(result.stderr, '')

    def test_uses_app_and_stripe_fallbacks_when_vite_keys_are_absent(self):
        for stripe_key in ['pk_test_public123', 'pk_live_public456']:
            with self.subTest(stripe_key=stripe_key):
                result = self.export(f'APP_NAME="Site to Spend"\nSTRIPE_KEY={stripe_key}\n')
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(json.loads(result.stdout), {
                    'VITE_APP_NAME': 'Site to Spend',
                    'VITE_STRIPE_KEY': stripe_key,
                })

    def test_explicit_vite_values_win_and_quoted_app_name_is_preserved(self):
        result = self.export('''APP_NAME=Fallback
STRIPE_KEY=pk_test_fallback
VITE_APP_NAME='Public "Name" / 日本'
VITE_STRIPE_KEY=pk_live_override
''')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout), {
            'VITE_APP_NAME': 'Public "Name" / 日本',
            'VITE_STRIPE_KEY': 'pk_live_override',
        })

    def test_empty_vite_overrides_do_not_fall_back(self):
        for override in ['VITE_APP_NAME=', 'VITE_APP_NAME="   "', 'VITE_STRIPE_KEY=']:
            with self.subTest(override=override):
                self.assert_rejected(f'APP_NAME=Fallback\nSTRIPE_KEY=pk_test_fallback\n{override}\n')

    def test_missing_values_and_secret_stripe_keys_are_rejected_without_disclosure(self):
        self.assert_rejected('STRIPE_KEY=pk_test_public\n')
        self.assert_rejected('APP_NAME=Site\n')
        self.assert_rejected('APP_NAME=Site\nSTRIPE_KEY=sk_live_private_secret\n', 'sk_live_private_secret')
        self.assert_rejected('APP_NAME=Site\nSTRIPE_KEY=pk_live_\n')

    def test_malformed_dotenv_does_not_expose_secret_bearing_line(self):
        self.assert_rejected('APP_NAME=Site\nSTRIPE_KEY=pk_test_public\nSTRIPE_SECRET=sk_live_private_secret unexpected\n', 'sk_live_private_secret')

    def test_unresolved_public_interpolation_is_rejected(self):
        self.assert_rejected('VITE_APP_NAME="${MISSING_NAME}"\nSTRIPE_KEY=pk_test_public\n')

    def test_explicit_file_is_independent_of_caller_environment(self):
        result = self.export('APP_NAME=FileName\nSTRIPE_KEY=pk_test_file\n', {
            'APP_NAME': 'CallerName',
            'STRIPE_KEY': 'sk_live_caller_secret',
            'VITE_APP_NAME': 'CallerOverride',
            'VITE_STRIPE_KEY': 'sk_live_caller_override',
        })
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout), {
            'VITE_APP_NAME': 'FileName',
            'VITE_STRIPE_KEY': 'pk_test_file',
        })

    def test_missing_file_fails_without_output(self):
        with tempfile.TemporaryDirectory() as directory:
            result = subprocess.run(
                ['php', str(EXPORTER), str(pathlib.Path(directory) / 'missing.env')],
                cwd=ROOT, capture_output=True, text=True, timeout=15, check=False,
            )
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(result.stdout, '')


if __name__ == '__main__':
    unittest.main()
