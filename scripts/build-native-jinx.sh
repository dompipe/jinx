#!/usr/bin/env sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
CC_BIN="${CC:-gcc}"
OUT_DIR="${ROOT_DIR}/build/native"
OUT="${ROOT_DIR}/jinx"
COPY_OUT="${OUT_DIR}/jinx"

mkdir -p "$OUT_DIR"

"$CC_BIN" \
    -std=c11 \
    -O2 \
    -Wall \
    -Wextra \
    -I"${ROOT_DIR}/runtime" \
    -I"${ROOT_DIR}/build/oracle-asm" \
    -include "${ROOT_DIR}/runtime/jinx_php_manual_manifest.h" \
    "${ROOT_DIR}/native/jinx_cli.c" \
    "${ROOT_DIR}/runtime/jinx_oracle_asm_context.c" \
    "${ROOT_DIR}/runtime/jinx_builtin_dispatch.generated.c" \
    "${ROOT_DIR}/runtime/jinx_pasm_machine.c" \
    -lm \
    -o "$OUT"

cp "$OUT" "$COPY_OUT"

echo "Built native JINX CLI: $OUT"
echo "Copied native JINX CLI: $COPY_OUT"
echo "Manual manifest compiled: runtime/jinx_php_manual_manifest.h"
echo "Try: ./jinx oracle-smoke"
