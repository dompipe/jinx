# Oracle execution commands

Oracle is the PHP/Zend mirroring and execution layer. PASM/native output stays secondary until the Oracle layer proves behavior against PHP.

## Current executable Oracle families

Every family listed here is executable only because it has a runtime owner and a PHP parity comparison test. The full native suite also runs a coverage audit that checks this ledger, the parity tests, the native suite wiring, and this document stay synchronized.

### Core language/runtime families

| Family | Runtime owner | PHP comparison test |
|---|---|---|
| `straight-line` | `runtime/OracleStraightLineExecutor.php` | `scripts/test-oracle-straightline-execution.php` |
| `conditionals` | `runtime/OracleConditionalExecutor.php` | `scripts/test-oracle-conditional-execution.php` |
| `loops` | `runtime/OracleLoopExecutor.php` | `scripts/test-oracle-loop-execution.php` |
| `arrays` | `runtime/OracleArrayExecutor.php` | `scripts/test-oracle-array-execution.php` |
| `functions` | `runtime/OracleFunctionExecutor.php` | `scripts/test-oracle-function-execution.php` |
| `request-globals` | `runtime/OracleRequestExecutor.php` | `scripts/test-oracle-request-globals-execution.php` |
| `include-require` | `runtime/OracleIncludeExecutor.php` | `scripts/test-oracle-include-require-execution.php` |
| `exit-die` | `runtime/OracleExitExecutor.php` | `scripts/test-oracle-exit-die-execution.php` |
| `object-basics` | `runtime/OracleObjectExecutor.php` | `scripts/test-oracle-object-basics-execution.php` |
| `object-inheritance` | `runtime/OracleObjectInheritanceExecutor.php` | `scripts/test-oracle-object-inheritance-execution.php` |

### Expression/control-flow batch

| Family | Runtime owner | PHP comparison test |
|---|---|---|
| `ternary-expressions` | `runtime/OracleExpressionBatchExecutor.php` | `scripts/test-oracle-next-ten-execution.php` |
| `type-casts` | `runtime/OracleExpressionBatchExecutor.php` | `scripts/test-oracle-next-ten-execution.php` |
| `string-builtins` | `runtime/OracleExpressionBatchExecutor.php` | `scripts/test-oracle-next-ten-execution.php` |
| `math-builtins` | `runtime/OracleExpressionBatchExecutor.php` | `scripts/test-oracle-next-ten-execution.php` |
| `comparison-expressions` | `runtime/OracleExpressionBatchExecutor.php` | `scripts/test-oracle-next-ten-execution.php` |
| `boolean-expressions` | `runtime/OracleExpressionBatchExecutor.php` | `scripts/test-oracle-next-ten-execution.php` |
| `magic-constants` | `runtime/OracleExpressionBatchExecutor.php` | `scripts/test-oracle-next-ten-execution.php` |
| `array-literals` | `runtime/OracleExpressionBatchExecutor.php` | `scripts/test-oracle-next-ten-execution.php` |
| `foreach-loops` | `runtime/OracleExpressionBatchExecutor.php` | `scripts/test-oracle-next-ten-execution.php` |
| `for-loops` | `runtime/OracleExpressionBatchExecutor.php` | `scripts/test-oracle-next-ten-execution.php` |

### Builtin batch one

