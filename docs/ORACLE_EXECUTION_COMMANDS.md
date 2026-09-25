# Oracle execution commands

Oracle is the PHP/Zend mirroring and execution layer. PASM/native output stays secondary until the Oracle layer proves behavior against PHP.

Every executable family listed here has a runtime owner and a PHP parity comparison test. The native suite runs a coverage audit that checks the ledger, parity tests, native suite wiring, and this document stay synchronized.

## Native `./jinx` verification

```bash
git pull origin master
./scripts/build-native-jinx.sh
./jinx scripts/test-jinx-native-suite.php
git diff --check
```

Do not run family tests with `php scripts/...` for release verification. Launch them through repository-root native `./jinx`.

Run the facet audit when checking whether every declared family facet is represented by its parity test:

```bash
./jinx scripts/test-oracle-family-facet-audit.php
```

## Native benchmarks

Use the harness/process benchmark to time every executable family parity path against the PHP baseline and native `./jinx` path:

```bash
./jinx scripts/benchmark-oracle-families.php --iterations=5
./jinx scripts/benchmark-oracle-families.php --iterations=5 --json=build/benchmarks/oracle-family-benchmark.json
```

Use the worker/hot benchmark when checking the speed path where startup and test-harness overhead are removed:

```bash
./jinx scripts/benchmark-oracle-worker-hot.php --iterations=1000 --warmup=100
./jinx scripts/benchmark-oracle-worker-hot.php --only=text --iterations=10000
./jinx scripts/benchmark-oracle-worker-hot.php --only=chr-builtins --iterations=10000
```

Use the web back-page hot benchmark for the 89x-style web request aura. PHP stays direct route logic; JINX can run the raw route template or the full response-envelope bridge. Use `--frame-cap` to prebuild and replay a capped shared frame deck so frame construction does not bend the timed section toward PHP. Use `--frame-level=high` to give JINX a typed near-high-level route frame while PHP keeps the normal raw request body path:

```bash
./jinx scripts/benchmark-web-back-page-hot.php --requests=100000 --warmup=1000 --jinx-mode=raw-template
./jinx scripts/benchmark-web-back-page-hot.php --workload=large --requests=100000 --warmup=1000 --frame-cap=256 --frame-level=high --jinx-mode=raw-template
./jinx scripts/benchmark-web-back-page-hot.php --requests=100000 --warmup=1000 --jinx-mode=bridge
./jinx scripts/benchmark-web-back-page-hot.php --workload=large --requests=100000 --warmup=1000 --frame-cap=256 --frame-level=high --jinx-mode=raw-template --json=build/benchmarks/web-back-page-hot-large.json
```

Use the warmed web-request worker benchmark for route logic without socket overhead:

```bash
./jinx scripts/benchmark-web-request-worker.php --requests=10000 --warmup=500
./jinx scripts/benchmark-web-request-worker.php --requests=10000 --warmup=500 --json=build/benchmarks/web-request-worker.json
```

Use the fair live HTTP benchmark when PHP and JINX should both look live over loopback HTTP. The JINX live worker uses `runtime/WebBackPageBridge.php`: HTTP front request in, back-page envelope execution, response envelope back out. The default JINX live worker mode is the optimized direct-response-template path:

```bash
./jinx scripts/benchmark-live-web-requests.php --requests=10000 --warmup=500 --jinx-mode=fast-template
./jinx scripts/benchmark-live-web-requests.php --requests=10000 --warmup=500 --jinx-mode=plan
./jinx scripts/benchmark-live-web-requests.php --requests=10000 --warmup=500 --jinx-mode=fast-template --json=build/benchmarks/live-web-requests.json
```

Use the fair live keep-alive HTTP benchmark when PHP and JINX should both look live but reuse one persistent socket per worker:

```bash
./jinx scripts/benchmark-live-web-keepalive.php --requests=10000 --warmup=500 --jinx-mode=fast-template
./jinx scripts/benchmark-live-web-keepalive.php --requests=10000 --warmup=500 --jinx-mode=plan
./jinx scripts/benchmark-live-web-keepalive.php --requests=10000 --warmup=500 --jinx-mode=fast-template --json=build/benchmarks/live-web-keepalive.json
```

See `docs/ORACLE_BENCHMARKS.md` for benchmark options, JSON output, and interpretation notes.

