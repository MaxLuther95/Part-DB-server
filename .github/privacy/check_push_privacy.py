#!/usr/bin/env python3
"""Check outgoing commit trees before Git sends objects, without printing contents."""

import pathlib
import re
import subprocess
import sys

CHECK = pathlib.Path(__file__).with_name('check_source_privacy.py')


def main():
    commits = set()
    for line in sys.stdin:
        _local_ref, local_sha, _remote_ref, _remote_sha = line.split()
        if re.fullmatch('0+', local_sha):
            continue  # Deleting a ref does not publish objects.
        if not re.fullmatch('[0-9a-f]{40,64}', local_sha):
            raise ValueError('Invalid outgoing Git object')
        tip = subprocess.check_output(['git', 'rev-parse', '--verify', local_sha + '^{commit}']).decode().strip()
        commits.add(tip)
        # Include intermediate new commits, even if a later commit removed a leak.
        commits.update(subprocess.check_output(['git', 'rev-list', tip, '--not', '--remotes']).decode().splitlines())
    for commit in sorted(commits):
        subprocess.run([sys.executable, str(CHECK), '--ref', commit], check=True)
    print(f'Outgoing source privacy checks passed ({len(commits)} commit trees).')


if __name__ == '__main__':
    main()
