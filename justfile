# Updates CHANGELOG.md, then commits and tags the new version (pushing is left to be done by hand, once checked).
release:
    #!/usr/bin/env bash
    set -euo pipefail
    # The release commit must only hold the changelog: changes in progress would be released without being reviewed.
    if ! git diff --quiet || ! git diff --cached --quiet; then
        echo "The working tree has uncommitted changes, commit or stash them first." >&2
        exit 1
    fi
    VERSION=$(git cliff --bumped-version)
    git cliff --bump -o CHANGELOG.md
    git add CHANGELOG.md
    git commit -m "chore(release): $VERSION"
    git tag "$VERSION"