## Core language/runtime families

Runtime owners are the corresponding files under `runtime/`. PHP comparison tests are shown beside each family.

| Family | PHP comparison test |
|---|---|
| `straight-line` | `scripts/test-oracle-straightline-execution.php` |
| `string-interpolation` | `scripts/test-oracle-string-interpolation-execution.php` |
| `conditionals` | `scripts/test-oracle-conditional-execution.php` |
| `loops` | `scripts/test-oracle-loop-execution.php` |
| `arrays` | `scripts/test-oracle-array-execution.php` |
| `functions` | `scripts/test-oracle-function-execution.php` |
| `request-globals` | `scripts/test-oracle-request-globals-execution.php` |
| `include-require` | `scripts/test-oracle-include-require-execution.php` |
| `exit-die` | `scripts/test-oracle-exit-die-execution.php` |
| `object-basics` | `scripts/test-oracle-object-basics-execution.php` |
| `object-inheritance` | `scripts/test-oracle-object-inheritance-execution.php` |

String interpolation has additional facet coverage for evaluator-level PHP forms that are not all represented in the straight-line source fixture:

```bash
./jinx scripts/test-oracle-string-interpolation-facets.php
./jinx scripts/test-oracle-string-interpolation-coalesced.php
```

The facet test covers plain double-quoted strings, simple `$name`, braced `{$name}`, unbraced `$row[name]`, braced `{$row['name']}`, braced `{$row["name"]}`, dynamic `{$row[$key]}`, numeric offsets, nested offsets, escaped dollars, newline/tab escapes, concatenation, builtin `strlen()`, and missing-local/dimension failures.

## Expression/control-flow batch

Runtime owner: `runtime/OracleExpressionBatchExecutor.php`. PHP comparison test: `scripts/test-oracle-next-ten-execution.php`.

`ternary-expressions`, `type-casts`, `string-builtins`, `math-builtins`, `comparison-expressions`, `boolean-expressions`, `magic-constants`, `array-literals`, `foreach-loops`, `for-loops`.

## Builtin batch one

Runtime owner: `runtime/OracleBuiltinBatchExecutor.php`. PHP comparison test: `scripts/test-oracle-builtin-batch-execution.php`.

`str-replace-builtins`, `strpos-builtins`, `explode-builtins`, `in-array-builtins`, `array-key-exists-builtins`, `array-merge-builtins`, `array-reverse-builtins`, `array-unique-builtins`, `json-encode-builtins`, `hash-builtins`.

## Builtin batch two

Runtime owner: `runtime/OracleBuiltinBatchExecutor.php`. PHP comparison test: `scripts/test-oracle-builtin-batch-two-execution.php`.

`ltrim-builtins`, `rtrim-builtins`, `ucfirst-builtins`, `lcfirst-builtins`, `strrev-builtins`, `str-repeat-builtins`, `str-pad-builtins`, `array-keys-builtins`, `array-values-builtins`, `array-slice-builtins`.

## Scalar/type builtin batch

Runtime owner: `runtime/OracleScalarBuiltinExecutor.php`. PHP comparison test: `scripts/test-oracle-scalar-builtin-execution.php`.

`is-string-builtins`, `is-int-builtins`, `is-array-builtins`, `is-bool-builtins`, `is-null-builtins`, `intval-builtins`, `strval-builtins`, `boolval-builtins`, `floatval-builtins`, `is-numeric-builtins`.

## App builtin batch

Runtime owner: `runtime/OracleAppBuiltinExecutor.php`. PHP comparison test: `scripts/test-oracle-app-builtin-execution.php`.

`str-contains-builtins`, `str-starts-with-builtins`, `str-ends-with-builtins`, `stripos-builtins`, `strrpos-builtins`, `strstr-builtins`, `substr-count-builtins`, `wordwrap-builtins`, `sprintf-builtins`, `number-format-builtins`, `urlencode-builtins`, `urldecode-builtins`, `rawurlencode-builtins`, `rawurldecode-builtins`, `http-build-query-builtins`, `parse-url-builtins`, `htmlspecialchars-builtins`, `html-entity-decode-builtins`, `strip-tags-builtins`, `nl2br-builtins`, `array-combine-builtins`, `array-flip-builtins`, `array-diff-builtins`, `array-intersect-builtins`, `array-search-builtins`, `array-column-builtins`, `array-chunk-builtins`, `range-builtins`, `array-change-key-case-builtins`, `array-fill-builtins`.

