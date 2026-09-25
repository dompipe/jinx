# Oracle execution commands

Oracle is the PHP/Zend mirroring and execution layer. PASM/native output stays secondary until the Oracle layer proves behavior against PHP.

## Current executable Oracle families

| Family | Runtime owner | PHP comparison test | Scope |
|---|---|---|---|
| `straight-line` | `runtime/OracleStraightLineExecutor.php` | `scripts/test-oracle-straightline-execution.php` | assignments, dimension writes/fetches, coalesce, compound assignment, inc/dec, echo, print, return, `strlen`, `strtoupper` |
| `conditionals` | `runtime/OracleConditionalExecutor.php` | `scripts/test-oracle-conditional-execution.php` | narrow `if`/`else`, comparisons, `&&`, `||`, `!`, PHP-like truthiness, plus the straight-line operations needed inside branches |
| `loops` | `runtime/OracleLoopExecutor.php` | `scripts/test-oracle-loop-execution.php` | narrow `while` loops, `break`, `continue`, nested conditionals, plus the straight-line operations needed inside loop bodies |
| `arrays` | `runtime/OracleArrayExecutor.php` | `scripts/test-oracle-array-execution.php` | empty array literals, append writes, nested dimension assigns/fetches, `isset`, `empty`, `unset`, `count`, echo, print, return |

## Verification

Run these from a clean local checkout after pulling `master`:

```bash
git pull origin master
./scripts/build-native-jinx.sh
php scripts/test-oracle-execution-families.php
php scripts/test-oracle-straightline-execution.php
php scripts/test-oracle-conditional-execution.php
php scripts/test-oracle-loop-execution.php
php scripts/test-oracle-array-execution.php
php scripts/test-bin-jinx.php
php scripts/test-oracle-program-compiler.php
git diff --check
```

The family execution tests compare Oracle execution to PHP for:

- captured output;
- returned value;
- thrown error status/message class shape.

Do not claim a PHP/Zend behavior is executable until an Oracle runtime owner actually runs it and a PHP comparison test proves parity for that family.
