import importlib.machinery
import importlib.util
import pathlib
import unittest

path = pathlib.Path(__file__).resolve().parents[2] / 'bin/verify-deploy-commit'
loader = importlib.machinery.SourceFileLoader('deploy_gate', str(path))
spec = importlib.util.spec_from_loader(loader.name, loader)
gate = importlib.util.module_from_spec(spec)
loader.exec_module(gate)


class DeployGateTests(unittest.TestCase):
    def test_requires_exact_sha_success_and_push_on_master(self):
        run = dict(id=1, head_sha='a'*40, head_branch='master', event='push',
                   path='.github/workflows/ci.yml', status='completed', conclusion='success')
        self.assertTrue(gate.passed([run], 'a'*40))
        self.assertFalse(gate.passed([run], 'b'*40))
        for field, value in [('event', 'pull_request'), ('status', 'in_progress'), ('conclusion', 'failure')]:
            self.assertFalse(gate.passed([{**run, field: value}], 'a'*40))
        self.assertFalse(gate.passed([run, {**run, 'id': 2, 'conclusion': 'failure'}], 'a'*40))


if __name__ == '__main__':
    unittest.main()
