#!/usr/bin/env sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
CC_BIN="${CC:-gcc}"
OUT_DIR="${ROOT_DIR}/build/native"
OUT="${OUT_DIR}/jinx-oracle-array-fixture-cli"

mkdir -p "$OUT_DIR"

php "${ROOT_DIR}/scripts/generate-oracle-dispatch-table.php" \
    "${ROOT_DIR}/build/oracle-asm/oracle_asm_index.json" \
    "${ROOT_DIR}/runtime/jinx_builtin_dispatch.generated.c"

"$CC_BIN" \
    -std=c11 \
    -O2 \
    -Wall \
    -Wextra \
    -I"${ROOT_DIR}/runtime" \
    -I"${ROOT_DIR}/build/oracle-asm" \
    "${ROOT_DIR}/native/jinx_oracle_array_fixture_cli.c" \
    "${ROOT_DIR}/runtime/jinx_zend_engine.c" \
    "${ROOT_DIR}/runtime/jinx_oracle_asm_context.c" \
    "${ROOT_DIR}/runtime/jinx_builtin_dispatch.generated.c" \
    -lm \
    -o "$OUT"

chmod +x "$OUT"

echo "Built Oracle array fixture CLI: $OUT"
echo "Try: ./build/native/jinx-oracle-array-fixture-cli oracle-call count za:deleted"
echo "Try: ./build/native/jinx-oracle-array-fixture-cli oracle-call array_key_exists s:name za:deleted"
echo "Try: ./build/native/jinx-oracle-array-fixture-cli oracle-call array_values za:deleted"
