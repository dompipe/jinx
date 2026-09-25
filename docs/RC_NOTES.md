# dompipe/jinx RC Notes

## RC Intent

This release candidate packages the current JINX web/worker branch as a runnable source package with the worker-style native wrapper work included.

The main RC goal is to make the current state inspectable:

- Read the project notes from the package.
- View the latest benchmark snapshot.
- Build and run the native `./jinx` executable.
- Run native Oracle/PASM smoke checks and dispatch benchmarks.
- Run PHP helper tests and benchmarks from the PHP CLI wrapper where appropriate.
- Keep generated/cache/build junk out of the release zip.

## Native WSL CLI

Build the top-level native executable first:

```bash
chmod +x scripts/build-native-jinx.sh scripts/install-wsl-cli.sh
./scripts/build-native-jinx.sh
./jinx rc
./jinx oracle-smoke
./jinx functions-count
./jinx functions-smoke
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

Or install the compiled executable to `~/.local/bin`:

```bash
./scripts/install-wsl-cli.sh
jinx rc
jinx bench-all-functions 1000
```

The installed `jinx` is the GCC-built binary, not a PHP launcher.

The PHP RC helper remains available as:

```bash
php bin/jinx rc
php bin/jinx benchmarks
php bin/jinx bench-wrapper-first100
```

Use native `./jinx` for native Oracle/PASM timing.

## Native GCC CLI

To build `jinx` as a native executable with GCC:

```bash
./scripts/build-native-jinx.sh
./jinx oracle-smoke
```

The native GCC target compiles:

```text
native/jinx_cli.c
runtime/jinx_function_list.generated.h
runtime/jinx_oracle_asm_context.c
runtime/jinx_builtin_dispatch.generated.c
runtime/jinx_pasm_machine.c
build/oracle-asm/jinx_oracle_asm_runtime.h
build/oracle-asm/stubs/runtime-reflection-8-4-23.oracle_asm.h
```

This native CLI does not shell out to PHP. It exercises the current C Oracle/PASM runtime directly.

The compiled `jinx` includes a generated inventory for all 3,527 imported names:

```bash
./jinx functions-count
./jinx functions
./jinx function-exists strlen
./jinx functions-smoke
```

`functions-smoke` verifies that every generated name resolves to a native Oracle dispatch wrapper.

The first 100 benchmark functions have deterministic native sample execution:

```bash
./jinx first100
./jinx first100-list
./jinx bench-first100 100000
```

The all-functions benchmark traverses every generated wrapper through native Oracle dispatch:

```bash
./jinx bench-all-functions 1000
```

It reports:

- generated function count;
- dispatch-wrapper presence;
- concrete non-null first-pass returns;
- null/fault placeholder first-pass returns;
- total dispatches;
- elapsed time;
- nanoseconds per dispatch.

Full native PHP behavior for every imported function is not claimed; unsafe or complex runtime behavior remains fail-closed or bounded unless explicitly implemented. The null/fault placeholder count is the visible list of remaining native behavior work.

It also exposes static RC inspection commands:

```bash
./jinx notes
./jinx benchmarks
```

## PHP vs Native Benchmark

Run the comparison wrapper:

```bash
php scripts/benchmark-native-jinx-vs-php.php 1000
```

Fast rerun after the native binary is already built:

```bash
JINX_SKIP_BUILD=1 php scripts/benchmark-native-jinx-vs-php.php 1000
```

This script validates PHP-side direct builtin calls where possible, then calls the native executable:

```text
./jinx functions
./jinx bench-all-functions <iterations>
```

It does not use `php bin/jinx` for the native timing path.

## Worker-Style Native Wrappers

The wrapper inventory is generated from:

```text
spec/php-functions.from-runtime.json
```

The generated runtime file is:

```text
runtime/WebNativeFunctionRegistry.generated.php
```

It contains 3,527 wrapper records and uses a compact runtime dispatch shape:

```text
function name -> small numeric id -> compact row
```

The compact row stores:

```text
[name, required_arg_count, total_arg_count, flags, blocked_reason]
```

The full metadata registry is still available for inspection, but generated calls use the compact table.

## Oracle Dispatch Layer

The package has two Oracle dispatch layers:

```text
runtime/WebNativeOracleDispatch.generated.php
runtime/jinx_builtin_dispatch.generated.c
```

`WebNativeOracleDispatch.generated.php` is the PHP-carried Oracle-shaped dispatch table used by `WebNativeFunctions::call()` for the first hot worker-safe benchmark set.

`runtime/jinx_builtin_dispatch.generated.c` is the lower C Oracle/PASM dispatch table used by the built native `./jinx` executable.

The C Oracle smoke tests require a local C compiler and default to `gcc`. You can override it with `CC=clang` or `CC=cc`.

## Safety Policy

The worker does not blindly execute all imported PHP functions.

The registry creates wrapper records for every imported signature, but runtime execution fails closed for:

- Method-only entries.
- Unavailable PHP runtime functions.
- By-reference signatures that need custom hand wrappers.
- Filesystem, process, shell, stream, socket, session, database, environment, and other unsafe side-effect functions.

This means the RC can honestly say all 3,527 signatures have worker-style wrapper records, while also preserving worker safety.

## Verified In This Workspace

Focused checks run successfully:

```text
php scripts/test-web-native-function-registry.php
php -l runtime/WebNativeFunctions.php
php -l runtime/WebNativeFunctionRegistry.generated.php
php -l scripts/generate-web-native-function-registry.php
```

The Windows environment here does not provide `/bin/bash`, so the shell wrapper `scripts/test-web-green-main.sh` cannot be run directly as a Bash script. Its PHP checks were run directly where possible during the RC work.

## RC Caveats

This is still not a finished native compiler distribution.

The current package proves:

- Web endpoint compilation behavior.
- Worker request handling.
- Native wrapper registry generation.
- Compact dispatch table execution for safe generated wrappers.
- Oracle-shaped dispatch for the first hot worker-safe builtin benchmark set.
- First-100 wrapper benchmarking through direct PHP and the JINX wrapper path.
- Native GCC inventory coverage for all 3,527 generated names.
- Native GCC first-100 sample execution through C Oracle dispatch.
- Native GCC all-functions dispatch traversal through C Oracle dispatch.
- Native reporting of concrete versus null/fault placeholder function returns.
- Fail-closed behavior for unsafe wrappers.

It does not prove:

- Complete PHP language compatibility.
- Complete PASM/native lowering for every PHP runtime function.
- Complete C behavior handlers for every PHP builtin.
- A final PE64/ELF64 native compiler.
