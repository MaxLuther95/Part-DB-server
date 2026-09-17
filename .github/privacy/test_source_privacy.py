#!/usr/bin/env python3
"""Regression checks using disposable repositories and synthetic contents only."""

import pathlib
import subprocess
import tempfile
import unittest

SCRIPT = pathlib.Path(__file__).with_name("check_source_privacy.py").resolve()


class SourcePrivacyTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = pathlib.Path(self.temp.name)
        self.git("init", "-q")

    def tearDown(self):
        self.temp.cleanup()

    def git(self, *args):
        return subprocess.check_output(["git", *args], cwd=self.root, stderr=subprocess.DEVNULL)

    def stage(self, name, contents):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(contents)
        self.git("add", "--", name)
        return path

    def check(self, *args):
        return subprocess.run(["python3", str(SCRIPT), *args], cwd=self.root, capture_output=True)

    def test_regular_source_passes(self):
        self.stage("src/example.php", b"<?php echo 'Example';\n")
        self.assertEqual(self.check("--staged").returncode, 0)

    def test_force_added_runtime_file_fails_without_logging_its_name(self):
        self.stage("public_media/private-example.csv", b"SYNTHETIC-CUSTOMER-ONLY")
        result = self.check("--staged")
        self.assertEqual(result.returncode, 1)
        self.assertNotIn(b"private-example", result.stderr)
        self.assertNotIn(b"SYNTHETIC-CUSTOMER", result.stderr)

    def test_index_not_worktree_is_authoritative(self):
        path = self.stage("example.txt", b"SQLite format 3\x00synthetic")
        path.write_bytes(b"safe working copy")
        self.assertEqual(self.check("--staged").returncode, 1)

    def test_committed_tree_can_be_checked(self):
        self.stage("src/example.php", b"<?php\n")
        tree = self.git("write-tree").decode().strip()
        self.assertEqual(self.check("--ref", tree).returncode, 0)

    def test_symlink_to_private_file_is_rejected(self):
        (self.root / "source-link").symlink_to("/private/example")
        self.git("add", "source-link")
        self.assertEqual(self.check("--staged").returncode, 1)

    def test_private_environment_and_misnamed_database_are_rejected(self):
        self.stage(".env.mariadb.local", b"EXAMPLE=synthetic")
        self.stage("db-backup.txt", b"SQLite format 3\x00synthetic")
        result = self.check("--staged")
        self.assertEqual(result.returncode, 1)
        self.assertIn(b"private environment", result.stderr)
        self.assertIn(b"SQLite content", result.stderr)

    def test_synthetic_token_is_rejected_without_logging_it(self):
        token = b"ghp_" + b"A" * 30
        self.stage("example.txt", token)
        result = self.check("--staged")
        self.assertEqual(result.returncode, 1)
        self.assertNotIn(token, result.stderr)

    def test_replacing_allowlisted_fixture_is_rejected(self):
        self.stage(".github/assets/legacy_import/db_minimal.sql", b"SELECT 'synthetic replacement';")
        self.assertEqual(self.check("--staged").returncode, 1)

    def test_push_rejects_leak_in_intermediate_commit_even_after_removal(self):
        self.stage('src/example.php', b'<?php\n')
        self.git('-c', 'user.name=Synthetic', '-c', 'user.email=synthetic@example.invalid', 'commit', '-qm', 'Synthetic base')
        self.git('update-ref', 'refs/remotes/origin/main', 'HEAD')
        self.stage('var/synthetic.db', b'synthetic-only')
        self.git('-c', 'user.name=Synthetic', '-c', 'user.email=synthetic@example.invalid', 'commit', '-qm', 'Synthetic leak')
        self.git('rm', 'var/synthetic.db')
        self.git('-c', 'user.name=Synthetic', '-c', 'user.email=synthetic@example.invalid', 'commit', '-qm', 'Synthetic removal')
        tip = self.git('rev-parse', 'HEAD').decode().strip()
        result = subprocess.run(
            ['python3', str(SCRIPT.with_name('check_push_privacy.py'))], cwd=self.root,
            input=f'refs/heads/main {tip} refs/heads/main {"0" * 40}\n', text=True, capture_output=True,
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn('synthetic.db', result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
