#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="${ROOT_DIR}/build/native"
mkdir -p "${OUT_DIR}"

cc -std=c11 -Wall -Wextra -pedantic \
    -I"${ROOT_DIR}/runtime" \
    -I"${ROOT_DIR}/build/oracle-asm" \
    "${ROOT_DIR}/native/jinx_zend_ir_fixture_smoke.c" \
    "${ROOT_DIR}/runtime/jinx_zend_engine.c" \
    "${ROOT_DIR}/runtime/jinx_oracle_frame_context.c" \
    -o "${OUT_DIR}/jinx-zend-ir-fixture-smoke"

echo "Built ${OUT_DIR}/jinx-zend-ir-fixture-smoke"
