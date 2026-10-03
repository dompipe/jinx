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

The group audit exposes exactly 782 executable generated ledger family IDs, each using `runtime/OracleGeneratedBuiltinExecutor.php` as owner and `scripts/test-oracle-generated-175-builtin-execution.php` as the comparison test.

Important coverage shape:

```text
Generated family IDs: 782
Generated unique expression templates: 25
Generated semantic coverage: fixture-level pure-builtin cases, not distinct PHP/Zend semantic families
```

In other words: this batch is 782 generated family IDs across 25 unique expression templates.

The first pass added 175 generated family IDs. The second pass added 100 more, bringing the generated merged batch to 275 family IDs. The third pass added another 100, bringing the generated merged batch to 375 family IDs. The fourth pass added 150 more, bringing the generated merged batch to 525 family IDs. The fifth pass added 100 more, bringing the generated merged batch to 625 family IDs. The sixth pass added another 100, bringing the generated merged batch to 725 family IDs. This final pass adds 57 more, bringing the generated merged batch to 782 family IDs total.

That completes the 1,144 PHP/Zend executable family ledger target when combined with the 362 regular PHP/Zend families. The extra merged family above that target is the JINX island/server Oracle family. This is a ledger-count milestone, not proof that 1,144 distinct PHP/Zend semantic behaviors have independent parity fixtures.

This batch generates deterministic PHP fixtures under `build/generated/oracle-generated-builtin/` and compares each fixture against Oracle execution through repository-root native `./jinx`.

The generated families are named:

```text
generated-pure-builtin-001 through generated-pure-builtin-782
generated-pure-builtin-001
...
generated-pure-builtin-782
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
