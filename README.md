# dompipe/jinx RC

This is a release-candidate source package for the dompipe/JINX worker and web compiler branch.

The end goal is to rewrite the php-src behavior surface in Oracle: PHP commands, language constructs, builtins, extension calls, loader paths, and arbitrary PHP execution should enter Oracle and run faster while preserving PHP/Zend behavior. Anything not represented in Oracle yet is incomplete coverage, not a different architecture.

Literal local `require`, `require_once`, `include`, and `include_once` statements are Oracle entry points. When a target can be resolved safely, JINX interprets the included PHP file into Oracle records. Dynamic targets, unresolved paths, and include cycles stay PHP-compatible and require fallback.

Oracle is the interpreter/mirroring layer for php-src behavior. PASM/native binary emission is a later output path after Oracle owns the PHP/Zend behavior; it should not block arbitrary PHP/Zend coverage.

The RC focuses on:

- JINX web compilation for validated JSON endpoints.
- Worker-style native wrapper registration for the imported PHP runtime surface.
- A generated compact wrapper dispatch table for 3,527 PHP callable signatures.
- A native GCC `./jinx` executable that exercises the Oracle dispatch layer.
- Native `oracle-call` support for every generated PHP callable name in the native dispatch table.
- A native Zend-shaped engine skeleton for rewriting `Zend/` concepts into Oracle interpreter records and acceleration targets.
- Manual-driven handler states: `exact`, `php-fallback`, and `sandbox-blocked` are resolved; `partial`, `placeholder`, and `unsafe-native` are not.
- PHP-source implementation families that map generated callables to Oracle/PASM target families and php-src source areas.
- Correctness-first PHP fallback for crypto/hash/password/random functions until exact native crypto exists.
- True all-functions benchmark tooling that runs every generated JINX function `X` times and direct PHP benchmarkable functions `X` times.
- Native benchmarks for first-100 and all-functions Oracle dispatch traversal.
- PHP comparison benchmarks for validated callable builtin cases.

## Quick Native Commands

Build the native executable first. This creates the repository-root `./jinx` binary, copies it to `./build/native/jinx`, and builds the Zend smoke binary.

```bash
./scripts/build-native-jinx.sh
./jinx rc
./jinx oracle-smoke
./build/native/jinx-zend-smoke
./jinx functions-count
./jinx functions-smoke
./jinx function-exists strlen
./jinx oracle-call strlen s:oracle
./jinx oracle-call abs i:-42
./jinx oracle-call str_contains s:dompipe s:pipe
./jinx oracle-call array_values a:4
./jinx first100
./jinx bench-first100 100000
./jinx bench-all-functions 1000
./jinx scripts/test-oracle-program-compiler.php
./jinx scripts/test-zend-arbitrary-code-oracle.php
./jinx scripts/test-zend-runtime-ops-oracle.php
./jinx scripts/test-zend-declaration-metadata-oracle.php
php scripts/report-php-families.php
php scripts/benchmark-true-all-functions.php 1000
./jinx notes
./jinx benchmarks
```

Strict all-functions traversal:

```bash
./jinx bench-all-functions 1000 --strict
```

Manual-resolution gate:

```bash
php scripts/check-native-manual-complete.php
```

The gate now passes only when every manifest entry is resolved as one of:

```text
exact
php-fallback
sandbox-blocked
```

It fails if anything remains `partial`, `placeholder`, or `unsafe-native`. A pass means there are no ambiguous under-construction buckets left. It does **not** mean every PHP function is native ASM; `php-fallback` explicitly means original PHP is still used for correctness.

Crypto fallback regression test:

```bash
php scripts/test-php-crypto-fallback.php
```

## Zend Engine Rewrite Layer

The Zend rewrite starts with a JINX-owned native engine layer instead of linking against PHP. This gives Oracle a stable interpreter and acceleration target for PHP's core runtime concepts. PASM can consume proven Oracle-owned behavior later, but it is not the gate for Zend mirroring.

Native sources:

```text
runtime/jinx_zend_engine.h
runtime/jinx_zend_engine.c
native/jinx_zend_smoke.c
docs/ZEND_ENGINE_REWRITE.md
```

Build and smoke test:

```bash
./scripts/build-native-jinx.sh
./build/native/jinx-zend-smoke
```

Current Zend-shaped native structures:

```text
JinxZendValue       zval-like tagged value
JinxZendString      zend_string-like string view
JinxZendArray       HashTable/zend_array count shell
JinxZendObject      object/class shell
JinxZendReference   reference shell
JinxZendCallFrame   function call frame shell
JinxZendExecutor    executor/request state shell
```

Zend rewrite families now have explicit targets:

