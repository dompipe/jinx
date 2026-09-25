#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="$ROOT/build/native/jinx-zend-variable-scalar-ir-smoke"
mkdir -p "$(dirname "$OUT")"
cc -std=c11 -Wall -Wextra -pedantic \
  "$ROOT/native/jinx_zend_variable_scalar_ir_smoke.c" \
  "$ROOT/runtime/jinx_zend_engine.c" \
  -o "$OUT"
chmod +x "$OUT"
echo "Built $OUT"
