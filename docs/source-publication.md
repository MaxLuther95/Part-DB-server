# Publishing source without operational data

The public repository contains application code, migrations, documentation and
synthetic test fixtures. Database records, uploaded documents, local backups,
private environment files and operational handovers belong in protected local
storage, never in Git, CI artifacts or container images. Database migrations
describe the schema; they must not embed a copy of the installation's records.

Before committing, enable the shared local check in a fresh checkout:

```sh
git config core.hooksPath .githooks
```

The pre-commit hook checks the staged Git objects, including files force-added
despite `.gitignore`. Python 3 is required. The same check can inspect a commit:

```sh
python3 .github/privacy/check_source_privacy.py --ref HEAD
```

The check rejects runtime paths, database dumps, SQLite files and recognizable
private keys/tokens. It does not print matching contents or filenames in build
logs. It cannot identify every customer name, business record or secret; review
the actual diff before publishing. Upstream legacy import fixtures and other
synthetic test inputs are source assets, not backups of a running installation.

Only explicitly reviewed source files should be committed. Do not use a blanket
`git add .` on a working production installation. Builds should use a clean Git
checkout. Runtime credentials and volumes are supplied only when deploying.

If confidential data was already pushed, deleting the current file is not
enough. Review and clean affected historical refs, CI logs/artifacts/caches and
image layers. Old GitHub commit views can require removal by GitHub Support.
Keep the evidence and support correspondence outside the public repository.
