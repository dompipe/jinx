#!/usr/bin/env sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
CC_BIN="${CC:-gcc}"
OUT_DIR="${ROOT_DIR}/build/native"
OUT="${OUT_DIR}/jinx-zend-smoke"
ARRAY_BUILTIN_OUT="${OUT_DIR}/jinx-zend-array-builtin-smoke"
ARRAY_DELETE_OUT="${OUT_DIR}/jinx-zend-array-delete-smoke"

mkdir -p "$OUT_DIR"

"$CC_BIN" \
    -std=c11 \
    -O2 \
    -Wall \
    -Wextra \
    -I"${ROOT_DIR}/runtime" \
    "${ROOT_DIR}/native/jinx_zend_smoke.c" \
    "${ROOT_DIR}/runtime/jinx_zend_engine.c" \
    -o "$OUT"

"$CC_BIN" \
    -std=c11 \
    -O2 \
    -Wall \
    -Wextra \
    -I"${ROOT_DIR}/runtime" \
    "${ROOT_DIR}/native/jinx_zend_array_builtin_smoke.c" \
    "${ROOT_DIR}/runtime/jinx_zend_engine.c" \
    -o "$ARRAY_BUILTIN_OUT"

"$CC_BIN" \
    -std=c11 \
    -O2 \
    -Wall \
    -Wextra \
    -I"${ROOT_DIR}/runtime" \
    "${ROOT_DIR}/native/jinx_zend_array_delete_smoke.c" \
    "${ROOT_DIR}/runtime/jinx_zend_engine.c" \
    -o "$ARRAY_DELETE_OUT"

chmod +x "$OUT" "$ARRAY_BUILTIN_OUT" "$ARRAY_DELETE_OUT"

echo "Built native Zend smoke: $OUT"
echo "Built native Zend array builtin bridge smoke: $ARRAY_BUILTIN_OUT"
echo "Built native Zend array delete smoke: $ARRAY_DELETE_OUT"
echo "Try: ./build/native/jinx-zend-smoke"
echo "Try: ./build/native/jinx-zend-array-builtin-smoke"
echo "Try: ./build/native/jinx-zend-array-delete-smoke"