## Math/numeric builtin batch

Runtime owner: `runtime/OracleMathBuiltinExecutor.php`. PHP comparison test: `scripts/test-oracle-math-builtin-execution.php`.

`floor-builtins`, `ceil-builtins`, `sqrt-builtins`, `pow-builtins`, `fmod-builtins`, `intdiv-builtins`, `deg2rad-builtins`, `rad2deg-builtins`, `sin-builtins`, `cos-builtins`, `tan-builtins`, `asin-builtins`, `acos-builtins`, `atan-builtins`, `log-builtins`, `exp-builtins`, `pi-builtins`, `hypot-builtins`, `is-finite-builtins`, `is-infinite-builtins`, `is-nan-builtins`.

## Data/encoding/introspection builtin batch

Runtime owner: `runtime/OracleDataBuiltinExecutor.php`. PHP comparison test: `scripts/test-oracle-data-builtin-execution.php`.

`base64-encode-builtins`, `base64-decode-builtins`, `bin2hex-builtins`, `hex2bin-builtins`, `sha1-builtins`, `crc32-builtins`, `hash-generic-builtins`, `hash-hmac-builtins`, `serialize-builtins`, `unserialize-builtins`, `var-export-builtins`, `print-r-builtins`, `gettype-builtins`, `is-scalar-builtins`, `is-countable-builtins`, `sizeof-builtins`, `array-sum-builtins`, `array-product-builtins`, `str-split-builtins`, `chunk-split-builtins`.

## Data/encoding/introspection builtin batch two

Runtime owner: `runtime/OracleDataBuiltinExecutor.php`. PHP comparison test: `scripts/test-oracle-data-builtin-two-execution.php`.

`join-builtins`, `count-builtins`, `array-count-values-builtins`, `array-pad-builtins`, `array-replace-builtins`, `array-replace-recursive-builtins`, `array-is-list-builtins`, `array-filter-builtins`, `quoted-printable-encode-builtins`, `quoted-printable-decode-builtins`, `convert-uuencode-builtins`, `convert-uudecode-builtins`, `pack-builtins`, `unpack-builtins`, `decbin-builtins`, `dechex-builtins`, `decoct-builtins`, `bindec-builtins`, `hexdec-builtins`, `base-convert-builtins`.

## Text/string builtin batch

Runtime owner: `runtime/OracleTextBuiltinExecutor.php`. PHP comparison test: `scripts/test-oracle-text-builtin-execution.php`.

`chr-builtins`, `ord-builtins`, `strcmp-builtins`, `strcasecmp-builtins`, `strncmp-builtins`, `strncasecmp-builtins`, `substr-compare-builtins`, `similar-text-builtins`, `levenshtein-builtins`, `soundex-builtins`, `metaphone-builtins`, `str-rot13-builtins`, `addslashes-builtins`, `stripslashes-builtins`, `quotemeta-builtins`, `addcslashes-builtins`, `substr-replace-builtins`, `strtr-builtins`, `str-getcsv-builtins`, `str-word-count-builtins`.

## Text/string builtin batch two

Runtime owner: `runtime/OracleTextBuiltinExecutor.php`. PHP comparison test: `scripts/test-oracle-text-builtin-two-execution.php`.

`strlen-builtins`, `strtolower-builtins`, `strtoupper-builtins`, `trim-builtins`, `substr-builtins`, `basename-builtins`, `dirname-builtins`, `ucwords-builtins`, `stripcslashes-builtins`, `ctype-alnum-builtins`, `ctype-alpha-builtins`, `ctype-cntrl-builtins`, `ctype-digit-builtins`, `ctype-graph-builtins`, `ctype-lower-builtins`, `ctype-print-builtins`, `ctype-punct-builtins`, `ctype-space-builtins`, `ctype-upper-builtins`, `ctype-xdigit-builtins`.

## Coverage rule

Do not mark a PHP/Zend behavior executable until an Oracle runtime owner actually runs it and a PHP comparison test proves parity for captured output, returned value, thrown error status/message class shape, and exit behavior where applicable.
