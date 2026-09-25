#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
mkdir -p "${ROOT_DIR}/build/native"

cc -std=c11 -Wall -Wextra -Werror \
  -I"${ROOT_DIR}/runtime" \
  "${ROOT_DIR}/native/jinx_zend_statement_ir_smoke.c" \
  "${ROOT_DIR}/runtime/jinx_zend_engine.c" \
  -o "${ROOT_DIR}/build/native/jinx-zend-statement-ir-smoke"

echo "Built ${ROOT_DIR}/build/native/jinx-zend-statement-ir-smoke"
