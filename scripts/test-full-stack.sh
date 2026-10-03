#!/usr/bin/env sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT_DIR"

PHASE="startup"
START_TS=$(date +%s)

banner() {
    printf '\n============================================================\n'
    printf 'JINX FULL STACK: %s\n' "$1"
    printf '============================================================\n'
}

fail() {
    code=$?
    printf '\nFAIL: JINX full-stack verification stopped in phase: %s (exit=%s)\n' "$PHASE" "$code" >&2
    printf 'No success claim should be made until this command exits 0.\n' >&2
    exit "$code"
}
trap fail HUP INT TERM
trap 'code=$?; if [ "$code" -ne 0 ]; then printf "\nFAIL: JINX full-stack verification stopped in phase: %s (exit=%s)\n" "$PHASE" "$code" >&2; printf "No success claim should be made until this command exits 0.\n" >&2; fi' EXIT

command -v php >/dev/null 2>&1 || { echo "FAIL: php is required" >&2; exit 1; }
command -v gcc >/dev/null 2>&1 || { echo "FAIL: gcc is required" >&2; exit 1; }
command -v git >/dev/null 2>&1 || { echo "FAIL: git is required" >&2; exit 1; }

banner "1/8 SOURCE SANITY"
PHASE="source sanity"
git rev-parse --is-inside-work-tree >/dev/null
printf 'HEAD: %s\n' "$(git rev-parse HEAD)"
printf 'BRANCH: %s\n' "$(git rev-parse --abbrev-ref HEAD)"
git diff --check
printf 'PASS: source diff check\n'

banner "2/8 CLEAN NATIVE BUILD"
PHASE="native build"
./scripts/build-native-jinx.sh
test -x ./jinx
./jinx functions-smoke >/dev/null
printf 'PASS: repository-root ./jinx built and smoke-tested\n'

banner "3/8 COMPLETE NATIVE VERIFICATION SUITE"
PHASE="complete native suite"
./jinx scripts/test-jinx-native-suite.php
printf 'PASS: complete native verification suite\n'

banner "4/8 HIGH-VALUE PHP SEMANTIC EDGE SUITE"
PHASE="40-fixture semantic edge suite"
./jinx scripts/test-jinx-native-edge-suite.php
printf 'PASS: high-value semantic edge suite\n'

banner "5/8 DIRECT PHP VS JINX DIFFERENTIALS"
PHASE="PHP versus JINX differential"
./jinx scripts/test-php-jinx-differential.php
./jinx scripts/test-php-jinx-all-callables-differential.php
printf 'PASS: direct PHP/JINX differentials\n'

banner "6/8 ORACLE EXECUTION CLAIM AUDITS"
PHASE="Oracle execution claim audits"
./jinx scripts/test-oracle-full-coverage-audit.php
./jinx scripts/test-oracle-family-facet-audit.php
./jinx scripts/audit-oracle-family-facet-proof-sources.php --strict
printf 'PASS: Oracle execution claims have independent proof-source evidence\n'

banner "7/8 MODERN PHP + RECENT EXECUTION REGRESSIONS"
PHASE="modern PHP regressions"
./jinx scripts/test-oracle-modern-php-recording.php
./jinx scripts/test-oracle-function-execution.php
./jinx scripts/test-oracle-straightline-execution.php
./jinx scripts/test-oracle-static-property-execution.php
./jinx scripts/test-oracle-clone-execution.php
./jinx scripts/test-oracle-zend-declaration-execution.php
printf 'PASS: recent PHP 8+/object/function execution regressions\n'

banner "8/8 PHP VS JINX BENCHMARK SMOKE"
PHASE="separated benchmark smoke"
mkdir -p build/benchmarks
./jinx scripts/benchmark-oracle-separated.php \
    --engine=both \
    --iterations=3 \
    --warmup=1 \
    --json=build/benchmarks/full-stack-smoke.json
test -s build/benchmarks/full-stack-smoke.json
printf 'PASS: separated PHP/JINX benchmark smoke\n'

END_TS=$(date +%s)
ELAPSED=$((END_TS - START_TS))

trap - EXIT
printf '\n============================================================\n'
printf 'PASS: JINX FULL STACK VERIFIED\n'
printf 'HEAD: %s\n' "$(git rev-parse HEAD)"
printf 'Elapsed: %ss\n' "$ELAPSED"
printf 'Benchmark JSON: build/benchmarks/full-stack-smoke.json\n'
printf '============================================================\n'