| Family | State | Oracle SM target | PASM target |
|---|---:|---|---|
| zval | started | `oracle-sm/zend/zval.osm` | `runtime/pasm/zend/zval.pasm` |
| zend_string | started | `oracle-sm/zend/string.osm` | `runtime/pasm/zend/string.pasm` |
| HashTable/zend_array | planned | `oracle-sm/zend/hash.osm` | `runtime/pasm/zend/hash.pasm` |
| executor/call-frame | started | `oracle-sm/zend/executor.osm` | `runtime/pasm/zend/executor.pasm` |
| objects/classes | planned | `oracle-sm/zend/object.osm` | `runtime/pasm/zend/object.pasm` |
| errors/exceptions | planned | `oracle-sm/zend/errors.osm` | `runtime/pasm/zend/errors.pasm` |
| compiler/opcodes | planned | `oracle-sm/zend/opcodes.osm` | `runtime/pasm/zend/opcodes.pasm` |

## PHP Source Families

The flat generated callable list is now mapped into implementation families. This is the route from `php-src` behavior to Oracle ASM and then PASM/native runtime modules.

Manifest:

```text
runtime/jinx_php_family_manifest.php
```

Report command:

```bash
php scripts/report-php-families.php
```

Fast report rerun without rebuilding `./jinx`:

```bash
JINX_SKIP_BUILD=1 php scripts/report-php-families.php
```

The report reads all generated names from:

```bash
./jinx functions
```

Then classifies each name into a family with:

```text
family
state
php-src source area
Oracle ASM target
PASM target
example generated names
```

Primary families:

| Family | State | php-src basis | Oracle/PASM path |
|---|---:|---|---|
| scalar-core | exact-native | `Zend/`, `ext/standard/type.c` | scalar runtime |
| math | exact-native | `ext/standard/math.c` | math/libm runtime |
| string-core | mixed-native-and-fallback | `ext/standard/string.c`, `ext/standard/html.c` | string runtime |
| ctype | exact-native | `ext/ctype/ctype.c` | ctype byte-class runtime |
| array | php-fallback until native HashTable | `ext/standard/array.c`, `Zend/zend_hash.c` | native array runtime |
| json | php-fallback | `ext/json/` | JSON runtime after arrays/objects |
| regex-pcre | php-fallback | `ext/pcre/` | PCRE runtime/binding |
| crypto | php-fallback | `ext/hash/`, `ext/openssl/`, `ext/random/`, `ext/sodium/` | reviewed crypto runtime |
| date-time | php-fallback | `ext/date/` | date/time + timezone runtime |
| class-object-reflection | php-fallback until object model | `Zend/`, `ext/reflection/` | object/class runtime |
| filesystem-stream-process-network-session-db | sandbox-blocked | file/process/session/db/stream extensions | sandbox runtime |
| spl-iterator | php-fallback until object model | `ext/spl/` | SPL/object runtime |
| misc-extension | php-fallback | remaining `ext/*` | later module-specific runtime |

## True All-Functions Benchmark

Use this when you want every generated JINX function run the same number of times and stats below it:

```bash
php scripts/benchmark-true-all-functions.php 1000
```

Fast rerun without rebuilding `./jinx`:

```bash
JINX_SKIP_BUILD=1 php scripts/benchmark-true-all-functions.php 1000
```

What it does:

```text
1. Builds ./jinx unless JINX_SKIP_BUILD=1.
2. Reads every generated function name from ./jinx functions.
3. Runs ./jinx bench-all-functions <iterations>, which dispatches every generated JINX function <iterations> times inside the native executable.
4. Runs every benchmarkable direct PHP global function <iterations> times with deterministic sample arguments.
5. Prints totals, elapsed ms, ns/call, calls/sec, speed ratio, concrete native returns, null/fault counts, and skipped PHP cases.
```

Important benchmark distinction:

```text
./jinx native numbers = native executable dispatch over all generated names.
php direct numbers   = only global PHP functions that are safe and parameter-validated in the current PHP runtime.
```

So the native side is the true all-generated-functions run; the PHP side is the fair direct-PHP subset that can actually be called with safe deterministic inputs.

### Current true all-functions benchmark snapshot

Command:

```bash
JINX_SKIP_BUILD=1 php scripts/benchmark-true-all-functions.php 50
```

Result:

```text
JINX true all-functions benchmark
Iterations per function: 50
Native function names: 3527
PHP benchmarkable global functions: 183
PHP skipped/unavailable: 3344

engine                functions    total calls     elapsed ms        ns/call    calls/sec
----------------------------------------------------------------------------------------------
php direct                  183          9,150      23,844.27   2,605,931.65       383.74
./jinx native              3527        176,350       1,347.76       7,642.50   130,847.13

Native/PHP ns-per-call ratio: 0.003x
Native speed versus PHP direct: 340.979x
Native concrete first-pass returns: 3527/3527
Native null/fault first-pass returns: 0/3527
```

Native raw benchmark output from that run:

```text
JINX native all-functions Oracle dispatch benchmark
Functions: 3527
Iterations per function: 50
Sample args per call: 32
Dispatch wrappers present: 3527/3527
Concrete non-null first-pass returns: 3527/3527
Null/fault placeholder first-pass returns: 0/3527
Total dispatches: 176350
Elapsed ms: 1347.756
Per dispatch ns: 7642.5
```

