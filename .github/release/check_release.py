#!/usr/bin/env python3
"""Fail closed on invalid releases, existing image tags or registry errors."""

import argparse
import base64
import json
import os
import re
import subprocess
import urllib.error
import urllib.parse
import urllib.request

IMAGES = (
    "ghcr.io/maxluther95/part-db-server",
    "ghcr.io/maxluther95/part-db-server-frankenphp",
)
VERSION = r"(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)"


def release_version(ref):
    match = re.fullmatch(r"refs/tags/production-v(" + VERSION + r")", ref)
    if not match:
        raise ValueError("Expected production-vMAJOR.MINOR.PATCH without leading zeros or suffixes")
    return match.group(1)


def ensure_unused(image, version):
    if image not in IMAGES or not re.fullmatch(VERSION, version):
        raise ValueError("Unexpected release image or version")
    repository = image.removeprefix("ghcr.io/")
    credentials = (os.environ["GHCR_USER"] + ":" + os.environ["GHCR_TOKEN"]).encode()
    query = urllib.parse.urlencode({"service": "ghcr.io", "scope": f"repository:{repository}:pull"})
    request = urllib.request.Request("https://ghcr.io/token?" + query, headers={
        "Authorization": "Basic " + base64.b64encode(credentials).decode(),
    })
    with urllib.request.urlopen(request, timeout=30) as response:
        token = json.load(response)["token"]
    request = urllib.request.Request(f"https://ghcr.io/v2/{repository}/manifests/{version}", headers={
        "Authorization": "Bearer " + token,
        "Accept": "application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.v2+json",
    })
    try:
        with urllib.request.urlopen(request, timeout=30):
            pass
    except urllib.error.HTTPError as error:
        # A transient registry/authentication error must never count as an unused tag.
        if error.code == 404:
            return
        raise RuntimeError(f"Registry check failed (HTTP {error.code})") from None
    raise RuntimeError(f"Image version {version} already exists; use a new version")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--image-only", action="store_true")
    args = parser.parse_args()
    if args.image_only:
        ensure_unused(os.environ["RELEASE_IMAGE"], os.environ["RELEASE_VERSION"])
        return
    version = release_version(os.environ["GITHUB_REF"])
    if os.environ["GITHUB_REPOSITORY"] != "MaxLuther95/Part-DB-server":
        raise ValueError("Unexpected repository")
    head = subprocess.check_output(["git", "rev-parse", "HEAD"]).decode().strip()
    if head != os.environ["GITHUB_SHA"]:
        raise ValueError("Checkout differs from the triggered commit")
    subprocess.run(["git", "merge-base", "--is-ancestor", head, "refs/remotes/origin/main"], check=True)
    for image in IMAGES:
        ensure_unused(image, version)
    with open(os.environ["GITHUB_OUTPUT"], "a", encoding="utf-8") as output:
        output.write(f"version={version}\n")
    print(f"Validated production version {version}")


if __name__ == "__main__":
    main()
