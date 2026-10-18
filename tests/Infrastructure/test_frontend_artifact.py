"""Deployment boundaries: exact CI provenance, public config and hostile archives."""
import copy
import importlib.machinery
import importlib.util
import io
import json
import pathlib
import tarfile
import tempfile
import unittest
from unittest import mock


path = pathlib.Path(__file__).resolve().parents[2] / 'bin/frontend-artifact'
loader = importlib.machinery.SourceFileLoader('frontend_artifact', str(path))
spec = importlib.util.spec_from_loader(loader.name, loader)
artifact = importlib.util.module_from_spec(spec)
loader.exec_module(artifact)

SHA = 'a' * 40
CONFIG = {'VITE_APP_NAME': 'Site to Spend', 'VITE_STRIPE_KEY': 'pk_test_publishable'}


def create_build(root):
    build = root / 'public/build'
    (build / 'assets').mkdir(parents=True)
    (build / 'assets/app.js').write_text('console.log("tested frontend");')
    (build / 'assets/app.css').write_text('body { color: red; }')
    (build / 'manifest.json').write_text(json.dumps({
        'resources/js/app.jsx': {'file': 'assets/app.js', 'css': ['assets/app.css'], 'isEntry': True},
    }))
    return build


def archive_bytes(members):
    output = io.BytesIO()
    with tarfile.open(fileobj=output, mode='w:gz') as bundle:
        for name, kind, content in members:
            member = tarfile.TarInfo(name)
            member.type = kind
            if kind == tarfile.REGTYPE:
                data = content.encode()
                member.size = len(data)
                bundle.addfile(member, io.BytesIO(data))
            else:
                member.linkname = content
                bundle.addfile(member)
    return output.getvalue()


class FrontendArtifactTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.base = pathlib.Path(self.temporary.name)
        self.source = self.base / 'source'
        self.build = create_build(self.source)
        self.output = self.base / 'artifacts'
        self.receipt = artifact.pack(self.source, self.output, SHA, 42, 1, CONFIG)
        self.deployment = self.base / 'release'
        existing = self.deployment / 'public/build'
        existing.mkdir(parents=True)
        (existing / 'keep-until-verified.txt').write_text('previous build')
        self.run = {
            'id': 42, 'run_attempt': 1, 'head_sha': SHA, 'head_branch': 'master',
            'head_repository': {'full_name': artifact.REPOSITORY}, 'event': 'push',
            'path': artifact.WORKFLOW, 'status': 'completed', 'conclusion': 'success',
        }
        self.workflow_path = f'actions/workflows/ci.yml/runs?head_sha={SHA}&event=push&per_page=100'
        self.tag = artifact.release_tag(SHA, 42, 1)
        self.responses = {
            'git/ref/heads/master': {'object': {'type': 'commit', 'sha': SHA}},
            self.workflow_path: {'workflow_runs': [self.run]},
            f'git/ref/tags/{self.tag}': {'object': {'type': 'commit', 'sha': SHA}},
        }
        assets = []
        self.downloads = {}
        for name in (artifact.ARCHIVE_NAME, artifact.RECEIPT_NAME):
            url = f'https://github.com/{artifact.REPOSITORY}/releases/download/{self.tag}/{name}'
            data = (self.output / name).read_bytes()
            self.downloads[url] = data
            assets.append({'name': name, 'state': 'uploaded', 'size': len(data), 'browser_download_url': url})
        self.responses[f'releases/tags/{self.tag}'] = {
            'tag_name': self.tag, 'draft': False, 'prerelease': True, 'assets': assets,
        }
        self.requests = []

    def api(self, path):
        self.requests.append(path)
        return copy.deepcopy(self.responses[path])

    def download(self, url, limit):
        self.assertLessEqual(len(self.downloads[url]), limit)
        return self.downloads[url]

    def install(self, configuration=CONFIG, api=None):
        return artifact.install(self.deployment, SHA, configuration, api or self.api, self.download)

    def assert_previous_build_preserved(self):
        self.assertEqual((self.deployment / 'public/build/keep-until-verified.txt').read_text(), 'previous build')
        self.assertFalse((self.deployment / 'public/build/deployment.json').exists())
        self.assertEqual(list((self.deployment / 'public').glob('.frontend-install-*')), [])

    def update_receipt(self, **changes):
        self.receipt.update(changes)
        url = f'https://github.com/{artifact.REPOSITORY}/releases/download/{self.tag}/{artifact.RECEIPT_NAME}'
        self.downloads[url] = artifact.canonical_json(self.receipt)

    def test_installs_tested_files_and_public_activation_receipt(self):
        receipt = self.install()
        build = self.deployment / 'public/build'
        self.assertEqual((build / 'assets/app.js').read_text(), 'console.log("tested frontend");')
        self.assertFalse((build / 'keep-until-verified.txt').exists())
        self.assertEqual(json.loads((build / 'deployment.json').read_text()), receipt)
        self.assertEqual(receipt['source_sha'], SHA)
        self.assertEqual((receipt['run_id'], receipt['run_attempt']), (42, 1))
        self.assertEqual(self.requests.count('git/ref/heads/master'), 2)
        self.assertEqual(self.requests.count(self.workflow_path), 2)

    def test_public_build_config_rejects_missing_secret_and_unlisted_inputs(self):
        invalid = [
            {**CONFIG, 'VITE_STRIPE_KEY': 'sk_live_secret'},
            {**CONFIG, 'VITE_STRIPE_KEY': ''},
            {'VITE_APP_NAME': CONFIG['VITE_APP_NAME']},
            {**CONFIG, 'STRIPE_SECRET': 'secret'},
        ]
        for configuration in invalid:
            with self.subTest(configuration_keys=list(configuration)):
                with self.assertRaises(artifact.ArtifactError):
                    artifact.config_fingerprint(configuration)

    def test_build_config_fingerprint_is_stable_without_exposing_values(self):
        reverse = dict(reversed(list(CONFIG.items())))
        self.assertEqual(artifact.config_fingerprint(CONFIG), artifact.config_fingerprint(reverse))
        text = (self.output / artifact.RECEIPT_NAME).read_text()
        self.assertNotIn(CONFIG['VITE_STRIPE_KEY'], text)
        self.assertNotIn(CONFIG['VITE_APP_NAME'], text)

    def test_pack_rejects_symbolic_links_and_reserved_receipt(self):
        (self.build / 'deployment.json').write_text('{}')
        with self.assertRaisesRegex(artifact.ArtifactError, 'reserved'):
            artifact.pack(self.source, self.output, SHA, 42, 1, CONFIG)
        (self.build / 'deployment.json').unlink()
        (self.build / 'assets/link.js').symlink_to(self.build / 'assets/app.js')
        with self.assertRaisesRegex(artifact.ArtifactError, 'link'):
            artifact.pack(self.source, self.output, SHA, 42, 1, CONFIG)

    def test_latest_failed_pending_or_cancelled_ci_blocks_old_success(self):
        for status, conclusion in [('completed', 'failure'), ('in_progress', None), ('completed', 'cancelled')]:
            with self.subTest(status=status, conclusion=conclusion):
                self.responses[self.workflow_path]['workflow_runs'] = [self.run, {
                    **self.run, 'run_attempt': 2, 'status': status, 'conclusion': conclusion,
                }]
                with self.assertRaisesRegex(artifact.ArtifactError, 'latest CI'):
                    self.install()
                self.assert_previous_build_preserved()

    def test_pull_requests_forks_other_workflows_and_wrong_sha_cannot_authorize_install(self):
        for change in [
            {'event': 'pull_request'}, {'head_branch': 'develop'},
            {'head_repository': {'full_name': 'attacker/fork'}}, {'head_repository': None},
            {'path': '.github/workflows/other.yml'}, {'head_sha': 'b' * 40},
        ]:
            with self.subTest(change=change):
                self.responses[self.workflow_path]['workflow_runs'] = [{**self.run, **change}]
                with self.assertRaisesRegex(artifact.ArtifactError, 'no matching CI'):
                    self.install()
                self.assert_previous_build_preserved()

    def test_stale_master_and_wrong_release_tag_block_install(self):
        self.responses['git/ref/heads/master']['object']['sha'] = 'b' * 40
        with self.assertRaisesRegex(artifact.ArtifactError, 'Master has moved'):
            self.install()
        self.responses['git/ref/heads/master']['object']['sha'] = SHA
        self.responses[f'git/ref/tags/{self.tag}']['object']['sha'] = 'b' * 40
        with self.assertRaisesRegex(artifact.ArtifactError, 'release tag'):
            self.install()
        self.assert_previous_build_preserved()

    def test_annotated_release_tag_is_dereferenced(self):
        tag_sha = 'b' * 40
        self.responses[f'git/ref/tags/{self.tag}']['object'] = {'type': 'tag', 'sha': tag_sha}
        self.responses[f'git/tags/{tag_sha}'] = {'object': {'type': 'commit', 'sha': SHA}}
        self.install()

    def test_wrong_receipt_or_public_config_blocks_install(self):
        for changes in [
            {'source_sha': 'b' * 40}, {'run_attempt': 2}, {'run_id': 43},
            {'repository': 'attacker/fork'}, {'workflow': 'untrusted.yml'},
            {'public_config_sha256': '0' * 64}, {'archive_sha256': 'invalid'},
        ]:
            with self.subTest(changes=changes):
                original = copy.deepcopy(self.receipt)
                self.update_receipt(**changes)
                with self.assertRaisesRegex(artifact.ArtifactError, 'receipt does not match'):
                    self.install()
                self.update_receipt(**original)
                self.assert_previous_build_preserved()
        with self.assertRaisesRegex(artifact.ArtifactError, 'receipt does not match'):
            self.install({**CONFIG, 'VITE_APP_NAME': 'A changed production setting'})

    def test_tampered_archive_is_not_extracted(self):
        url = f'https://github.com/{artifact.REPOSITORY}/releases/download/{self.tag}/{artifact.ARCHIVE_NAME}'
        self.downloads[url] += b'tampered'
        with self.assertRaisesRegex(artifact.ArtifactError, 'checksum'):
            self.install()
        self.assert_previous_build_preserved()

    def test_wrong_github_asset_digest_blocks_install(self):
        self.responses[f'releases/tags/{self.tag}']['assets'][0]['digest'] = 'sha256:' + '0' * 64
        with self.assertRaisesRegex(artifact.ArtifactError, 'GitHub asset metadata'):
            self.install()
        self.assert_previous_build_preserved()

    def test_external_asset_urls_duplicates_drafts_and_oversized_metadata_are_rejected(self):
        original = copy.deepcopy(self.responses[f'releases/tags/{self.tag}'])
        for change in ('external', 'duplicate', 'draft', 'oversized'):
            with self.subTest(change=change):
                release = copy.deepcopy(original)
                if change == 'external':
                    release['assets'][0]['browser_download_url'] = 'http://localhost/private'
                elif change == 'duplicate':
                    release['assets'].append(release['assets'][0])
                elif change == 'draft':
                    release['draft'] = True
                else:
                    release['assets'][0]['size'] = artifact.MAX_ARCHIVE_BYTES + 1
                self.responses[f'releases/tags/{self.tag}'] = release
                with self.assertRaises(artifact.ArtifactError):
                    self.install()
                self.assert_previous_build_preserved()

    def test_master_or_ci_changes_while_downloading_preserve_previous_build(self):
        for changed in ('master', 'ci'):
            with self.subTest(changed=changed):
                requests = []

                def raced_api(path):
                    requests.append(path)
                    if changed == 'master' and path == 'git/ref/heads/master' and requests.count(path) == 2:
                        return {'object': {'type': 'commit', 'sha': 'b' * 40}}
                    if changed == 'ci' and path == self.workflow_path and requests.count(path) == 2:
                        return {'workflow_runs': [{**self.run, 'run_attempt': 2}]}
                    return self.api(path)

                with self.assertRaises(artifact.ArtifactError):
                    self.install(api=raced_api)
                self.assert_previous_build_preserved()

    def test_unavailable_github_evidence_does_not_replace_existing_build(self):
        def unavailable(path):
            raise artifact.ArtifactError('GitHub unavailable')

        with self.assertRaisesRegex(artifact.ArtifactError, 'unavailable'):
            self.install(api=unavailable)
        self.assert_previous_build_preserved()

    def test_unsafe_archive_paths_links_special_files_and_duplicates_are_rejected(self):
        hostile = [
            [('../outside', tarfile.REGTYPE, 'bad')],
            [('/absolute', tarfile.REGTYPE, 'bad')],
            [('assets/../../outside', tarfile.REGTYPE, 'bad')],
            [('assets\\outside', tarfile.REGTYPE, 'bad')],
            [('C:/outside', tarfile.REGTYPE, 'bad')],
            [('assets/link', tarfile.SYMTYPE, '../outside')],
            [('assets/link', tarfile.LNKTYPE, '../outside')],
            [('device', tarfile.CHRTYPE, '')],
            [('same', tarfile.REGTYPE, 'a'), ('same', tarfile.REGTYPE, 'b')],
            [('assets', tarfile.REGTYPE, 'a'), ('assets/app.js', tarfile.REGTYPE, 'b')],
        ]
        for index, members in enumerate(hostile):
            with self.subTest(members=[(name, kind) for name, kind, _ in members]):
                archive = self.base / f'hostile-{index}.tar.gz'
                archive.write_bytes(archive_bytes(members))
                destination = self.base / f'extracted-{index}'
                destination.mkdir()
                with self.assertRaises(artifact.ArtifactError):
                    artifact.extract_archive(archive, destination)
                self.assertEqual(list(destination.iterdir()), [])
                self.assertFalse((self.base / 'outside').exists())

    def test_expansion_and_file_size_limits_reject_small_compressed_bombs(self):
        archive = self.base / 'bomb.tar.gz'
        archive.write_bytes(archive_bytes([('huge', tarfile.REGTYPE, 'x' * 8192)]))
        destination = self.base / 'bomb-output'
        destination.mkdir()
        with mock.patch.object(artifact, 'MAX_TAR_BYTES', 4096):
            with self.assertRaisesRegex(artifact.ArtifactError, 'expanded frontend archive'):
                artifact.extract_archive(archive, destination)
        with mock.patch.object(artifact, 'MAX_FILE_BYTES', 4096):
            with self.assertRaisesRegex(artifact.ArtifactError, 'file limits'):
                artifact.extract_archive(archive, destination)
        self.assertEqual(list(destination.iterdir()), [])

    def test_archive_member_count_total_size_and_corruption_are_bounded(self):
        archive = self.base / 'many.tar.gz'
        archive.write_bytes(archive_bytes([
            ('one', tarfile.REGTYPE, '123'), ('two', tarfile.REGTYPE, '456'),
        ]))
        destination = self.base / 'many-output'
        destination.mkdir()
        for constant, limit in [('MAX_MEMBERS', 1), ('MAX_FILES_BYTES', 4)]:
            with self.subTest(limit=constant), mock.patch.object(artifact, constant, limit):
                with self.assertRaisesRegex(artifact.ArtifactError, 'file limits'):
                    artifact.extract_archive(archive, destination)
                self.assertEqual(list(destination.iterdir()), [])
        archive.write_bytes(b'not a gzip archive')
        with self.assertRaisesRegex(artifact.ArtifactError, 'corrupt or incomplete'):
            artifact.extract_archive(archive, destination)

    def test_manifest_requires_existing_css_assets_and_dynamic_imports(self):
        original = json.loads((self.build / 'manifest.json').read_text())
        for change in [
            {'file': '../outside.js'}, {'file': 'assets/missing.js'},
            {'css': ['assets/missing.css']}, {'assets': ['assets/missing.png']},
            {'dynamicImports': ['missing-chunk']}, {'imports': ['missing-chunk']},
        ]:
            with self.subTest(change=change):
                manifest = copy.deepcopy(original)
                manifest['resources/js/app.jsx'].update(change)
                (self.build / 'manifest.json').write_text(json.dumps(manifest))
                with self.assertRaises(artifact.ArtifactError):
                    artifact.validate_manifest(self.build)


if __name__ == '__main__':
    unittest.main()
