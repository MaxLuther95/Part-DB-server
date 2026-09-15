#!/usr/bin/env python3
"""Reject runtime data and credentials in a Git tree or staged index.

Only Git object contents are read. No matching contents or filenames are logged.
This complements review; it cannot recognize all confidential business text.
"""

import argparse
import collections
import pathlib
import re
import subprocess
import sys

LEGACY_FIXTURES = {
    ".github/assets/legacy_import/db_jbtronics.sql": "5237461f3fc1d65f241107a84c21547d52bc139f",
    ".github/assets/legacy_import/db_minimal.sql": "7f29d5dcad3d523dd2bd5dd73aa753090978d630",
}


def git(*args):
    return subprocess.check_output(["git", *args])


def path_reason(path):
    parts = pathlib.PurePosixPath(path).parts
    first = parts[0]
    if first in {"var", "db", "docker-data", "development_backups", "public_media", ".tmp"}:
        return "runtime directory"
    if first.startswith("uploads.") or (first == "uploads" and path != "uploads/.keep"):
        return "runtime upload"
    if path.startswith("public/media/") and path not in {"public/media/.gitignore", "public/media/.htaccess"}:
        return "runtime media"
    if ".DS_Store" in parts or first == "CODEX_HANDOVER.md":
        return "local metadata or handover"
    if any(re.fullmatch(r"\.env\..*local(?:\..*)?", p) for p in parts):
        return "private environment"
    if any(p.startswith(".env") for p in parts) and path not in {
        ".env", ".env.dev", ".env.docker", ".env.test", ".env.mariadb.example"
    }:
        return "unreviewed environment file"
    if path.endswith(".decrypt.private.php"):
        return "private decryption key"
    if re.search(r"\.(?:sqlite3?|db)(?:\.(?:gz|zip|bak))?$", path, re.I):
        return "database file"
    # These two legacy SQL fixtures are part of upstream's import tests.
    if re.search(r"\.sql(?:\.(?:gz|zip|bak))?$", path, re.I) and path not in LEGACY_FIXTURES:
        return "database dump"
    return None


def content_reason(data):
    if data.startswith(b"SQLite format 3\x00"):
        return "SQLite content"
    if re.search(rb"-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----", data):
        return "private key"
    if re.search(rb"\b(?:gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{30,})\b", data):
        return "GitHub credential"
    if re.search(rb"\b(?:AKIA|ASIA)[A-Z0-9]{16}\b", data):
        return "AWS credential"
    return None


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--staged", action="store_true")
    group.add_argument("--ref", help="Review a committed Git tree, e.g. HEAD")
    args = parser.parse_args()
    if args.staged:
        entries = git("ls-files", "--stage", "-z").split(b"\0")
    else:
        # Resolve first so a ref cannot be interpreted as a command-line option.
        ref = git("rev-parse", "--verify", "--end-of-options", args.ref + "^{tree}").decode().strip()
        entries = git("ls-tree", "-r", "-z", ref).split(b"\0")
    reasons = collections.Counter()
    checked = 0
    for entry in entries:
        if not entry:
            continue
        metadata, raw_path = entry.split(b"\t", 1)
        path = raw_path.decode("utf-8", errors="surrogateescape")
        fields = metadata.split()
        if args.staged:
            mode, oid, stage = fields
            if stage != b"0":
                reasons["unmerged index"] += 1
                continue
        else:
            mode, kind, oid = fields
            if kind != b"blob":
                reasons["unreviewed submodule"] += 1
                continue
        if mode not in {b"100644", b"100755"}:
            reasons["non-regular source file"] += 1
            continue
        checked += 1
        reason = path_reason(path)
        if path in LEGACY_FIXTURES and oid.decode() != LEGACY_FIXTURES[path]:
            reason = "modified legacy database fixture requires review"
        if not reason:
            reason = content_reason(git("cat-file", "blob", oid.decode()))
        if reason:
            reasons[reason] += 1
    if reasons:
        print("Source privacy check FAILED. Do not publish this tree.", file=sys.stderr)
        for reason, count in sorted(reasons.items()):
            print(f"  {reason}: {count}", file=sys.stderr)
        print("Review the affected files locally; contents and filenames are intentionally not logged.", file=sys.stderr)
        return 1
    print(f"Source privacy check passed ({checked} files). Business-data review is still required.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
