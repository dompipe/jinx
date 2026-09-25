# Oracle array set/key builtin families

Runtime owner: `runtime/OracleArraySetBuiltinExecutor.php`. PHP comparison test: `scripts/test-oracle-array-set-builtin-execution.php`.

This batch covers deterministic array set, key, strict-search, preserve-key, recursive-count, and step-range behavior without IO, randomness, clocks, callbacks, or external process state.

`array-diff-assoc-builtins`, `array-diff-key-builtins`, `array-intersect-assoc-builtins`, `array-intersect-key-builtins`, `array-merge-recursive-builtins`, `array-fill-keys-builtins`, `array-key-first-builtins`, `array-key-last-builtins`, `array-keys-strict-builtins`, `array-reverse-preserve-builtins`, `array-slice-preserve-builtins`, `array-pad-negative-builtins`, `array-search-strict-builtins`, `in-array-strict-builtins`, `count-recursive-builtins`, `array-column-index-builtins`, `array-chunk-preserve-builtins`, `range-step-builtins`, `array-filter-null-builtins`, `array-unique-string-builtins`.

Run through repository-root native `./jinx`:

```bash
./jinx scripts/test-oracle-array-set-builtin-execution.php
```
