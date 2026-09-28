#!/usr/bin/env sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
CC_BIN="${CC:-gcc}"
OUT_DIR="${ROOT_DIR}/build/native"
OUT="${OUT_DIR}/jinx-oracle-zend-array-carrier-smoke"

mkdir -p "$OUT_DIR"

"$CC_BIN" \
    -std=c11 \
    -O2 \
    -Wall \
    -Wextra \
    -I"${ROOT_DIR}/runtime" \
    -I"${ROOT_DIR}/build/oracle-asm" \
    "${ROOT_DIR}/native/jinx_oracle_zend_array_carrier_smoke.c" \
    "${ROOT_DIR}/runtime/jinx_zend_engine.c" \
    "${ROOT_DIR}/runtime/jinx_oracle_frame_context.c" \
    -o "$OUT"

chmod +x "$OUT"

echo "Built Oracle Zend array carrier smoke: $OUT"
echo "Try: ./build/native/jinx-oracle-zend-array-carrier-smoke"
