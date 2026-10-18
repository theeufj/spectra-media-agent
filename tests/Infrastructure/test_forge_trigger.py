import importlib.machinery
import importlib.util
import io
import json
import os
import pathlib
import tempfile
import unittest
from unittest import mock
import urllib.error
import urllib.parse

path = pathlib.Path(__file__).resolve().parents[2] / 'bin/trigger-forge-deploy'
loader = importlib.machinery.SourceFileLoader('forge_trigger', str(path))
spec = importlib.util.spec_from_loader(loader.name, loader)
trigger = importlib.util.module_from_spec(spec)
loader.exec_module(trigger)


def run(**changes):
    return dict(head_repository={'full_name': trigger.REPOSITORY}, head_branch='master',
                event='push', path=trigger.WORKFLOW, status='completed', conclusion='success',
                head_sha='a' * 40, id=123, run_attempt=1, **changes)


class ForgeTriggerTest(unittest.TestCase):
    def test_hook_uses_get_with_explicit_client_headers_and_no_body(self):
        good = run()
        hook = 'https://forge.laravel.com/servers/1179907/sites/3094000/deploy/http?token=private-hook-token'
        request = trigger.forge_request(hook, good)
        self.assertEqual(request.get_method(), 'GET')
        self.assertIsNone(request.data)
        self.assertEqual(request.get_header('Accept'), 'application/json')
        self.assertEqual(request.get_header('User-agent'), 'SiteToSpend-Deployment/1.0')
        query = urllib.parse.parse_qs(urllib.parse.urlsplit(request.full_url).query)
        self.assertEqual(query['token'], ['private-hook-token'])
        self.assertEqual(query['forge_deploy_branch'], ['master'])
        self.assertEqual(query['forge_deploy_commit'], [good['head_sha']])
        self.assertEqual(query['forge_deploy_message'], ['CI 123/1: tested frontend assets'])

    def test_hook_rejects_wrong_scheme_host_site_and_missing_token(self):
        valid = 'https://forge.laravel.com/servers/1179907/sites/3094000/deploy/http?token=private-hook-token'
        for hook in [valid.replace('https:', 'http:'), valid.replace('forge.laravel.com', 'attacker.example'),
                     valid.replace('3094000', '9999999'), valid.split('?')[0],
                     valid.replace('token=private-hook-token', 'token=')]:
            with self.subTest(hook=hook):
                with self.assertRaises(ValueError):
                    trigger.forge_request(hook, run())

    def test_failure_diagnostics_never_include_hook_token_response_or_exception_message(self):
        secret = 'private-hook-token-and-response'
        hook = 'https://forge.laravel.com/servers/1179907/sites/3094000/deploy/http?token=' + secret
        errors = [urllib.error.HTTPError(hook, 403, secret, {'Private': secret}, io.BytesIO(secret.encode())),
                  urllib.error.URLError(secret), ValueError(secret)]
        for error in errors:
            if isinstance(error, urllib.error.HTTPError):
                self.addCleanup(error.close)
            with self.subTest(error=type(error).__name__):
                message = trigger.failure_message('Forge dispatch', error)
                self.assertIn('Forge dispatch', message)
                self.assertIn(type(error).__name__, message)
                self.assertNotIn(secret, message)
                self.assertNotIn(hook, message)
                if isinstance(error, urllib.error.HTTPError):
                    self.assertIn('HTTP 403', message)
        self.assertNotIn(secret, trigger.failure_message(secret, ValueError(secret)))

    def test_dispatch_failure_reports_safe_phase_and_does_not_retry(self):
        good = run()
        hook = 'https://forge.laravel.com/servers/1179907/sites/3094000/deploy/http?token=private-hook-token'
        error = urllib.error.HTTPError(hook, 403, 'private-hook-token', {}, io.BytesIO(b'private-hook-token'))
        with tempfile.TemporaryDirectory() as directory:
            event = pathlib.Path(directory) / 'event.json'
            event.write_text(json.dumps({'workflow_run': good}))
            environment = {'GITHUB_EVENT_PATH': str(event), 'GH_TOKEN': 'private-github-token',
                           'FORGE_DEPLOY_HOOK': hook}
            responses = [{'object': {'sha': good['head_sha']}}, {'workflow_runs': [good]}]
            with mock.patch.dict(os.environ, environment), \
                    mock.patch.object(trigger, 'read_json', side_effect=responses), \
                    mock.patch.object(trigger.urllib.request, 'urlopen', side_effect=error) as send:
                with self.assertRaises(trigger.DeploymentFailure) as raised:
                    trigger.main()
        self.assertEqual(send.call_count, 1)
        self.assertEqual(send.call_args.args[0].get_method(), 'GET')
        self.assertIn('Forge dispatch (HTTPError, HTTP 403)', str(raised.exception))
        self.assertNotIn('private-hook-token', str(raised.exception))
        self.assertNotIn('private-github-token', str(raised.exception))

    def test_json_reads_keep_authentication_headers_and_use_explicit_client_identity(self):
        response = mock.MagicMock()
        response.__enter__.return_value.read.return_value = b'{}'
        headers = {'Accept': 'application/vnd.github+json', 'Authorization': 'Bearer private-github-token'}
        with mock.patch.object(trigger.urllib.request, 'urlopen', return_value=response) as send:
            self.assertEqual(trigger.read_json('https://api.github.com/example', headers), {})
        request = send.call_args.args[0]
        self.assertEqual(request.get_method(), 'GET')
        self.assertEqual(request.get_header('Accept'), headers['Accept'])
        self.assertEqual(request.get_header('Authorization'), headers['Authorization'])
        self.assertEqual(request.get_header('User-agent'), 'SiteToSpend-Deployment/1.0')

    def test_activation_receipt_poll_and_health_check_have_explicit_client_identity(self):
        good = run()
        receipt = dict(repository=trigger.REPOSITORY, workflow=trigger.WORKFLOW,
                       source_sha=good['head_sha'], run_id=good['id'], run_attempt=1)
        health_response = mock.MagicMock()
        with mock.patch.object(trigger, 'read_json', return_value=receipt) as read, \
                mock.patch.object(trigger.urllib.request, 'urlopen', return_value=health_response) as send, \
                mock.patch('builtins.print'):
            trigger.verify_activation(good)
        self.assertIn('/build/deployment.json?', read.call_args.args[0])
        self.assertEqual(read.call_args.args[1]['Cache-Control'], 'no-cache')
        request = send.call_args.args[0]
        self.assertEqual(request.full_url, trigger.SITE + '/up')
        self.assertEqual(request.get_method(), 'GET')
        self.assertEqual(request.get_header('Accept'), 'application/json')
        self.assertEqual(request.get_header('User-agent'), 'SiteToSpend-Deployment/1.0')

    def test_only_successful_trusted_master_push_can_use_hook(self):
        good = run()
        self.assertTrue(trigger.trusted_run(good))
        for field, value in [('head_repository', {'full_name': 'attacker/fork'}),
                             ('head_branch', 'feature'), ('event', 'pull_request'),
                             ('path', '.github/workflows/other.yml'), ('status', 'in_progress'),
                             ('conclusion', 'failure'), ('head_sha', 'main'),
                             ('id', None), ('run_attempt', 0)]:
            with self.subTest(field=field):
                self.assertFalse(trigger.trusted_run(dict(good, **{field: value})))

    def test_failed_rerun_supersedes_old_success_and_other_events_do_not(self):
        good = run()
        failed = dict(good, run_attempt=2, conclusion='failure')
        unrelated = dict(good, id=999, event='pull_request')
        self.assertEqual(failed, trigger.latest_run([good, failed, unrelated], good['head_sha']))

    def test_activation_receipt_requires_exact_source_and_run_attempt(self):
        good = run()
        receipt = dict(repository=trigger.REPOSITORY, workflow=trigger.WORKFLOW,
                       source_sha=good['head_sha'], run_id=good['id'], run_attempt=1)
        self.assertTrue(trigger.receipt_matches(receipt, good))
        for field, value in [('source_sha', 'b' * 40), ('run_id', 999), ('run_attempt', 2),
                             ('repository', 'other/repo'), ('workflow', 'other.yml')]:
            with self.subTest(field=field):
                self.assertFalse(trigger.receipt_matches(dict(receipt, **{field: value}), good))


if __name__ == '__main__':
    unittest.main()
