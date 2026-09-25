# dompipe/jinx RC

This is a release-candidate source package for the dompipe/JINX worker and web compiler branch.

The RC focuses on:

- JINX web compilation for validated JSON endpoints.
- Worker-style native wrapper registration for the imported PHP runtime surface.
- A generated compact wrapper dispatch table for 3,527 PHP callable signatures.
- A native GCC `./jinx` executable that exercises the C Oracle/PASM dispatch layer.
- Native `oracle-call` support for every generated PHP callable name in the native dispatch table.
- Manual-driven handler states: `exact`, `php-fallback`, and `sandbox-blocked` are resolved; `partial`, `placeholder`, and `unsafe-native` are not.
- Correctness-first PHP fallback for crypto/hash/password/random functions until exact native crypto exists.
- Native benchmarks for first-100 and all-functions Oracle dispatch traversal.
- PHP comparison benchmarks for validated callable builtin cases.

## Quick Native Commands

Build the native executable first. This creates the repository-root `./jinx` binary and also copies it to `./build/native/jinx`.

```bash
./scripts/build-native-jinx.sh
./jinx rc
./jinx oracle-smoke
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
bin/jinx
runtime/jinx_php_manual_manifest.h
runtime/WebNativeFunctions.php
runtime/WebNativeFunctionRegistry.generated.php
runtime/WebNativeOracleDispatch.generated.php
runtime/jinx_builtin_dispatch.generated.c
runtime/jinx_function_list.generated.h
runtime/jinx_oracle_asm_context.c
scripts/check-native-manual-complete.php
scripts/test-php-crypto-fallback.php
scripts/benchmark-native-jinx-vs-php.php
scripts/build-native-jinx.sh
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
