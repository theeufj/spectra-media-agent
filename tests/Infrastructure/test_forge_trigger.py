import importlib.machinery
import importlib.util
import pathlib
import unittest

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