| Family | Runtime owner | PHP comparison test |
|---|---|---|
| `str-replace-builtins` | `runtime/OracleBuiltinBatchExecutor.php` | `scripts/test-oracle-builtin-batch-execution.php` |
| `strpos-builtins` | `runtime/OracleBuiltinBatchExecutor.php` | `scripts/test-oracle-builtin-batch-execution.php` |
| `explode-builtins` | `runtime/OracleBuiltinBatchExecutor.php` | `scripts/test-oracle-builtin-batch-execution.php` |
| `in-array-builtins` | `runtime/OracleBuiltinBatchExecutor.php` | `scripts/test-oracle-builtin-batch-execution.php` |
| `array-key-exists-builtins` | `runtime/OracleBuiltinBatchExecutor.php` | `scripts/test-oracle-builtin-batch-execution.php` |
| `array-merge-builtins` | `runtime/OracleBuiltinBatchExecutor.php` | `scripts/test-oracle-builtin-batch-execution.php` |
| `array-reverse-builtins` | `runtime/OracleBuiltinBatchExecutor.php` | `scripts/test-oracle-builtin-batch-execution.php` |
| `array-unique-builtins` | `runtime/OracleBuiltinBatchExecutor.php` | `scripts/test-oracle-builtin-batch-execution.php` |
| `json-encode-builtins` | `runtime/OracleBuiltinBatchExecutor.php` | `scripts/test-oracle-builtin-batch-execution.php` |
| `hash-builtins` | `runtime/OracleBuiltinBatchExecutor.php` | `scripts/test-oracle-builtin-batch-execution.php` |

### Builtin batch two

| Family | Runtime owner | PHP comparison test |
|---|---|---|
| `ltrim-builtins` | `runtime/OracleBuiltinBatchExecutor.php` | `scripts/test-oracle-builtin-batch-two-execution.php` |
| `rtrim-builtins` | `runtime/OracleBuiltinBatchExecutor.php` | `scripts/test-oracle-builtin-batch-two-execution.php` |
| `ucfirst-builtins` | `runtime/OracleBuiltinBatchExecutor.php` | `scripts/test-oracle-builtin-batch-two-execution.php` |
| `lcfirst-builtins` | `runtime/OracleBuiltinBatchExecutor.php` | `scripts/test-oracle-builtin-batch-two-execution.php` |
| `strrev-builtins` | `runtime/OracleBuiltinBatchExecutor.php` | `scripts/test-oracle-builtin-batch-two-execution.php` |
| `str-repeat-builtins` | `runtime/OracleBuiltinBatchExecutor.php` | `scripts/test-oracle-builtin-batch-two-execution.php` |
| `str-pad-builtins` | `runtime/OracleBuiltinBatchExecutor.php` | `scripts/test-oracle-builtin-batch-two-execution.php` |
| `array-keys-builtins` | `runtime/OracleBuiltinBatchExecutor.php` | `scripts/test-oracle-builtin-batch-two-execution.php` |
| `array-values-builtins` | `runtime/OracleBuiltinBatchExecutor.php` | `scripts/test-oracle-builtin-batch-two-execution.php` |
| `array-slice-builtins` | `runtime/OracleBuiltinBatchExecutor.php` | `scripts/test-oracle-builtin-batch-two-execution.php` |

### Scalar/type builtin batch

| Family | Runtime owner | PHP comparison test |
|---|---|---|
| `is-string-builtins` | `runtime/OracleScalarBuiltinExecutor.php` | `scripts/test-oracle-scalar-builtin-execution.php` |
| `is-int-builtins` | `runtime/OracleScalarBuiltinExecutor.php` | `scripts/test-oracle-scalar-builtin-execution.php` |
| `is-array-builtins` | `runtime/OracleScalarBuiltinExecutor.php` | `scripts/test-oracle-scalar-builtin-execution.php` |
| `is-bool-builtins` | `runtime/OracleScalarBuiltinExecutor.php` | `scripts/test-oracle-scalar-builtin-execution.php` |
| `is-null-builtins` | `runtime/OracleScalarBuiltinExecutor.php` | `scripts/test-oracle-scalar-builtin-execution.php` |
| `intval-builtins` | `runtime/OracleScalarBuiltinExecutor.php` | `scripts/test-oracle-scalar-builtin-execution.php` |
| `strval-builtins` | `runtime/OracleScalarBuiltinExecutor.php` | `scripts/test-oracle-scalar-builtin-execution.php` |
| `boolval-builtins` | `runtime/OracleScalarBuiltinExecutor.php` | `scripts/test-oracle-scalar-builtin-execution.php` |
| `floatval-builtins` | `runtime/OracleScalarBuiltinExecutor.php` | `scripts/test-oracle-scalar-builtin-execution.php` |
| `is-numeric-builtins` | `runtime/OracleScalarBuiltinExecutor.php` | `scripts/test-oracle-scalar-builtin-execution.php` |

