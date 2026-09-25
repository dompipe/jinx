# Oracle execution commands

Oracle is the PHP/Zend mirroring and execution layer. PASM/native output stays secondary until the Oracle layer proves behavior against PHP.

## Current executable Oracle families

| Family | Runtime owner | PHP comparison test | Scope |
|---|---|---|---|
| `straight-line` | `runtime/OracleStraightLineExecutor.php` | `scripts/test-oracle-straightline-execution.php` | assignments, dimension writes/fetches, coalesce, compound assignment, inc/dec, echo, print, return, `strlen`, `strtoupper` |
| `conditionals` | `runtime/OracleConditionalExecutor.php` | `scripts/test-oracle-conditional-execution.php` | narrow `if`/`else`, comparisons, `&&`, `||`, `!`, PHP-like truthiness, plus the straight-line operations needed inside branches |
| `loops` | `runtime/OracleLoopExecutor.php` | `scripts/test-oracle-loop-execution.php` | narrow `while` loops, `break`, `continue`, nested conditionals, plus the straight-line operations needed inside loop bodies |
| `arrays` | `runtime/OracleArrayExecutor.php` | `scripts/test-oracle-array-execution.php` | empty array literals, append writes, nested dimension assigns/fetches, `isset`, `empty`, `unset`, `count`, echo, print, return |
| `functions` | `runtime/OracleFunctionExecutor.php` | `scripts/test-oracle-function-execution.php` | named user functions, local parameter scope, return values, nested user calls, builtin dispatch for `strlen` and `strtoupper` |
| `request-globals` | `runtime/OracleRequestExecutor.php` | `scripts/test-oracle-request-globals-execution.php` | explicit Oracle request context for `$_SERVER`, `$_GET`, `$_POST`, `$_REQUEST`, coalesce, `isset`, `empty`, `count`, echo, print, return |
| `include-require` | `runtime/OracleIncludeExecutor.php` | `scripts/test-oracle-include-require-execution.php` | literal `include` and `require` edges resolved by OracleProgramCompiler, included local scope, included output, caller return parity |
| `exit-die` | `runtime/OracleExitExecutor.php` | `scripts/test-oracle-exit-die-execution.php` | `exit`/`die` termination, string output, exit status, terminated flag, and unreachable-code stopping behavior |
| `ternary-expressions` | `runtime/OracleExpressionBatchExecutor.php` | `scripts/test-oracle-next-ten-execution.php` | ternary `?:` expression execution and branch parity |
| `type-casts` | `runtime/OracleExpressionBatchExecutor.php` | `scripts/test-oracle-next-ten-execution.php` | `(int)`, `(string)`, `(bool)`, `(float)`, and `(array)` casts |
| `string-builtins` | `runtime/OracleExpressionBatchExecutor.php` | `scripts/test-oracle-next-ten-execution.php` | `strlen`, `strtoupper`, `strtolower`, `trim`, and `substr` |
| `math-builtins` | `runtime/OracleExpressionBatchExecutor.php` | `scripts/test-oracle-next-ten-execution.php` | `abs`, `max`, `min`, and `round` |
| `comparison-expressions` | `runtime/OracleExpressionBatchExecutor.php` | `scripts/test-oracle-next-ten-execution.php` | comparison operators including strict equality and spaceship `<=>` |
| `boolean-expressions` | `runtime/OracleExpressionBatchExecutor.php` | `scripts/test-oracle-next-ten-execution.php` | `&&`, `||`, `!`, and PHP-like truthiness |
| `magic-constants` | `runtime/OracleExpressionBatchExecutor.php` | `scripts/test-oracle-next-ten-execution.php` | `__FILE__`, `__DIR__`, and `PHP_VERSION` |
| `array-literals` | `runtime/OracleExpressionBatchExecutor.php` | `scripts/test-oracle-next-ten-execution.php` | list literals, associative literals, `count`, `implode`, and `array_sum` |
| `foreach-loops` | `runtime/OracleExpressionBatchExecutor.php` | `scripts/test-oracle-next-ten-execution.php` | narrow key/value `foreach` loops and body execution |
| `for-loops` | `runtime/OracleExpressionBatchExecutor.php` | `scripts/test-oracle-next-ten-execution.php` | narrow `for` init, condition, iteration, and body execution |
| `str-replace-builtins` | `runtime/OracleBuiltinBatchExecutor.php` | `scripts/test-oracle-builtin-batch-execution.php` | `str_replace` output and return parity |
| `strpos-builtins` | `runtime/OracleBuiltinBatchExecutor.php` | `scripts/test-oracle-builtin-batch-execution.php` | `strpos` integer result parity through PHP string concatenation |
| `explode-builtins` | `runtime/OracleBuiltinBatchExecutor.php` | `scripts/test-oracle-builtin-batch-execution.php` | `explode`, `implode`, and `count` working together |
| `in-array-builtins` | `runtime/OracleBuiltinBatchExecutor.php` | `scripts/test-oracle-builtin-batch-execution.php` | `in_array` boolean result parity |
| `array-key-exists-builtins` | `runtime/OracleBuiltinBatchExecutor.php` | `scripts/test-oracle-builtin-batch-execution.php` | `array_key_exists` boolean result parity |
| `array-merge-builtins` | `runtime/OracleBuiltinBatchExecutor.php` | `scripts/test-oracle-builtin-batch-execution.php` | `array_merge` list merging parity |
| `array-reverse-builtins` | `runtime/OracleBuiltinBatchExecutor.php` | `scripts/test-oracle-builtin-batch-execution.php` | `array_reverse` list ordering parity |
| `array-unique-builtins` | `runtime/OracleBuiltinBatchExecutor.php` | `scripts/test-oracle-builtin-batch-execution.php` | `array_unique` value preservation parity |
| `json-encode-builtins` | `runtime/OracleBuiltinBatchExecutor.php` | `scripts/test-oracle-builtin-batch-execution.php` | `json_encode` array encoding parity |
| `hash-builtins` | `runtime/OracleBuiltinBatchExecutor.php` | `scripts/test-oracle-builtin-batch-execution.php` | `md5` hash output parity |

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
./jinx scripts/test-oracle-builtin-batch-execution.php
```

The family execution tests compare Oracle execution to PHP/Zend behavior for:

- captured output;
- returned value;
- thrown error status/message class shape;
- exit status and termination behavior where applicable.

Do not claim a PHP/Zend behavior is executable until an Oracle runtime owner actually runs it and a PHP comparison test proves parity for that family. For release verification, those comparison tests must be launched through `./jinx`.
