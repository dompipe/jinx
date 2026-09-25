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
./jinx scripts/test-oracle-function-execution.php
```

The family execution tests compare Oracle execution to PHP/Zend behavior for:

- captured output;
- returned value;
- thrown error status/message class shape.

Do not claim a PHP/Zend behavior is executable until an Oracle runtime owner actually runs it and a PHP comparison test proves parity for that family. For release verification, those comparison tests must be launched through `./jinx`.
