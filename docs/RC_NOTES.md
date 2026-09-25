# dompipe/jinx RC Notes

## RC Intent

This release candidate packages the current JINX web/worker branch as a runnable source package with the worker-style native wrapper work included.

The main RC goal is to make the current state inspectable:

- Read the project notes from the package.
- View the latest benchmark snapshot.
- Run focused tests and benchmarks from the `jinx` CLI.
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
./jinx bench-first100 100000
./jinx notes
./jinx benchmarks
```

Or install the compiled executable to `~/.local/bin`:

```bash
./scripts/install-wsl-cli.sh
jinx rc
```

The installed `jinx` is the GCC-built binary, not a PHP launcher.

The PHP RC helper remains available as:

```bash
php bin/jinx rc
php bin/jinx benchmarks
php bin/jinx bench-wrapper-first100
```

## Native GCC CLI

To build `jinx` as a native executable with GCC:

```bash
./scripts/build-native-jinx.sh
./build/native/jinx oracle-smoke
```

The native GCC target compiles:

```text
native/jinx_cli.c
runtime/jinx_function_list.generated.h
runtime/jinx_oracle_asm_context.c
runtime/jinx_builtin_dispatch.generated.c
runtime/jinx_pasm_machine.c
```

This native CLI does not shell out to PHP. It exercises the current C Oracle/PASM runtime directly.

The compiled `jinx` includes a generated inventory for all 3,527 imported names:

```bash
./build/native/jinx functions-count
./build/native/jinx functions
./build/native/jinx function-exists strlen
./build/native/jinx functions-smoke
```

`functions-smoke` verifies that every generated name resolves to a native Oracle dispatch wrapper. The first 100 benchmark functions also have deterministic native sample execution:

```bash
./build/native/jinx first100
./build/native/jinx first100-list
./build/native/jinx bench-first100 100000
```

Full native PHP behavior for every imported function is not claimed; unsafe or complex runtime behavior remains fail-closed or bounded unless explicitly implemented.

It also exposes static RC inspection commands:

```bash
./build/native/jinx notes
./build/native/jinx benchmarks
```

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

The first hot worker-safe builtin benchmark set now routes through:

```text
runtime/WebNativeOracleDispatch.generated.php
```

That table is a PHP carrier for the Oracle/PASM dispatch shape:

```text
validated builtin name -> direct Oracle dispatch case -> PHP builtin
```

The lower C Oracle/PASM dispatcher remains in:

```text
runtime/jinx_builtin_dispatch.generated.c
```

The C dispatcher smoke tests require a local C compiler and default to `gcc`. You can override it with `CC=clang` or `CC=cc`.

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
- Fail-closed behavior for unsafe wrappers.

It does not prove:

- Complete PHP language compatibility.
- Complete PASM/native lowering for every PHP runtime function.
- A final PE64/ELF64 native compiler.
