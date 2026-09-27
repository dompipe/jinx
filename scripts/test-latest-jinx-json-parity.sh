#!/usr/bin/env bash
set -Eeuo pipefail

REPO_URL="${JINX_REPO_URL:-https://github.com/dompipe/jinx.git}"
WORKDIR="${JINX_PARITY_WORKDIR:-${TMPDIR:-/tmp}/jinx-parity-fresh}"
KEEP_WORKDIR="${JINX_PARITY_KEEP:-0}"

log()  { printf '\n==> %s\n' "$*"; }
pass() { printf '\nPASS: %s\n' "$*"; }
fail() { printf '\nFAIL: %s\n' "$*" >&2; exit 1; }

cleanup() {
    if [[ "$KEEP_WORKDIR" != "1" && -d "$WORKDIR" ]]; then
        rm -rf "$WORKDIR"
    fi
}
trap cleanup EXIT

command -v git >/dev/null 2>&1 || fail "git is required"
command -v php >/dev/null 2>&1 || fail "php is required"
command -v gcc >/dev/null 2>&1 || fail "gcc is required"

PHP_VERSION="$(php -r 'echo PHP_VERSION;')"
GCC_VERSION="$(gcc --version | head -n1)"
GIT_VERSION="$(git --version)"

log "Toolchain"
printf 'git: %s\nphp: %s\ngcc: %s\n' "$GIT_VERSION" "$PHP_VERSION" "$GCC_VERSION"

log "Preparing clean checkout"
rm -rf "$WORKDIR"
git clone --no-checkout "$REPO_URL" "$WORKDIR"
cd "$WORKDIR"

git fetch --prune origin '+refs/heads/*:refs/remotes/origin/*'

if git show-ref --verify --quiet refs/remotes/origin/main; then
    BRANCH="main"
elif git show-ref --verify --quiet refs/remotes/origin/master; then
    BRANCH="master"
else
    fail "Neither origin/main nor origin/master exists"
fi

git checkout -B "$BRANCH" "origin/$BRANCH"
git reset --hard "origin/$BRANCH"
git clean -ffd

COMMIT_SHA="$(git rev-parse HEAD)"
COMMIT_SHORT="$(git rev-parse --short=12 HEAD)"
COMMIT_SUBJECT="$(git log -1 --pretty=%s)"

log "Testing fresh origin/$BRANCH"
printf 'commit: %s\nsubject: %s\nrepo: %s\n' "$COMMIT_SHA" "$COMMIT_SUBJECT" "$REPO_URL"

log "Building native Jinx"
./scripts/build-native-jinx.sh

[[ -x ./jinx ]] || fail "./jinx was not produced by the native build"

log "Basic native smoke"
./jinx oracle-smoke
./build/native/jinx-zend-smoke

log "JSON declaration parity"
# These are PHP oracle harnesses: run them with PHP so they independently
# compute PHP behavior and invoke ./jinx only for the Jinx side.
php scripts/test-native-pure-core-oracle-asm.php
php scripts/test-native-zend-array-core-oracle-asm.php

log "Full registered Jinx parity/test suite"
php scripts/test-bin-jinx.php

if [[ -f scripts/test-jinx-native-suite.php ]]; then
    log "Native suite"
    php scripts/test-jinx-native-suite.php
fi

log "Generated-function smoke"
./jinx functions-smoke

pass "Fresh origin/$BRANCH at $COMMIT_SHORT passed JSON parity and the registered native Jinx test suites"

printf '\nTested commit:\n  %s\n\n' "$COMMIT_SHA"

if [[ "$KEEP_WORKDIR" == "1" ]]; then
    printf 'Checkout kept at:\n  %s\n' "$WORKDIR"
fi
