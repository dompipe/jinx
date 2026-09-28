#!/usr/bin/env sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
CC_BIN="${CC:-gcc}"
OUT_DIR="${ROOT_DIR}/build/native"
OUT="${OUT_DIR}/jinx-zend-method-opcode-smoke"

mkdir -p "$OUT_DIR"

"$CC_BIN" \
    -std=c11 \
    -O2 \
    -Wall \
    -Wextra \
    -I"${ROOT_DIR}/runtime" \
    "${ROOT_DIR}/native/jinx_zend_method_opcode_smoke.c" \
    "${ROOT_DIR}/runtime/jinx_zend_engine.c" \
    "${ROOT_DIR}/runtime/jinx_oracle_frame_context.c" \
    -o "$OUT"

echo "Built Zend method opcode smoke: $OUT"
