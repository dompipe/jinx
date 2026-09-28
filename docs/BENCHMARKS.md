# dompipe/jinx RC Benchmark Snapshot

These numbers were captured in local RC workspaces. Treat them as local snapshots, not universal performance claims.

The benchmark documentation is split into two paths:

1. Native Oracle/PASM benchmarks through the built `./jinx` executable.
2. PHP helper benchmarks through `php bin/jinx` or direct PHP scripts.

Use the native executable path for native timing claims.

## Native Build

Build the native CLI first:

```bash
./scripts/build-native-jinx.sh
```

That script writes:

```text
./jinx
./build/native/jinx
```

Both are the same GCC-built native executable.

## Native All-Functions Dispatch Benchmark

Command:

```bash
./jinx bench-all-functions 1000
```

Strict mode:

```bash
./jinx bench-all-functions 1000 --strict
```

What it does:

- walks every generated function name in `runtime/jinx_function_list.generated.h`;
- verifies every name has a native Oracle dispatch wrapper;
- fills deterministic sample argument slots for each wrapper call;
- times full generated dispatch traversal;
- reports concrete non-null first-pass returns separately from null/fault placeholder returns.

The placeholder count is expected until the C Oracle runtime has complete behavior handlers for every imported PHP builtin. Non-strict mode is the honest full-surface traversal benchmark. Strict mode is for tracking when all functions have real non-null behavior.

Expected output shape:

```text
JINX native all-functions Oracle dispatch benchmark
Functions: 3527
Iterations per function: 1000
Sample args per call: 32
Dispatch wrappers present: 3527/3527
Concrete non-null first-pass returns: <count>/3527
Null/fault placeholder first-pass returns: <count>/3527
Total dispatches: 3527000
Elapsed ms: <ms>
Per dispatch ns: <ns>
```

## PHP vs Native `./jinx` Comparison

Command:

```bash
php scripts/benchmark-native-jinx-vs-php.php 1000
php scripts/benchmark-native-implemented-functions.php 1000
```

Fast rerun after the native binary is already built:

```bash
JINX_SKIP_BUILD=1 php scripts/benchmark-native-jinx-vs-php.php 1000
```

The comparison script builds `./jinx` unless `JINX_SKIP_BUILD=1` is set, validates PHP-side direct builtin calls where possible, and then calls:

```bash
./jinx functions
./jinx bench-all-functions <iterations>
```

It does **not** use `php bin/jinx` for the native JINX timing path.

## Implemented-Function PHP vs Native Benchmark

Use this benchmark when you want timings attached to the functions that now have
real native routes instead of folding them into the all-functions average.

Command:

```bash
php scripts/benchmark-native-implemented-functions.php 1000
```

If repository-root `./jinx` already exists, the benchmark reuses it and starts
immediately. Force a rebuild only when needed:

```bash
php scripts/benchmark-native-implemented-functions.php 1000 --build
```

`JINX_SKIP_BUILD=1` and `--no-build` remain available for CI/scripts that
must never trigger a build.

Useful filters:

```bash
JINX_SKIP_BUILD=1 php scripts/benchmark-native-implemented-functions.php 1000 --route=asm
JINX_SKIP_BUILD=1 php scripts/benchmark-native-implemented-functions.php 1000 --route=extended
JINX_SKIP_BUILD=1 php scripts/benchmark-native-implemented-functions.php 1000 --name=str
JINX_SKIP_BUILD=1 php scripts/benchmark-native-implemented-functions.php 1000 --limit=100
php scripts/benchmark-native-implemented-functions.php 1000 --no-build --case-timeout=10
```

Before timing, the benchmark runs `./jinx native-benchmark-id` and requires
the exact native identity `native-root-jinx`. This prevents the benchmark
from silently falling through the native CLI's unrelated `bin/jinx` PHP
frontend compatibility path.

The benchmark prints progress before every implementation. A native case that
does not return within the per-case timeout is marked `SKIP` and the run
continues. Slow/network/stateful PHP families are rejected before native timing
starts, so one DNS, process, filesystem, sleep, or similar route cannot hold the
entire table open. `--quiet` suppresses progress lines when only the final
table is wanted.

The benchmark associates each measured row with:

- the function or method name;
- the actual execution route used by the benchmark;
- the reviewed builtin-ledger route from `spec/native-oracle-wiring.json`;
- the parity-proof family that covers the implementation;
- direct PHP nanoseconds per call;
- native `./jinx` nanoseconds per call;
- the JINX/PHP timing ratio.

It reuses `scripts/native-oracle-sample-args.php`, the same deterministic typed
fixtures used by the native placeholder audit, so audit and benchmark
parameterization stay synchronized. Unsafe/state-changing calls and fixture
types that cannot be translated faithfully to direct PHP are skipped rather
than timed with made-up inputs.

The native half is timed inside the GCC-built executable, not by launching one
`./jinx` process per measured call:

```bash
./jinx bench-call abs 1000000 i:-42
./jinx bench-method-call 'DateTime::format' 100000 'dt:2024-01-02 03:04:05' 's:Y-m-d'
```

The current parity-proven DateTime/DateTimeImmutable/DateTimeZone/DateInterval scalar methods use explicit receiver fixtures. Throwable methods are discovered automatically from the reviewed inventory and PHP class metadata using the deterministic `ex:<Class>` receiver fixture. Method rows report `method-dispatch` as the actual route and keep the builtin-ledger route in a separate column, because the builtin wiring ledger intentionally does not classify method-dispatch implementations. They point to `scripts/test-native-method-oracle-asm.php` as the parity proof.

