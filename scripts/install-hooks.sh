#!/usr/bin/env bash
# Point git at the checked-in hooks directory so contributors share the same
# pre-commit checks (shader validation + PHPStan + PHPUnit).
#
# Run once per clone:
#   scripts/install-hooks.sh

set -euo pipefail
ROOT="$(git rev-parse --show-toplevel)"
git -C "$ROOT" config core.hooksPath .githooks
echo "git core.hooksPath -> .githooks"
echo "active hooks:"
ls "$ROOT/.githooks"
