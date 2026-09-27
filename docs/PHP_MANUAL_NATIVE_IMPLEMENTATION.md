# PHP Manual Driven Native Builtin Implementation

The native `./jinx` executable must not treat under-construction PHP functions as random placeholders. Every builtin should move through a manual-backed implementation path.

## Rule

For every generated PHP function or method name:

1. Read the PHP manual page or the matching manual family page.
2. Record the prototype, return type family, edge cases, and safety notes.
3. Add the native C / Oracle / PASM behavior handler, or explicitly mark the family as PHP fallback while native behavior is not safe or complete.
4. Add a PHP-vs-JINX fixture using the same arguments on both sides.
5. Mark the family as resolved only when it is exact-native, correctness-first PHP fallback, or intentionally sandbox-blocked.

The compiled manifest lives in:

```text
runtime/jinx_php_manual_manifest.h
```

It is force-included by the native build:

```text
scripts/build-native-jinx.sh
```

So the manual implementation map is part of the native executable source path, not just documentation.

## Completion gate

Use this command before treating the manual manifest as resolved:

```bash
php scripts/check-native-manual-complete.php
```

The gate fails only when a manifest entry remains `partial`, `placeholder`, or `unsafe-native`. It passes when every entry is resolved as one of:

```text
exact
php-fallback
sandbox-blocked
```

A pass means there are no ambiguous under-construction buckets left. It does **not** mean every PHP function is native ASM; `php-fallback` explicitly means original PHP is still used for correctness.

Expected after this cleanup:

```text
PASS: every manual manifest entry is resolved as exact-native, PHP-fallback, or sandbox-blocked.
```

## States

```text
exact            Manual behavior is implemented natively for the supported JinxValue types.
php-fallback     Original PHP is used for correctness until exact native behavior exists.
sandbox-blocked  Side-effect/native host calls are blocked until sandbox policy exists.
partial          Unresolved: manual page was read but native behavior is incomplete.
placeholder      Unresolved: callable carrier exists but exact behavior is not implemented.
unsafe-native    Unresolved: unsafe host behavior has not been blocked or sandboxed.
```

## Crypto PHP fallback

Crypto-sensitive behavior is correctness-first. Selected crypto/hash/password/random functions bypass Oracle/native placeholders in the PHP worker and call original PHP directly:

```text
hash, hash_hmac, md5, sha1, crc32, crypt,
password_hash, password_verify, password_needs_rehash, password_get_info,
random_bytes, random_int,
openssl_digest, openssl_encrypt, openssl_decrypt, openssl_random_pseudo_bytes,
sodium_bin2hex, sodium_hex2bin
```

Run the regression test with:

```bash
php scripts/test-php-crypto-fallback.php
```

This is not native ASM completion. The manifest state is `php-fallback` until exact native crypto handlers replace the PHP calls.

## Current first-pass manual families

| Family | Manual basis | State | Notes |
|---|---|---:|---|
| `strlen` | `strlen(string $string): int` | exact | Uses byte length in `JinxValue.flags`. |
| `count` | `count(Countable|array $value, int $mode = COUNT_NORMAL): int` | exact | Exact for native array-count model; real PHP arrays use fallback until native storage exists. |
| `abs` | `abs(int|float $num): int|float` | exact | Preserves int versus float return family for native values. |
| math trig/log | PHP math reference | exact | Uses C libm for scalar native values. |
| `str_contains` | `str_contains(string $haystack, string $needle): bool` | exact | Binary-safe byte substring including empty needle. |
| `str_starts_with` | `str_starts_with(string $haystack, string $needle): bool` | exact | Binary-safe byte prefix including empty needle. |
| `str_ends_with` | `str_ends_with(string $haystack, string $needle): bool` | exact | Binary-safe byte suffix including empty needle. |
| `ctype_*` | PHP ctype reference | exact | Native ASCII byte-class checks for string `JinxValue`; empty strings return false. |
| scalar core | PHP variable/type reference | exact | Native `JinxValue` checks and conversions for `is_*`, `boolval`, `intval`, `floatval`, and `strval`. |
| string byte transforms/primitives | `strtolower`/`strtoupper`/`lcfirst`/`ucfirst`/`strrev`/default `trim` family/`chr`/`ord`/`substr`/`strpos`/`str_repeat` | exact | Native Oracle ASM byte handlers for the current `JinxValue` string model. |
| crypto/hash/password/random | PHP crypto/hash/password/random references | php-fallback | Original PHP fallback preserves correctness until exact native crypto exists. |
| complex string transform family | PHP string reference | php-fallback | Original PHP fallback preserves exact behavior until each transform gets a native handler. |
| array family | PHP array reference | php-fallback | Original PHP fallback preserves exact behavior until native array storage exists. |
| class/object/reflection | PHP class/object reference | php-fallback | Original PHP fallback preserves exact behavior until native class/object tables exist. |
| filesystem/stream/process/network/session/db | PHP side-effect references | sandbox-blocked | No blind native host calls; needs explicit sandbox policy before enabling. |

## Promotion checklist

A function can move to `exact` only when all are true:

```text
[ ] Manual page URL recorded.
[ ] Prototype recorded.
[ ] Required/optional/variadic arguments recorded.
[ ] Return type family recorded.
[ ] False/null/error/warning behavior recorded.
[ ] PHP 8 behavior changes checked.
[ ] Native `JinxValue` conversion rules defined.
[ ] Native handler implemented.
[ ] `./jinx oracle-call <function> ...` works for the fixture.
[ ] PHP-vs-JINX fixture compares expected return values.
[ ] Unsafe side effects are blocked or explicitly sandboxed.
```

## Native command expectations

Every generated name should be callable by the native executable:

```bash
./jinx oracle-call <function> [typed-args...]
```

Examples:

```bash
./jinx oracle-call strlen s:oracle
./jinx oracle-call abs i:-42
./jinx oracle-call str_contains s:dompipe s:pipe
./jinx oracle-call array_values a:4
```

`oracle-call` proving callability is not the same as exact PHP semantic parity. Exactness comes from this manual process plus PHP-vs-JINX fixtures, or from explicit PHP fallback for families that require original PHP behavior for now.

## Next implementation order

1. Replace PHP fallback string transforms with exact native handlers one by one.
2. Keep crypto on original PHP fallback until a reviewed exact native crypto backend exists.
3. Add native array storage, then replace array fallbacks.
4. Add object/class tables, then replace class/object/reflection fallbacks.
5. Add sandbox policy for filesystem/stream/process/network/session/database functions.
