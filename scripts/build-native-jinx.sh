#!/usr/bin/env sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
CC_BIN="${CC:-gcc}"
OUT_DIR="${ROOT_DIR}/build/native"
OUT="${ROOT_DIR}/jinx"
COPY_OUT="${OUT_DIR}/jinx"
ZEND_SMOKE_OUT="${OUT_DIR}/jinx-zend-smoke"

mkdir -p "$OUT_DIR"

php "${ROOT_DIR}/scripts/audit-oracle-dispatch-duplicates.php" \
    "${ROOT_DIR}/build/oracle-asm/oracle_asm_index.json"

php "${ROOT_DIR}/scripts/generate-oracle-dispatch-table.php" \
    "${ROOT_DIR}/build/oracle-asm/oracle_asm_index.json" \
    "${ROOT_DIR}/runtime/jinx_builtin_dispatch.generated.c"

php "${ROOT_DIR}/scripts/generate-native-core-metadata.php" \
    "${ROOT_DIR}/runtime/jinx_native_core_metadata.generated.h"

"$CC_BIN" \
    -std=c11 \
    -O2 \
    -Wall \
    -Wextra \
    -I"${ROOT_DIR}/runtime" \
    -I"${ROOT_DIR}/build/oracle-asm" \
    -include "${ROOT_DIR}/runtime/jinx_php_manual_manifest.h" \
    "${ROOT_DIR}/native/jinx_cli_with_web_plan.c" \
    "${ROOT_DIR}/runtime/jinx_zend_engine.c" \
    "${ROOT_DIR}/runtime/jinx_oracle_asm_context.c" \
    "${ROOT_DIR}/runtime/jinx_oracle_extended_builtins.c" \
    "${ROOT_DIR}/runtime/jinx_builtin_dispatch.generated.c" \
    "${ROOT_DIR}/runtime/jinx_pasm_machine.c" \
    -lm \
    -lz \
    -o "$OUT"

"$CC_BIN" \
    -std=c11 \
    -O2 \
    -Wall \
    -Wextra \
    -I"${ROOT_DIR}/runtime" \
    "${ROOT_DIR}/native/jinx_zend_smoke.c" \
    "${ROOT_DIR}/runtime/jinx_zend_engine.c" \
    -o "$ZEND_SMOKE_OUT"

cp "$OUT" "$COPY_OUT"

"$OUT" functions-smoke >/dev/null

STRLOWER_SMOKE=$("$OUT" oracle-call strtolower s:JiNx)
if [ "$STRLOWER_SMOKE" != "string:jinx" ]; then
    echo "FAIL: post-build oracle-call strtolower smoke expected string:jinx, got: $STRLOWER_SMOKE" >&2
    exit 1
fi

STRTOUPPER_SMOKE=$("$OUT" oracle-call strtoupper s:JiNx)
if [ "$STRTOUPPER_SMOKE" != "string:JINX" ]; then
    echo "FAIL: post-build oracle-call strtoupper smoke expected string:JINX, got: $STRTOUPPER_SMOKE" >&2
    exit 1
fi

WEB_PLAN_SMOKE=$("$OUT" web-plan "${ROOT_DIR}/fixtures/simple-web-api-validated.php")
case "$WEB_PLAN_SMOKE" in
    *WEB_IF_MISSING_ARRAY_KEY*) ;;
    *)
        echo "FAIL: post-build web-plan smoke did not contain WEB_IF_MISSING_ARRAY_KEY" >&2
        exit 1
        ;;
esac

echo "Built native JINX CLI: $OUT"
echo "Copied native JINX CLI: $COPY_OUT"
echo "Built native Zend smoke: $ZEND_SMOKE_OUT"
echo "Manual manifest compiled: runtime/jinx_php_manual_manifest.h"
echo "Zend skeleton compiled: runtime/jinx_zend_engine.c"
echo "Oracle dispatch duplicate audit: PASS"
echo "Oracle dispatch regenerated: runtime/jinx_builtin_dispatch.generated.c"
echo "Native class/constant metadata regenerated: runtime/jinx_native_core_metadata.generated.h"
echo "Extended procedural Oracle backend compiled: runtime/jinx_oracle_extended_builtins.c"
echo "Native functions-smoke: PASS"
echo "Native oracle-call smoke: strtolower/strtoupper PASS"
echo "Native web-plan smoke: PASS"
echo "Try: ./jinx oracle-smoke"
echo "Try: ./build/native/jinx-zend-smoke"
