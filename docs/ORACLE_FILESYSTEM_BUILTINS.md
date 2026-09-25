# Oracle filesystem/stat builtin families

Runtime owner: `runtime/OracleFilesystemBuiltinExecutor.php`.

PHP comparison test: `scripts/test-oracle-filesystem-builtin-execution.php`.

This batch creates controlled fixture files and directories under `build/generated/oracle-filesystem-builtin/assets` before comparison. The Oracle owner only reads that controlled fixture state; it does not mutate files during execution.

## Families

`file-exists-builtins`, `is-file-builtins`, `is-dir-builtins`, `is-readable-builtins`, `is-writable-builtins`, `filesize-builtins`, `filetype-builtins`, `fileperms-builtins`, `fileinode-builtins`, `filemtime-builtins`, `filectime-builtins`, `realpath-builtins`, `stat-builtins`, `lstat-builtins`, `file-get-contents-builtins`, `file-lines-builtins`, `md5-file-builtins`, `sha1-file-builtins`, `hash-file-builtins`, `glob-builtins`.

## Run

```bash
git pull origin master
./jinx scripts/test-oracle-filesystem-builtin-execution.php
./jinx scripts/test-oracle-execution-families.php
./jinx scripts/test-oracle-family-facet-audit.php
./jinx scripts/test-oracle-full-coverage-audit.php
./jinx scripts/test-jinx-native-suite.php
git diff --check
```
