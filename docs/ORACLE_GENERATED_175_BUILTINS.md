# Oracle generated 175 pure builtin batch

Runtime owner: `runtime/OracleGeneratedBuiltinExecutor.php`.

Parity test:

```bash
./jinx scripts/test-oracle-generated-175-builtin-execution.php
```

This batch generates 175 deterministic PHP fixtures under `build/generated/oracle-generated-175-builtin/` and compares each fixture against Oracle execution through repository-root native `./jinx`.

The generated families are named:

```text
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
./jinx scripts/test-oracle-generated-175-builtin-execution.php
git diff --check
```
