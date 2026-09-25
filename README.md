# dompipe/jinx RC

This is a release-candidate source package for the dompipe/JINX worker and web compiler branch.

The RC focuses on:

- JINX web compilation for validated JSON endpoints.
- Worker-style native wrapper registration for the imported PHP runtime surface.
- A generated compact wrapper dispatch table for 3,527 PHP callable signatures.
- An Oracle-shaped PHP dispatch table for the first hot worker-safe builtin benchmark set.
- Benchmarks for endpoint execution, worker serving, PASM-shaped execution forms, and generated wrapper overhead.

## Quick Native Commands

```bash
./scripts/build-native-jinx.sh
./jinx rc
./jinx oracle-smoke
./jinx oracle-call strlen oracle
./jinx oracle-call count 3
./jinx bench-oracle 1000000
./jinx functions-count
./jinx functions-smoke
./jinx first100
./jinx bench-first100 100000
./jinx notes
./jinx benchmarks
```

## Native GCC CLI

To compile an actual `jinx` executable with GCC in WSL:

```bash
./scripts/build-native-jinx.sh
./build/native/jinx rc
./build/native/jinx oracle-smoke
./build/native/jinx oracle-call strlen oracle
./build/native/jinx oracle-call count 3
./build/native/jinx bench-oracle 1000000
./build/native/jinx functions-count
./build/native/jinx functions-smoke
./build/native/jinx first100
./build/native/jinx bench-first100 100000
./build/native/jinx notes
./build/native/jinx benchmarks
```

This native binary uses the C Oracle/PASM runtime:

```text
native/jinx_cli.c
runtime/jinx_oracle_asm_context.c
runtime/jinx_builtin_dispatch.generated.c
runtime/jinx_pasm_machine.c
runtime/jinx_function_list.generated.h
```

Current native runtime behavior is intentionally bounded: the compiled CLI can prove all 3,527 function names resolve to generated Oracle dispatch wrappers, can execute the first 100 benchmark functions through that native Oracle path, and can run the lower PASM `CALL_BUILTIN strlen` smoke. Full PHP behavioral parity for every imported function is not claimed.

To install the compiled native command on WSL:

```bash
chmod +x scripts/build-native-jinx.sh scripts/install-wsl-cli.sh
./scripts/install-wsl-cli.sh
jinx rc
```

After that, use `jinx ...` directly. The installed `jinx` is the GCC-built executable.

If your WSL has GCC, the lower Oracle/PASM smoke tests are:

```bash
jinx oracle-smoke
```

## RC Status

This package is an RC, not a final native compiler claim.

Implemented and verified in this package:

- 3,527 worker-style native wrapper records are generated from `spec/php-functions.from-runtime.json`.
- The native GCC CLI includes all 3,527 generated function names and can smoke-check that every name resolves to a generated Oracle wrapper.
- The native GCC CLI executes the first 100 benchmark functions through C Oracle dispatch with deterministic sample values.
- The hot wrapper dispatch path uses a compact `name -> id -> row` table.
- Unsafe, unavailable, by-reference, and method-only wrappers fail closed at runtime.
- Hand wrappers remain for functions that need custom PHP value handling.
- The web worker benchmark and wrapper registry tests pass in the active Windows PHP runtime.

Not claimed as complete:

- A full native PE/ELF compiler.
- Full PHP behavioral parity for every imported signature.
- Runtime execution of unsafe filesystem, process, network, session, database, or environment-mutating PHP functions in the worker.

## Important Files

```text
native/jinx_cli.c
bin/jinx
runtime/WebNativeFunctions.php
runtime/WebNativeFunctionRegistry.generated.php
runtime/WebNativeOracleDispatch.generated.php
runtime/jinx_builtin_dispatch.generated.c
runtime/jinx_function_list.generated.h
scripts/generate-web-native-function-registry.php
scripts/generate-native-function-list.php
scripts/build-native-jinx.sh
scripts/test-web-native-function-registry.php
docs/RC_NOTES.md
docs/BENCHMARKS.md
```

## Current Benchmark Snapshot

From the RC benchmark run in this workspace:

```text
Generated wrapper acos:        0.582 us/call
Hand wrapper strlen:           0.156 us/call
First 100 wrapper benchmark:   1.17x JINX/PHP after Oracle dispatch
Native first100 command:       ./jinx bench-first100 100000
First allowedNames():          0.292 ms
Warm allowedNames():           0.001 ms
JINX worker close avg:         5.555 ms
Native php -S avg:             11.169 ms
Endpoint compiled/native:      1.00x
```

Run `php bin/jinx benchmarks` for the saved benchmark notes.

## Package Contents

The clean RC zip should include source-facing files only:

```text
bin/
docs/
native/
runtime/
scripts/
fixtures/
build/oracle-asm/
README.md
README-PACKAGE.md
.gitignore
```

Generated build output, logs, cache directories, zips, vendor folders, `node_modules`, and `.git` metadata are intentionally excluded from the clean zip.
