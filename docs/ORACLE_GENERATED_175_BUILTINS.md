# Oracle generated pure builtin batch

Runtime owner: `runtime/OracleGeneratedBuiltinExecutor.php`.

Generated family manifest:

```text
runtime/OracleGeneratedExecutionFamilies.php
```

Merged family ledger:

```text
runtime/OracleMergedExecutionFamilies.php
```

Parity tests:

```bash
./jinx scripts/test-oracle-generated-family-group.php
./jinx scripts/test-oracle-generated-175-builtin-execution.php
```

This batch is wired into the native verification group through `scripts/test-jinx-native-suite.php`.

The group audit exposes exactly 275 executable generated PHP/Zend parity families, each using `runtime/OracleGeneratedBuiltinExecutor.php` as owner and `scripts/test-oracle-generated-175-builtin-execution.php` as the comparison test.

The first pass added 175 generated families. This update adds 100 more, bringing the generated merged batch to 275 families total.

This batch generates deterministic PHP fixtures under `build/generated/oracle-generated-builtin/` and compares each fixture against Oracle execution through repository-root native `./jinx`.

The generated families are named:

```text
generated-pure-builtin-001 through generated-pure-builtin-275
generated-pure-builtin-001
...
generated-pure-builtin-275
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
./jinx scripts/test-oracle-generated-family-group.php
./jinx scripts/test-oracle-generated-175-builtin-execution.php
./jinx scripts/test-oracle-execution-families.php
./jinx scripts/test-oracle-full-coverage-audit.php
./jinx scripts/test-jinx-native-suite.php
git diff --check
```
