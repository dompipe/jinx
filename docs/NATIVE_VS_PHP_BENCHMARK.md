# Native `./jinx` versus PHP Function Benchmark

This benchmark is for the native executable path, not the PHP CLI wrapper.

## Build first

```bash
./scripts/build-native-jinx.sh
```

That script compiles the C Oracle/PASM runtime to the repository-root executable:

```text
./jinx
```

It also copies the same binary to:

```text
./build/native/jinx
```

## Run the benchmark

```bash
php scripts/benchmark-native-jinx-vs-php.php 100000
```

The benchmark driver intentionally calls:

```bash
./jinx first100-list
./jinx bench-first100 <iterations>
```

It does **not** call:

```bash
php bin/jinx
```

## What it measures

The benchmark has two sides:

1. **PHP side**: direct PHP builtin calls with deterministic sample parameters.
2. **JINX side**: native C Oracle/PASM dispatch through the built `./jinx` executable.

The PHP side validates that its parameter set can be called in the local PHP runtime before timing. The native side uses the first native benchmarkable Oracle wrapper set exposed by `./jinx first100-list` and timed by `./jinx bench-first100`.

## Fast rerun without rebuilding

After `./jinx` has already been built:

```bash
JINX_SKIP_BUILD=1 php scripts/benchmark-native-jinx-vs-php.php 100000
```

## Smoke checks

```bash
./jinx oracle-smoke
./jinx functions-smoke
./jinx first100
./jinx bench-first100 100000
```

`functions-smoke` verifies that the generated native Oracle dispatch table has a wrapper for every generated function name. `first100` executes the native benchmark sample set. `bench-first100` times that same native sample set.
