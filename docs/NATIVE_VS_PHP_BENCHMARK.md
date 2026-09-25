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

## Run the all-functions benchmark

```bash
php scripts/benchmark-native-jinx-vs-php.php 1000
```

The benchmark driver intentionally calls:

```bash
./jinx functions
./jinx bench-all-functions <iterations>
```

It does **not** call:

```bash
php bin/jinx
```

## Direct native command

After building, you can time the native all-functions traversal without the PHP comparison wrapper:

```bash
./jinx bench-all-functions 1000
```

For a strict native check that fails if any generated function returns a null/fault placeholder:

```bash
./jinx bench-all-functions 1000 --strict
```

## What it measures

The benchmark has two sides:

1. **PHP side**: direct PHP builtin calls with deterministic sample parameters, only for names that are global PHP functions in the current PHP runtime and survive parameter validation.
2. **JINX side**: every generated function name is run through the native `./jinx` C Oracle/PASM dispatch path with deterministic sample arguments.

The native all-functions path reports:

- generated function count;
- wrapper-dispatch presence;
- concrete non-null first-pass returns;
- null/fault placeholder first-pass returns;
- elapsed time and nanoseconds per dispatch.

The null/fault count is expected until the C Oracle runtime has full behavioral handlers for every imported PHP builtin. It still benchmarks the full generated native dispatch traversal with sample argument slots filled so the wrappers are not starved of parameters.

## Fast rerun without rebuilding

After `./jinx` has already been built:

```bash
JINX_SKIP_BUILD=1 php scripts/benchmark-native-jinx-vs-php.php 1000
```

## Smoke checks

```bash
./jinx oracle-smoke
./jinx functions-smoke
./jinx first100
./jinx bench-first100 100000
./jinx bench-all-functions 1000
```

`functions-smoke` verifies that the generated native Oracle dispatch table has a wrapper for every generated function name. `first100` executes the native benchmark sample set. `bench-first100` times that same native sample set. `bench-all-functions` traverses every generated native wrapper through `./jinx`.