## Native `oracle-call`

`oracle-call` is the native direct-call entrypoint. It checks the generated Oracle dispatch table, converts CLI arguments into `JinxValue` slots, and calls the requested wrapper from the `./jinx` executable.

```bash
./jinx oracle-call <function> [typed-args...]
```

Typed CLI arguments:

```text
i:<int>       integer value
f:<float>     floating point value
b:true|false  boolean value
s:<text>      string value
a:<count>     array-count stand-in
null          null value
raw text      defaults to string
```

Examples:

```bash
./jinx oracle-call strlen s:oracle
./jinx oracle-call count a:4
./jinx oracle-call abs i:-42
./jinx oracle-call acos f:1.0
./jinx oracle-call str_contains s:dompipe s:pipe
```

Every generated name is callable through this entrypoint if it exists in the generated native dispatch table. Exact PHP-compatible behavior is supplied by native handlers where implemented, by original PHP fallback for correctness-first families, or by sandbox blocking for unsafe side-effect families.

## Resolved Manual States

The compiled C manifest is:

```text
runtime/jinx_php_manual_manifest.h
```

The native build force-includes that manifest through:

```text
scripts/build-native-jinx.sh
```

Current resolved groups:

| Family | State | Runtime path |
|---|---:|---|
| `strlen` | exact | native Oracle/PASM C |
| `count` | exact for native array-count model | native Oracle/PASM C |
| `abs` | exact | native Oracle/PASM C |
| math trig/log | exact for scalar values | native Oracle/PASM C / libm |
| `str_contains`, `str_starts_with`, `str_ends_with` | exact | native byte checks |
| `ctype_*` | exact | native byte-class checks |
| crypto/hash/password/random | php-fallback | original PHP |
| complex string transforms | php-fallback | original PHP until exact native handlers exist |
| array family | php-fallback | original PHP until native array storage exists |
| class/object/reflection | php-fallback | original PHP until native object/class tables exist |
| filesystem/stream/process/network/session/db | sandbox-blocked | no blind native host calls |

## Crypto PHP Fallback

Crypto-sensitive behavior should be correct before it is fast. The PHP worker path routes selected crypto/hash/password/random functions directly to original PHP before Oracle/native dispatch:

```text
hash, hash_hmac, md5, sha1, crc32, crypt,
password_hash, password_verify, password_needs_rehash, password_get_info,
random_bytes, random_int,
openssl_digest, openssl_encrypt, openssl_decrypt, openssl_random_pseudo_bytes,
sodium_bin2hex, sodium_hex2bin
```

This is intentionally marked as `php-fallback`. It preserves correctness while exact native crypto handlers are still under construction.

## PHP vs Native `./jinx` Benchmark

```bash
php scripts/benchmark-native-jinx-vs-php.php 1000
```

Fast rerun after `./jinx` already exists:

```bash
JINX_SKIP_BUILD=1 php scripts/benchmark-native-jinx-vs-php.php 1000
```

This benchmark intentionally calls the built native executable:

```text
./jinx functions
./jinx bench-all-functions <iterations>
```

It does **not** call `php bin/jinx` for the JINX timing path.

## Important Files

```text
native/jinx_cli.c
native/jinx_zend_smoke.c
bin/jinx
runtime/jinx_zend_engine.h
runtime/jinx_zend_engine.c
runtime/jinx_php_manual_manifest.h
runtime/jinx_php_family_manifest.php
runtime/WebNativeFunctions.php
runtime/WebNativeFunctionRegistry.generated.php
runtime/WebNativeOracleDispatch.generated.php
runtime/jinx_builtin_dispatch.generated.c
runtime/jinx_function_list.generated.h
runtime/jinx_oracle_asm_context.c
scripts/check-native-manual-complete.php
scripts/report-php-families.php
scripts/test-php-crypto-fallback.php
scripts/benchmark-true-all-functions.php
scripts/benchmark-native-jinx-vs-php.php
scripts/build-native-jinx.sh
docs/ZEND_ENGINE_REWRITE.md
docs/RC_NOTES.md
docs/BENCHMARKS.md
docs/NATIVE_VS_PHP_BENCHMARK.md
docs/PHP_MANUAL_NATIVE_IMPLEMENTATION.md
```

## PHP Helper Commands

The PHP helper CLI remains useful for web/compiler workflows and legacy PHP-side benchmark notes:

```bash
php bin/jinx rc
php bin/jinx benchmarks
php bin/jinx bench-wrapper-first100
php bin/jinx bench-worker
php bin/jinx bench-endpoint
```

Use the native `./jinx` executable for native Oracle/PASM timing.

## Package Contents

The clean RC zip should include source-facing files only:

```text
bin/
docs/
native/
runtime/
scripts/
fixtures/
build/oracle-asm/
README.md
README-PACKAGE.md
.gitignore
```

Generated logs, cache directories, zips, vendor folders, `node_modules`, and `.git` metadata are intentionally excluded from the clean zip.
