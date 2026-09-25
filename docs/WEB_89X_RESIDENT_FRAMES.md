# 89x resident high-frame benchmark

Use this benchmark when the question is specifically:

```text
What happens when JINX keeps 256 high-level frames resident for the whole time the native ./jinx process is running?
```

This is not the fairness/raw-envelope benchmark. It is the 89x path: one running JINX process, one resident typed frame deck, and no frame rebuild or JSON parse in the JINX hot loop.

## Run

```bash
git pull origin master
./scripts/build-native-jinx.sh
./jinx scripts/benchmark-web-89x-resident-frames.php --requests=100000 --warmup=1000 --frame-cap=256 --workload=large
git diff --check
```

Save JSON:

```bash
./jinx scripts/benchmark-web-89x-resident-frames.php --requests=100000 --warmup=1000 --frame-cap=256 --workload=large --json=build/benchmarks/web-89x-resident-frames.json
```

## What it does

```text
JINX process starts
build resident high frame deck once: 256 frames
warmup reuses that same deck
measured requests reuse that same deck
JINX route reads typed high frame fields directly
JINX hot loop does not rebuild frames
JINX hot loop does not parse JSON request bodies
```

The output prints:

```text
Resident high frame deck: 256
JINX frame residency: whole process lifetime
PHP-direct/JINX-89x ratio: Nx
```

Values above `1.00x` mean the resident-frame JINX route loop is faster than the PHP direct route baseline.

## Why this exists

The normal web benchmarks are fair transport/request benchmarks. They keep PHP and JINX on the same request shape, which is right for server fairness but not for the 89x idea.

The 89x idea is different: JINX should run with the route state and typed request frames already hot. That means the benchmark has to keep the 256 high frames resident for the full JINX runtime instead of treating frame generation as part of the request path.
