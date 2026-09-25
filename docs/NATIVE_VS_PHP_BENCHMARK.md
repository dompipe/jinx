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

for the JINX timing path.

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
- total dispatches;
- elapsed time;
- nanoseconds per dispatch.

The null/fault count is expected until the C Oracle runtime has full behavioral handlers for every imported PHP builtin. It still benchmarks the full generated native dispatch traversal with sample argument slots filled so the wrappers are not starved of parameters.

## Output shape

Native side:

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

Comparison wrapper:

```text
PHP vs native ./jinx all-functions benchmark
Build path: scripts/build-native-jinx.sh -> ./jinx
Native names: 3527 generated names from ./jinx functions
PHP cases: <count>/3527 global functions parameter-validated in this PHP runtime
Iterations per function: 1000

engine                  functions        ns/call      ratio
--------------------------------------------------------------
php                       <count>          <ns>       1.00x
./jinx native               3527          <ns>       <x>x
```

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

`functions-smoke` verifies that the generated native Oracle dispatch table has a wrapper for every generated function name.

`first100` executes the native benchmark sample set.

`bench-first100` times that same native sample set.

`bench-all-functions` traverses every generated native wrapper through `./jinx`.

## Important distinction

`functions-smoke` and `bench-all-functions` prove native dispatch coverage, not complete PHP behavioral parity.

The current native layer can traverse all generated wrappers. It reports concrete non-null returns separately from placeholder null/fault returns so the next work is visible: replacing placeholders with real C Oracle/PASM behavior for more PHP builtins.

Use `--strict` when you want the command to fail until every generated function has concrete behavior.
