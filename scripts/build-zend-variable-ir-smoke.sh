#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="$ROOT/build/native"
OUT="$OUT_DIR/jinx-zend-variable-ir-smoke"

mkdir -p "$OUT_DIR"

cc -std=c11 -Wall -Wextra -pedantic \
  -I"$ROOT/runtime" \
  "$ROOT/native/jinx_zend_variable_ir_smoke.c" \
  "$ROOT/runtime/jinx_zend_engine.c" \
  -o "$OUT"

chmod +x "$OUT"
echo "Built $OUT"
