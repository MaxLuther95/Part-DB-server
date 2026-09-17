#!/usr/bin/env python3
"""Release guard regression tests; no credentials, registry or company data needed."""

import io
import os
import unittest
import urllib.error
from unittest.mock import patch

import check_release


class ReleaseGuardTest(unittest.TestCase):
    def test_only_explicit_production_versions_are_accepted(self):
        self.assertEqual(check_release.release_version('refs/tags/production-v1.2.3'), '1.2.3')
        for ref in ('refs/heads/main', 'refs/tags/v1.2.3', 'refs/tags/production-v01.2.3',
                    'refs/tags/production-v1.2.3-rc.1', 'refs/tags/production-v1.2',
                    'refs/tags/production-v1.2.3\n'):
            with self.subTest(ref=ref), self.assertRaises(ValueError):
                check_release.release_version(ref)

    def test_existing_version_is_never_overwritten(self):
        with patch.dict(os.environ, GHCR_USER='synthetic', GHCR_TOKEN='synthetic'), \
                patch('urllib.request.urlopen', side_effect=[io.BytesIO(b'{"token":"synthetic"}'), io.BytesIO(b'{}')]), \
                self.assertRaisesRegex(RuntimeError, 'already exists'):
            check_release.ensure_unused(check_release.IMAGES[0], '1.2.3')

    def test_only_manifest_not_found_permits_publication(self):
        for status in (404, 401, 403, 429, 500, 503):
            with self.subTest(status=status), \
                    patch.dict(os.environ, GHCR_USER='synthetic', GHCR_TOKEN='synthetic'), \
                    patch('urllib.request.urlopen', side_effect=[
                        io.BytesIO(b'{"token":"synthetic"}'),
                        urllib.error.HTTPError('https://ghcr.io/synthetic', status, 'synthetic', {}, None),
                    ]):
                if status == 404:
                    check_release.ensure_unused(check_release.IMAGES[0], '1.2.3')
                else:
                    with self.assertRaises(RuntimeError):
                        check_release.ensure_unused(check_release.IMAGES[0], '1.2.3')

    def test_arbitrary_repository_and_version_are_rejected_before_network_access(self):
        with patch('urllib.request.urlopen') as request:
            for image, version in [('ghcr.io/other/image', '1.2.3'), (check_release.IMAGES[0], 'current')]:
                with self.assertRaises(ValueError):
                    check_release.ensure_unused(image, version)
            request.assert_not_called()


if __name__ == '__main__':
    unittest.main()