## Native First-100 Benchmark

Command:

```bash
./jinx first100
./jinx first100-list
./jinx bench-first100 100000
```

This is the focused native C Oracle/PASM sample set. It executes the first 100 benchmark functions through C Oracle dispatch with deterministic native sample values.

Expected output shape:

```text
JINX native first 100 Oracle wrapper benchmark
Functions: 100
Iterations per function: 100000
Total calls: 10000000
Elapsed ms: <ms>
Per call ns: <ns>
```

## Native Oracle Smoke and Microbench

Commands:

```bash
./jinx oracle-smoke
./jinx oracle-call strlen oracle
./jinx oracle-call count 3
./jinx bench-oracle 1000000
```

`oracle-smoke` proves the current lower C Oracle/PASM path can execute Oracle `strlen`, Oracle `count`, and PASM `CALL_BUILTIN strlen`.

## Native Dispatch Inventory

Commands:

```bash
./jinx functions-count
./jinx functions
./jinx function-exists strlen
./jinx functions-smoke
```

`functions-smoke` checks every generated name resolves to a native Oracle dispatch wrapper. This is different from proving every PHP function has complete native behavioral parity.

## Worker-Style PHP Wrapper Dispatch Snapshot

```text
registered wrappers:       3527
allowedNames first read:   0.292 ms
allowedNames warm read:    0.001 ms
strlen hand wrapper:       0.156 us/call
acos generated wrapper:    0.582 us/call
```

Optimization history:

```text
acos generated wrapper before caching:     2.191 us/call
acos after cached/generated safety fields: 0.772 us/call
acos after compact id table:               0.582 us/call
```

## PHP First-100 Wrapper Functions

Legacy PHP helper command:

```bash
php bin/jinx bench-wrapper-first100 1000
```

This benchmark walks the generated wrapper order and compares direct PHP calls against `WebNativeFunctions::call()` for the first 100 benchmarkable worker-callable PHP function wrappers. The current JINX PHP worker path uses the Oracle-shaped PHP dispatch table before falling back to the generic worker wrapper registry.

Snapshot:

```text
Iterations per function: 1000
Skipped before target: 6

TOTAL php:   1.375 us/op
TOTAL jinx:  1.604 us/op
ratio:       1.17x
```

Before the Oracle-shaped dispatch table, the same benchmark was `1.47x` JINX/PHP.

First skipped wrappers in that run:

```text
array_unique: php and jinx value mismatch
assert_options: Function assert_options() is deprecated since 8.3
class_alias: Cannot redeclare class
closedir: no deterministic sample args
compact: Cannot call compact() dynamically
```

## Oracle Dispatch Layers

The package has two Oracle-dispatch layers:

```text
runtime/WebNativeOracleDispatch.generated.php
runtime/jinx_builtin_dispatch.generated.c
```

`WebNativeOracleDispatch.generated.php` is the PHP-carried Oracle interpreter-shaped dispatch table used by `WebNativeFunctions::call()` for the first hot worker-safe benchmark set.

`runtime/jinx_builtin_dispatch.generated.c` is the lower native C Oracle/PASM dispatch table used by the built `./jinx` executable.

The C Oracle smoke tests default to `gcc`; set `CC=clang` or `CC=cc` if you want a different compiler:

```bash
CC=clang ./scripts/build-native-jinx.sh
```

## Worker Benchmark

Command:

```bash
php scripts/benchmark-worker.php
```

Snapshot:

```text
Iterations: 2000

target                   avg ms     min ms     p50 ms     p95 ms     max ms    req/sec
native php -S            11.169      5.310      7.563     28.136     32.531       89.5
jinx worker close         5.555      2.161      2.974     21.605     26.944      180.0

worker-close/native avg latency ratio: 0.50x
worker-close/native p50 latency ratio: 0.39x
```

## Endpoint Execution Benchmark

Command:

```bash
php scripts/benchmark-endpoint-execution.php
```

Snapshot:

```text
Iterations: 10000

target                                 total ms       avg us     runs/sec
native endpoint source                39711.894     3971.189        251.8
compiled endpoint direct PHP          39866.459     3986.646        250.8

compiled/native execution ratio: 1.00x
```

## PASM Microbenchmarks

Optimized PASM forms:

```text
raw PHP expression              70.4 ns/op
literal PASM register traffic  164.0 ns/op
coalesced locals               115.1 ns/op
coalesced expression            65.7 ns/op
constant folded                 61.9 ns/op
```

Specialized PASM:

```text
raw PHP expression                 66.2 ns/op
plain closure                     131.7 ns/op
specialized PASM-shaped closure   149.0 ns/op
constant-folded PASM closure       58.8 ns/op
```

## Command Summary

Native timing path:

```bash
./scripts/build-native-jinx.sh
./jinx oracle-smoke
./jinx functions-smoke
./jinx bench-oracle 1000000
./jinx bench-call abs 1000000 i:-42
./jinx bench-method-call 'DateTime::format' 100000 'dt:2024-01-02 03:04:05' 's:Y-m-d'
./jinx bench-first100 100000
./jinx bench-all-functions 1000
php scripts/benchmark-native-jinx-vs-php.php 1000
```

PHP helper path:

```bash
php bin/jinx bench-wrapper-first100
php bin/jinx bench-worker
php bin/jinx bench-endpoint
php bin/jinx bench-docroot
php bin/jinx bench-socket
php bin/jinx bench-frozen-server
php bin/jinx bench-cache-server
```

If a benchmark reports a locked log file on Windows, stop any leftover local PHP benchmark server process and rerun the benchmark.