### App builtin batch

| Family | Runtime owner | PHP comparison test |
|---|---|---|
| `str-contains-builtins` | `runtime/OracleAppBuiltinExecutor.php` | `scripts/test-oracle-app-builtin-execution.php` |
| `str-starts-with-builtins` | `runtime/OracleAppBuiltinExecutor.php` | `scripts/test-oracle-app-builtin-execution.php` |
| `str-ends-with-builtins` | `runtime/OracleAppBuiltinExecutor.php` | `scripts/test-oracle-app-builtin-execution.php` |
| `stripos-builtins` | `runtime/OracleAppBuiltinExecutor.php` | `scripts/test-oracle-app-builtin-execution.php` |
| `strrpos-builtins` | `runtime/OracleAppBuiltinExecutor.php` | `scripts/test-oracle-app-builtin-execution.php` |
| `strstr-builtins` | `runtime/OracleAppBuiltinExecutor.php` | `scripts/test-oracle-app-builtin-execution.php` |
| `substr-count-builtins` | `runtime/OracleAppBuiltinExecutor.php` | `scripts/test-oracle-app-builtin-execution.php` |
| `wordwrap-builtins` | `runtime/OracleAppBuiltinExecutor.php` | `scripts/test-oracle-app-builtin-execution.php` |
| `sprintf-builtins` | `runtime/OracleAppBuiltinExecutor.php` | `scripts/test-oracle-app-builtin-execution.php` |
| `number-format-builtins` | `runtime/OracleAppBuiltinExecutor.php` | `scripts/test-oracle-app-builtin-execution.php` |
| `array-combine-builtins` | `runtime/OracleAppBuiltinExecutor.php` | `scripts/test-oracle-app-builtin-execution.php` |
| `array-flip-builtins` | `runtime/OracleAppBuiltinExecutor.php` | `scripts/test-oracle-app-builtin-execution.php` |
| `array-diff-builtins` | `runtime/OracleAppBuiltinExecutor.php` | `scripts/test-oracle-app-builtin-execution.php` |
| `array-intersect-builtins` | `runtime/OracleAppBuiltinExecutor.php` | `scripts/test-oracle-app-builtin-execution.php` |
| `array-search-builtins` | `runtime/OracleAppBuiltinExecutor.php` | `scripts/test-oracle-app-builtin-execution.php` |
| `array-column-builtins` | `runtime/OracleAppBuiltinExecutor.php` | `scripts/test-oracle-app-builtin-execution.php` |
| `array-chunk-builtins` | `runtime/OracleAppBuiltinExecutor.php` | `scripts/test-oracle-app-builtin-execution.php` |
| `range-builtins` | `runtime/OracleAppBuiltinExecutor.php` | `scripts/test-oracle-app-builtin-execution.php` |
| `array-change-key-case-builtins` | `runtime/OracleAppBuiltinExecutor.php` | `scripts/test-oracle-app-builtin-execution.php` |
| `array-fill-builtins` | `runtime/OracleAppBuiltinExecutor.php` | `scripts/test-oracle-app-builtin-execution.php` |

## Native `./jinx` verification

Run the native verification suite from a clean local checkout after pulling `master`. Do not run each executable-family test separately unless debugging a specific failure; the suite runs each family once with labeled output.

```bash
git pull origin master
./scripts/build-native-jinx.sh
./jinx scripts/test-jinx-native-suite.php
git diff --check
```

For a focused failure rerun, launch the specific comparison test through `./jinx`, not `php scripts/...`:

```bash
./jinx scripts/test-oracle-object-inheritance-execution.php
```

The family execution tests compare Oracle execution to PHP/Zend behavior for captured output, returned value, thrown error status/message class shape, and exit behavior where applicable. Do not claim a PHP/Zend behavior is executable until an Oracle runtime owner actually runs it and a PHP comparison test proves parity for that family.
