#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="${ROOT_DIR}/build/native"
mkdir -p "${BUILD_DIR}"

cc -std=c11 -Wall -Wextra -pedantic \
  -I"${ROOT_DIR}/runtime" \
  "${ROOT_DIR}/native/jinx_zend_lowering_fixture_smoke.c" \
  "${ROOT_DIR}/runtime/jinx_zend_engine.c" \
  "${ROOT_DIR}/runtime/jinx_oracle_frame_context.c" \
  -o "${BUILD_DIR}/jinx-zend-lowering-fixture-smoke"

echo "Built ${BUILD_DIR}/jinx-zend-lowering-fixture-smoke"
