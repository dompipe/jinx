# Oracle benchmark commands

There are six benchmark layers. Use the one that matches the question.

## 1. Harness/process benchmark

`scripts/benchmark-oracle-families.php` measures the full verification path for every executable Oracle family. It launches each parity test through direct PHP and through repository-root native `./jinx`.

This answers:

```text
How fast does the whole parity verification harness run?
```

It includes process startup, script loading, parser/compiler setup, fixture execution, Oracle execution, and parity assertions. It is broad and honest, but it hides worker-level speed because startup and test harness cost dominate small functions.

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

## 2. Worker/hot benchmark

`scripts/benchmark-oracle-worker-hot.php` measures the in-process worker path. It compiles each selected fixture once, warms the worker, then loops inside the same running `./jinx` process.

This answers:

```text
How fast is the already-running Oracle worker path after startup/compiler overhead is removed?
```

```bash
./jinx scripts/benchmark-oracle-worker-hot.php --iterations=1000 --warmup=100
./jinx scripts/benchmark-oracle-worker-hot.php --only=text --iterations=10000
./jinx scripts/benchmark-oracle-worker-hot.php --only=chr-builtins --iterations=10000
```

Worker/hot output reports `PHP/Oracle`. Values above `1.00x` mean the Oracle worker loop was faster than repeatedly requiring the equivalent PHP fixture in the same process.

## 3. Web back-page hot benchmark

`scripts/benchmark-web-back-page-hot.php` is the web request benchmark that matches the 89x worker aura. PHP stays as the direct route baseline. JINX can run the raw route body, the full response-envelope bridge, or a near-high-level typed frame inside one already-running process.

This answers:

```text
How fast is JINX's web-shaped route path compared with direct PHP route logic when both are kept off the HTTP socket path?
```

Run the fastest small raw-template JINX mode:

```bash
./jinx scripts/benchmark-web-back-page-hot.php --requests=100000 --warmup=1000 --jinx-mode=raw-template
```

Run the large route workload with a capped shared raw frame deck. This prebuilds request frames once, replays the same frames through PHP and JINX, and keeps frame construction out of both timed sections:

```bash
./jinx scripts/benchmark-web-back-page-hot.php --workload=large --requests=100000 --warmup=1000 --frame-cap=256 --jinx-mode=raw-template
```

Run the high-level typed frame path. PHP still uses direct JSON route logic; JINX uses the prebuilt high frame with typed route fields, so it avoids JSON parsing in the hot loop:

```bash
./jinx scripts/benchmark-web-back-page-hot.php --workload=large --requests=100000 --warmup=1000 --frame-cap=256 --frame-level=high --jinx-mode=raw-template
```

Compare raw large frames against high large frames:

```bash
./jinx scripts/benchmark-web-back-page-hot.php --workload=large --requests=100000 --warmup=1000 --frame-cap=256 --frame-level=raw --jinx-mode=raw-template
./jinx scripts/benchmark-web-back-page-hot.php --workload=large --requests=100000 --warmup=1000 --frame-cap=256 --frame-level=high --jinx-mode=raw-template
```

Compare the small route against the full back-page response-envelope bridge:

```bash
./jinx scripts/benchmark-web-back-page-hot.php --requests=100000 --warmup=1000 --jinx-mode=bridge
```

Save JSON:

```bash
./jinx scripts/benchmark-web-back-page-hot.php --workload=large --requests=100000 --warmup=1000 --frame-cap=256 --frame-level=high --jinx-mode=raw-template --json=build/benchmarks/web-back-page-hot-large-high.json
```

This is the benchmark to use when checking whether web requests can return to the same style as the 89x worker result: PHP direct route baseline, JINX precompiled back-page path, single process, prebuilt shared frames, no socket timing, and no process-spawn timing.

## 4. Warmed web-request worker benchmark

`scripts/benchmark-web-request-worker.php` measures a web-shaped request path in one already-running process. It compiles `fixtures/simple-web-api-validated.php` once into a JINX web executable plan, warms it, then runs many simulated JSON requests through both:

1. an equivalent PHP route worker
2. the compiled JINX web plan worker

This answers:

```text
Can a warmed JINX worker serve repeated web-style requests faster than equivalent warmed PHP route logic?
```

```bash
./jinx scripts/benchmark-web-request-worker.php --requests=10000 --warmup=500
./jinx scripts/benchmark-web-request-worker.php --requests=10000 --warmup=500 --json=build/benchmarks/web-request-worker.json
```

## 5. Fair live HTTP request benchmark

`scripts/benchmark-live-web-requests.php` starts two actual loopback HTTP workers:

1. `scripts/serve-php-web-worker.php` through PHP
2. `scripts/serve-jinx-web-worker.php` through repository-root native `./jinx`

Both workers use the same tiny socket-server harness. The PHP worker runs the PHP route logic directly. The JINX worker compiles `fixtures/simple-web-api-validated.php` once at startup and serves the compiled web plan. The benchmark then sends identical live HTTP POST requests to both workers and compares status/body checksums.

This answers:

```text
When PHP and JINX both look like live warmed web workers, which responds faster over real local HTTP when each measured request opens and closes a socket?
```

Run the optimized JINX direct-response-template path:

```bash
./jinx scripts/benchmark-live-web-requests.php --requests=10000 --warmup=500 --jinx-mode=fast-template
```

Compare against the older generic web-plan interpreter path:

```bash
./jinx scripts/benchmark-live-web-requests.php --requests=10000 --warmup=500 --jinx-mode=plan
```

## 6. Fair live keep-alive HTTP request benchmark

`scripts/benchmark-live-web-keepalive.php` starts the same two live loopback HTTP workers, but it keeps one TCP socket open to each worker and sends all warmup and measured POST requests over those persistent sockets.

This answers:

```text
When PHP and JINX both look live and connection churn is removed, can JINX carry the worker-level speed advantage across web requests?
```

Run the optimized JINX direct-response-template path over keep-alive:

```bash
./jinx scripts/benchmark-live-web-keepalive.php --requests=10000 --warmup=500 --jinx-mode=fast-template
```

Compare against the generic web-plan interpreter path over keep-alive:

```bash
./jinx scripts/benchmark-live-web-keepalive.php --requests=10000 --warmup=500 --jinx-mode=plan
```

Save JSON:

```bash
./jinx scripts/benchmark-live-web-keepalive.php --requests=10000 --warmup=500 --jinx-mode=fast-template --json=build/benchmarks/live-web-keepalive.json
```

Use alternate ports if the defaults are busy:

```bash
./jinx scripts/benchmark-live-web-keepalive.php --php-port=18180 --jinx-port=18181 --requests=10000 --warmup=500 --jinx-mode=fast-template
```

If a worker cannot start, the benchmark waits for the health endpoint and prints captured stdout/stderr plus process status.

The keep-alive benchmark reports average latency, p95 latency, min/max latency, requests per second, response checksums, and the PHP/JINX average latency ratio. Values above `1.00x` for `PHP/JINX avg latency ratio` mean the JINX live worker was faster.

## Back-page request bridge

The JINX live worker now uses a front/back split:

```text
HTTP front page -> back-page request envelope -> WebBackPageBridge -> response envelope -> HTTP response
```

The input envelope is the internal equivalent of `php://input` plus request metadata:

```json
{"method":"POST","path":"/api","headers":{"content-type":"application/json"},"body":"{\"name\":\"jinx\"}"}
```

The output envelope is what the front server writes back:

```json
{"status":200,"headers":{"Content-Type":"application/json"},"body":"{\"ok\":true,\"name\":\"jinx\"}"}
```

The goal is to move route execution off the HTTP path while keeping the server result-shaped: the front server sees one response envelope and does not need to know how the route was executed.

## Why 89x may not show on connection-per-request HTTP yet

An 89x executor advantage can disappear when every measured request still pays shared costs:

```text
TCP connect
HTTP header parse
Content-Length read
response header write
connection close
process-level PHP socket functions
client fsockopen cost
```

The connection-close live benchmark is fair because both sides pay those costs, but those costs also create a speed ceiling. The keep-alive benchmark removes connection churn. To push toward an 89x route-execution advantage across live web requests after keep-alive, the next layer is HTTP pipelining/batching and then a native socket loop rather than a worker script implemented in PHP.

## Interpreting the numbers

Use the harness/process benchmark to catch broad regressions in the complete toolchain.

Use the worker/hot benchmark when checking executor-level speed. It avoids the problem where tiny function calls are drowned by shell process startup and parity-test bookkeeping.

Use the web back-page hot benchmark for the 89x-style route-engine comparison: PHP direct route logic versus JINX's precompiled back-page path.

Use the warmed web-request worker benchmark for route logic without actual socket overhead.

Use the fair live HTTP request benchmark for connection-close server behavior.

Use the fair live keep-alive benchmark for persistent internet/server behavior: both sides are live workers, both receive loopback HTTP requests, both reuse one connection, and both produce comparable HTTP responses.

None of these benchmarks claims final PASM/native-code performance yet. PASM lowering should get its own benchmark once the PHP-to-PASM path executes the same fixtures without the Oracle interpreter layer.
