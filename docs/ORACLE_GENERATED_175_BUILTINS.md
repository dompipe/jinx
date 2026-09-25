# Oracle generated 175 pure builtin batch

Runtime owner: `runtime/OracleGeneratedBuiltinExecutor.php`.

Generated family manifest:

```text
runtime/OracleGeneratedExecutionFamilies.php
```

Merged family ledger:

```text
runtime/OracleMergedExecutionFamilies.php
```

The merged ledger folds `OracleGeneratedExecutionFamilies::all()` into the regular `OracleExecutionFamilies::all()` result, so suite-level audits count the generated batch as first-class executable Oracle/PHP parity families.

Parity tests:

```bash
./jinx scripts/test-oracle-generated-family-group.php
./jinx scripts/test-oracle-generated-175-builtin-execution.php
```

This batch is wired into the native verification group through `scripts/test-jinx-native-suite.php`.

The group audit exposes exactly 175 executable generated PHP/Zend parity families, confirms each generated family is also present in `runtime/OracleMergedExecutionFamilies.php`, and verifies each uses `runtime/OracleGeneratedBuiltinExecutor.php` as owner and `scripts/test-oracle-generated-175-builtin-execution.php` as the comparison test.

This batch generates 175 deterministic PHP fixtures under `build/generated/oracle-generated-175-builtin/` and compares each fixture against Oracle execution through repository-root native `./jinx`.

The generated families are named:

```text
generated-pure-builtin-001 through generated-pure-builtin-175
generated-pure-builtin-001
...
generated-pure-builtin-175
```

The batch intentionally stays inside pure deterministic builtin behavior:

- string transforms and length operations
- substring/replacement operations
- JSON-normalized scalar/array returns
- simple numeric builtins
- array aggregation builtins
- hash/base64 builtins
- nested whitelisted builtin calls

It avoids IO, process mutation, callbacks, resources, random values, by-reference mutation, and extension-dependent behavior.

Verification:

```bash
git pull origin master
./scripts/build-native-jinx.sh
./jinx scripts/test-oracle-execution-families.php
./jinx scripts/test-oracle-generated-family-group.php
./jinx scripts/test-oracle-generated-175-builtin-execution.php
./jinx scripts/test-oracle-full-coverage-audit.php
./jinx scripts/test-jinx-native-suite.php
git diff --check
```
