# Oracle benchmark commands

This benchmark measures every executable Oracle family that has been added so far by using the Oracle execution family ledger as the source of truth.

It compares two paths:

1. **PHP baseline**: runs each parity test directly through PHP.
2. **Native JINX path**: runs the same parity test through repository-root native `./jinx`.

The benchmark groups families by their PHP parity test, because many builtin families are intentionally tested in batches.

## Run the full benchmark

Run from a clean checkout after building native `./jinx`:

```bash
git pull origin master
./scripts/build-native-jinx.sh
./jinx scripts/benchmark-oracle-families.php --iterations=5
git diff --check
```

The benchmark script itself must be launched through `./jinx`. Inside the benchmark, the PHP baseline is measured separately and the JINX path is measured by launching repository-root `./jinx` for each parity test.

## Save machine-readable JSON

```bash
./jinx scripts/benchmark-oracle-families.php --iterations=5 --json=build/benchmarks/oracle-family-benchmark.json
```

The JSON includes:

- total executable family count
- benchmarked family count
- parity test group count
- PHP average/best/run timings
- JINX average/best/run timings
- JINX-over-PHP ratio per group
- aggregate timing totals

## Focus one family or group

Use `--only=` with a family name, test path, or part of a test path:

```bash
./jinx scripts/benchmark-oracle-families.php --only=text --iterations=10
./jinx scripts/benchmark-oracle-families.php --only=chr-builtins --iterations=10
./jinx scripts/benchmark-oracle-families.php --only=scripts/test-oracle-math-builtin-execution.php --iterations=10
```

## Useful options

```text
--iterations=N    measured runs per side, default 3
--warmup=N        warmup runs per side, default 1
--json=PATH       write JSON result payload
--only=FILTER     benchmark matching family or parity test
--fail-fast       stop at the first failed group
```

## Interpreting the results

The benchmark reports process-level wall-clock time. That means it includes startup cost, parser/compiler setup, and the full parity harness work. It is useful for comparing the current native `./jinx` path against direct PHP for the same verification workload.

For lower-level opcode or builtin microbenchmarks, add a dedicated fixture runner later that loops inside one process. This benchmark is the broad coverage benchmark for everything executable so far.
