# dompipe/jinx RC

This is a release-candidate source package for the dompipe/JINX worker and web compiler branch.

The RC focuses on:

- JINX web compilation for validated JSON endpoints.
- Worker-style native wrapper registration for the imported PHP runtime surface.
- A generated compact wrapper dispatch table for 3,527 PHP callable signatures.
- An Oracle-shaped PHP dispatch table for the first hot worker-safe builtin benchmark set.
- A native GCC `./jinx` executable that exercises the C Oracle/PASM dispatch layer.
- Native `oracle-call` support for every generated PHP callable name in the native dispatch table.
- Native benchmarks for first-100 and all-functions Oracle dispatch traversal.
- PHP comparison benchmarks for validated callable builtin cases.
- Benchmarks for endpoint execution, worker serving, PASM-shaped execution forms, generated wrapper overhead, and native Oracle dispatch.

## Quick Native Commands

Build the native executable first. This creates the repository-root `./jinx` binary and also copies it to `./build/native/jinx`.

```bash
./scripts/build-native-jinx.sh
./jinx rc
./jinx oracle-smoke
./jinx functions-count
./jinx functions-smoke
./jinx function-exists strlen
./jinx oracle-call strlen s:oracle
./jinx oracle-call abs i:-42
./jinx oracle-call array_values a:4
./jinx first100
./jinx first100-list
./jinx bench-first100 100000
./jinx bench-all-functions 1000
./jinx notes
./jinx benchmarks
```

Strict all-functions check:

```bash
./jinx bench-all-functions 1000 --strict
```

`--strict` fails if any generated function returns a null/fault placeholder. Non-strict mode is the normal traversal benchmark while the C Oracle runtime is still filling in exact behavioral handlers for every imported PHP builtin.

## Native `oracle-call`

`oracle-call` is the native direct-call entrypoint. It checks the generated Oracle dispatch table, converts CLI arguments into `JinxValue` slots, and calls the requested wrapper from the `./jinx` executable.

```bash
./jinx oracle-call <function> [typed-args...]
```

Typed CLI arguments:

```text
i:<int>       integer value
f:<float>     floating point value
b:true|false  boolean value
s:<text>      string value
a:<count>     array-count stand-in
null          null value
raw text      defaults to string
```

Examples:

```bash
./jinx oracle-call strlen s:oracle
./jinx oracle-call count a:4
./jinx oracle-call abs i:-42
./jinx oracle-call acos f:1.0
./jinx oracle-call str_contains s:dompipe s:pipe
./jinx oracle-call DateTime::format s:Y-m-d
```

Important distinction: every generated name is callable through this entrypoint if it exists in the generated native dispatch table. Exact PHP-compatible behavior is still being filled in by replacing coarse native handler families with exact builtin implementations.

## PHP vs Native `./jinx` Benchmark

Use this when you want the benchmark to build the native CLI, validate PHP-side parameterized cases, and compare direct PHP builtin timing against the native `./jinx` all-functions dispatch traversal:

```bash
php scripts/benchmark-native-jinx-vs-php.php 1000
```

Fast rerun after `./jinx` already exists:

```bash
JINX_SKIP_BUILD=1 php scripts/benchmark-native-jinx-vs-php.php 1000
```

This benchmark intentionally calls the built native executable:

```text
./jinx functions
./jinx bench-all-functions <iterations>
```

It does **not** call `php bin/jinx` for the JINX timing path.

## Native GCC CLI

To compile an actual `jinx` executable with GCC in WSL or another GCC-compatible environment:

```bash
./scripts/build-native-jinx.sh
./jinx oracle-smoke
./jinx oracle-call strlen s:oracle
./jinx oracle-call count a:3
./jinx oracle-call abs i:-42
./jinx bench-oracle 1000000
./jinx functions-count
./jinx functions-smoke
./jinx first100
./jinx bench-first100 100000
./jinx bench-all-functions 1000
./jinx benchmarks
```

The build script also writes the same binary to:

```bash
./build/native/jinx oracle-smoke
./build/native/jinx bench-all-functions 1000
```

This native binary uses the C Oracle/PASM runtime:

```text
native/jinx_cli.c
runtime/jinx_oracle_asm_context.c
runtime/jinx_builtin_dispatch.generated.c
runtime/jinx_pasm_machine.c
runtime/jinx_function_list.generated.h
build/oracle-asm/jinx_oracle_asm_runtime.h
build/oracle-asm/stubs/runtime-reflection-8-4-23.oracle_asm.h
```

