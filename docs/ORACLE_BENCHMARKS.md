# Oracle benchmark commands

There are three benchmark layers. Use the one that matches the question.

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

## 3. Warmed web-request worker benchmark

`scripts/benchmark-web-request-worker.php` measures a web-shaped request path in one already-running process. It compiles `fixtures/simple-web-api-validated.php` once into a JINX web executable plan, warms it, then runs many simulated JSON requests through both:

1. an equivalent PHP route worker
2. the compiled JINX web plan worker

This answers:

```text
Can a warmed JINX worker serve repeated web-style requests faster than equivalent warmed PHP route logic?
```

Run it:

```bash
./jinx scripts/benchmark-web-request-worker.php --requests=10000 --warmup=500
```

Save JSON:

```bash
./jinx scripts/benchmark-web-request-worker.php --requests=10000 --warmup=500 --json=build/benchmarks/web-request-worker.json
```

Use a different supported web fixture:

```bash
./jinx scripts/benchmark-web-request-worker.php --fixture=fixtures/simple-web-api-validated.php --requests=50000
```

The output reports total time, average microseconds per request, p95 microseconds, requests per second, and a PHP/JINX speed ratio. The benchmark varies request bodies and verifies a checksum so the worker cannot get a good result by reusing one cached output.

## Interpreting the numbers

Use the harness/process benchmark to catch broad regressions in the complete toolchain.

Use the worker/hot benchmark when checking executor-level speed. It avoids the problem where tiny function calls are drowned by shell process startup and parity-test bookkeeping.

Use the warmed web-request worker benchmark for the internet/server question. It is closer to a persistent web process because it removes command startup while still measuring request-shaped JSON body parsing, validation, status selection, and response serialization.

None of these benchmarks claims final PASM/native-code performance yet. PASM lowering should get its own benchmark once the PHP-to-PASM path executes the same fixtures without the Oracle interpreter layer.
