# Oracle benchmark commands

There are two benchmark layers. Use the one that matches the question.

## 1. Harness/process benchmark

`scripts/benchmark-oracle-families.php` measures the full verification path for every executable Oracle family. It launches each parity test through direct PHP and through repository-root native `./jinx`.

This answers:

```text
How fast does the whole parity verification harness run?
```

It includes process startup, script loading, parser/compiler setup, fixture execution, Oracle execution, and parity assertions. It is broad and honest, but it hides worker-level speed because startup and test harness cost dominate small functions.

Run it from a clean checkout after building native `./jinx`:

```bash
git pull origin master
./scripts/build-native-jinx.sh
./jinx scripts/benchmark-oracle-families.php --iterations=5
git diff --check
```

Save machine-readable JSON:

```bash
./jinx scripts/benchmark-oracle-families.php --iterations=5 --json=build/benchmarks/oracle-family-benchmark.json
```

Focus one family or group:

```bash
./jinx scripts/benchmark-oracle-families.php --only=text --iterations=10
./jinx scripts/benchmark-oracle-families.php --only=chr-builtins --iterations=10
./jinx scripts/benchmark-oracle-families.php --only=scripts/test-oracle-math-builtin-execution.php --iterations=10
```

Useful options:

```text
--iterations=N    measured runs per side, default 3
--warmup=N        warmup runs per side, default 1
--json=PATH       write JSON result payload
--only=FILTER     benchmark matching family or parity test
--fail-fast       stop at the first failed group
```

## 2. Worker/hot benchmark

`scripts/benchmark-oracle-worker-hot.php` measures the in-process worker path. It compiles each selected fixture once, warms the worker, then loops inside the same running `./jinx` process.

This answers:

```text
How fast is the already-running Oracle worker path after startup/compiler overhead is removed?
```

This is the benchmark layer where prior worker-level speedups, including near-30x measurements, should be visible again.

Run all representative hot cases:

```bash
./jinx scripts/benchmark-oracle-worker-hot.php --iterations=1000 --warmup=100
```

Save JSON:

```bash
./jinx scripts/benchmark-oracle-worker-hot.php --iterations=1000 --warmup=100 --json=build/benchmarks/oracle-worker-hot.json
```

Focus one group:

```bash
./jinx scripts/benchmark-oracle-worker-hot.php --only=text --iterations=10000
./jinx scripts/benchmark-oracle-worker-hot.php --only=math --iterations=10000
./jinx scripts/benchmark-oracle-worker-hot.php --only=data --iterations=10000
./jinx scripts/benchmark-oracle-worker-hot.php --only=chr-builtins --iterations=10000
```

Worker/hot output reports `PHP/Oracle`. Values above `1.00x` mean the Oracle worker loop was faster than repeatedly requiring the equivalent PHP fixture in the same process.

## Interpreting both numbers

Use the harness/process benchmark to catch broad regressions in the complete toolchain.

Use the worker/hot benchmark when checking the executor-level speed path. The worker benchmark avoids the problem where thousands of tiny function calls are drowned by shell process startup and parity-test bookkeeping.

Neither benchmark claims final PASM/native-code performance yet. PASM lowering should get its own benchmark once the PHP-to-PASM path executes the same fixtures without the Oracle interpreter layer.
