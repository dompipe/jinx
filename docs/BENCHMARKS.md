# dompipe/jinx RC Benchmark Snapshot

These numbers were captured in the active Windows PHP runtime for this RC package. Treat them as a local snapshot, not universal performance claims.

## Worker-Style Wrapper Dispatch

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

## First 100 Wrapper Functions

Command:

```bash
php bin/jinx bench-wrapper-first100 1000
```

This benchmark walks the generated wrapper order and compares direct PHP calls against `WebNativeFunctions::call()` for the first 100 benchmarkable worker-callable PHP function wrappers. The current JINX path uses the Oracle-shaped PHP dispatch table before falling back to the generic worker wrapper registry. Entries that need nondeterministic resources, are deprecated in this PHP runtime, cannot be called dynamically, or currently mismatch PHP behavior are reported as skipped.

Snapshot:

```text
Iterations per function: 1000
Skipped before target: 6

TOTAL php:   1.375 us/op
TOTAL jinx:  1.604 us/op
ratio:       1.17x
```

Before the Oracle-shaped dispatch table, the same benchmark was `1.47x` JINX/PHP.

First skipped wrappers in this run:

```text
array_unique: php and jinx value mismatch
assert_options: Function assert_options() is deprecated since 8.3
class_alias: Cannot redeclare class
closedir: no deterministic sample args
compact: Cannot call compact() dynamically
```

## Oracle Dispatch Notes

The package now has two Oracle-dispatch layers:

```text
runtime/WebNativeOracleDispatch.generated.php
runtime/jinx_builtin_dispatch.generated.c
```

`WebNativeOracleDispatch.generated.php` is the PHP-carried Oracle-shaped dispatch table used by `WebNativeFunctions::call()` for the first hot worker-safe benchmark set. The C dispatcher remains the lower-level Oracle/PASM path. The C Oracle smoke tests default to `gcc`; set `CC=clang` or `CC=cc` if you want a different compiler.

Build the native GCC CLI:

```bash
./scripts/build-native-jinx.sh
./build/native/jinx oracle-smoke
./build/native/jinx functions-smoke
./build/native/jinx first100
./build/native/jinx bench-oracle 1000000
./build/native/jinx bench-first100 100000
./build/native/jinx benchmarks
```

`functions-smoke` checks all 3,527 generated names are present in the native Oracle dispatch table. `first100` and `bench-first100` execute the first 100 benchmark functions through the compiled C Oracle/PASM path with deterministic native sample values.

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

## PHP RC Benchmark Commands

```bash
php bin/jinx bench-worker
php bin/jinx bench-wrapper-first100
php bin/jinx bench-endpoint
php bin/jinx bench-docroot
php bin/jinx bench-socket
php bin/jinx bench-frozen-server
php bin/jinx bench-cache-server
php bin/jinx oracle-smoke
```

If a benchmark reports a locked log file on Windows, stop any leftover local PHP benchmark server process and rerun the benchmark.
