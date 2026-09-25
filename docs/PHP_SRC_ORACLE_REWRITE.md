# php-src to Oracle Rewrite Contract

Oracle is the interpreter and acceleration layer for PHP behavior.

The target is to rewrite the php-src behavior surface in Oracle so all PHP execution can eventually run through Oracle faster while preserving PHP/Zend compatibility:

- PHP language constructs.
- PHP commands and CLI/runtime entry points.
- Builtin functions and internal methods.
- Extension calls.
- `require`, `require_once`, `include`, and `include_once` loader paths.
- Arbitrary PHP source execution.

The current arbitrary-code front door is `OracleProgramCompiler::interpretAnyPhpFileToOracleProgram()`. It emits Oracle records for Zend-shaped source even when that source is not executable inside Oracle yet. Current record families include namespaces/imports, class/interface/trait/enum declarations, methods/properties, control flow, globals/statics, unset/isset/empty, object creation, method/static/property access, throws, returns, echo/print/exit, dimension fetch/assignment, compound assignment, increment/decrement, coalesce/ternary, closures, arrow functions, anonymous classes, clone/instanceof, generators, labels, and goto.

Coverage is only complete for a family when:

1. PHP/Zend behavior is known.
2. Oracle has a representation for that behavior.
3. JINX can route the PHP path into Oracle.
4. A parity or smoke test proves the Oracle path.
5. Unsupported or unsafe gaps remain PHP-compatible instead of pretending to be done.

PASM and native binary emission are later output paths. They are useful after Oracle owns the behavior, but they are not the main task and they do not block the php-src-to-Oracle rewrite.

Proof commands:

```bash
./scripts/build-native-jinx.sh
./jinx scripts/test-oracle-program-compiler.php
./jinx scripts/test-zend-arbitrary-code-oracle.php
./jinx scripts/test-zend-runtime-ops-oracle.php
```