Current native runtime behavior is intentionally explicit:

- all 3,527 generated names can be checked for native Oracle dispatch-wrapper presence;
- `oracle-call` can invoke any generated name through the native `./jinx` executable;
- the first 100 benchmark functions execute through the C Oracle path with deterministic native sample values;
- `bench-all-functions` traverses every generated wrapper with deterministic sample argument slots;
- `bench-all-functions` reports concrete non-null returns separately from null/fault placeholder returns;
- lower PASM smoke currently covers `CALL_BUILTIN strlen`;
- full PHP behavioral parity for every imported function is still not claimed.

To install the compiled native command on WSL:

```bash
chmod +x scripts/build-native-jinx.sh scripts/install-wsl-cli.sh
./scripts/install-wsl-cli.sh
jinx rc
jinx bench-all-functions 1000
```

After that, use `jinx ...` directly. The installed `jinx` is the GCC-built executable.

## RC Status

This package is an RC, not a final native compiler claim.

Implemented and verified in this package:

- 3,527 worker-style native wrapper records are generated from `spec/php-functions.from-runtime.json`.
- The native GCC CLI includes all 3,527 generated function names.
- `functions-smoke` verifies that every generated name resolves to a generated Oracle wrapper.
- `oracle-call` invokes any generated name through the native `./jinx` Oracle dispatch table.
- `first100` executes the first 100 benchmark functions through C Oracle dispatch with deterministic sample values.
- `bench-first100` times that first-100 C Oracle dispatch set.
- `bench-all-functions` traverses the full generated native dispatch surface and reports concrete versus placeholder returns.
- `scripts/benchmark-native-jinx-vs-php.php` compares validated PHP builtin calls against native `./jinx bench-all-functions` output.
- The hot wrapper dispatch path uses a compact `name -> id -> row` table.
- Unsafe, unavailable, by-reference, and method-only wrappers fail closed at runtime.
- Hand wrappers remain for functions that need custom PHP value handling.
- The web worker benchmark and wrapper registry tests pass in the active Windows PHP runtime.

Not claimed as complete:

- A full native PE/ELF compiler.
- Full PHP behavioral parity for every imported signature.
- Runtime execution of unsafe filesystem, process, network, session, database, or environment-mutating PHP functions in the worker.
- Complete exact C behavior handlers for every generated PHP builtin.

## Important Files

```text
native/jinx_cli.c
bin/jinx
runtime/WebNativeFunctions.php
runtime/WebNativeFunctionRegistry.generated.php
runtime/WebNativeOracleDispatch.generated.php
runtime/jinx_builtin_dispatch.generated.c
runtime/jinx_function_list.generated.h
runtime/jinx_oracle_asm_context.c
scripts/benchmark-native-jinx-vs-php.php
scripts/generate-web-native-function-registry.php
scripts/generate-native-function-list.php
scripts/build-native-jinx.sh
scripts/test-web-native-function-registry.php
docs/RC_NOTES.md
docs/BENCHMARKS.md
docs/NATIVE_VS_PHP_BENCHMARK.md
```

## Current Benchmark Snapshot

From the RC benchmark run in this workspace:

```text
Generated wrapper acos:        0.582 us/call
Hand wrapper strlen:           0.156 us/call
First 100 wrapper benchmark:   1.17x JINX/PHP after Oracle dispatch
Native first100 command:       ./jinx bench-first100 100000
Native all-functions command:  ./jinx bench-all-functions 1000
First allowedNames():          0.292 ms
Warm allowedNames():           0.001 ms
JINX worker close avg:         5.555 ms
Native php -S avg:             11.169 ms
Endpoint compiled/native:      1.00x
```

For current benchmark instructions, read:

```bash
./jinx benchmarks
cat docs/BENCHMARKS.md
cat docs/NATIVE_VS_PHP_BENCHMARK.md
```

## PHP Helper Commands

The PHP helper CLI remains useful for web/compiler workflows and legacy PHP-side benchmark notes:

```bash
php bin/jinx rc
php bin/jinx benchmarks
php bin/jinx bench-wrapper-first100
php bin/jinx bench-worker
php bin/jinx bench-endpoint
```

Use the native `./jinx` executable for native Oracle/PASM timing and direct generated-function calls.

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

Generated logs, cache directories, zips, vendor folders, `node_modules`, and `.git` metadata are intentionally excluded from the clean zip.
