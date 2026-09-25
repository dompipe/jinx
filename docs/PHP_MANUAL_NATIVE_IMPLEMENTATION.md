# PHP Manual Driven Native Builtin Implementation

The native `./jinx` executable must not treat under-construction PHP functions as random placeholders. Every builtin should move through a manual-backed implementation path.

## Rule

For every generated PHP function or method name:

1. Read the PHP manual page or the matching manual family page.
2. Record the prototype, return type family, edge cases, and safety notes.
3. Add the native C / Oracle / PASM behavior handler, or explicitly mark the family as PHP fallback while native behavior is not safe or complete.
4. Add a PHP-vs-JINX fixture using the same arguments on both sides.
5. Promote the manifest state only when the native return behavior matches the documented PHP behavior for the supported `JinxValue` types.

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

Use this command before calling JINX a complete native PHP mirror:

```bash
php scripts/check-native-manual-complete.php
```

The gate fails if any manifest entry is not `exact`. That is intentional. It prevents the project from claiming “PHP-equal native ASM” while any handler remains `partial`, `placeholder`, `php-fallback`, or `unsafe-native`.

Expected during construction:

```text
FAIL: native PHP mirror is not complete. Remaining manual states:
```

Expected only when the mirror is actually complete:

```text
PASS: every manual manifest entry is exact. Native mirror gate passed.
```

## States

```text
exact          Manual behavior is implemented for the supported native value types.
partial        Manual page was read and the native handler covers only a documented subset.
placeholder    Callable native carrier exists, but exact manual behavior still needs implementation.
php-fallback   Original PHP is used for correctness until exact native behavior exists.
unsafe-native  Must not become a blind native host call. Needs sandbox/security policy first.
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

| Family | Manual basis | Native state | Notes |
|---|---|---:|---|
| `strlen` | `strlen(string $string): int` | exact | Uses byte length in `JinxValue.flags`. |
| `count` | `count(Countable|array $value, int $mode = COUNT_NORMAL): int` | partial | Exact for array-count stand-ins only. |
| `abs` | `abs(int|float $num): int|float` | partial | Integer path works; float-preserving return needs exact mirroring. |
| math trig/log | PHP math reference | partial | Uses C libm for scalar paths; warning/NAN behavior needs parity work. |
| `str_contains` | `str_contains(string $haystack, string $needle): bool` | partial | Needs exact empty-needle and binary-safe behavior. |
| `str_starts_with` | `str_starts_with(string $haystack, string $needle): bool` | partial | Needs exact empty-needle byte-prefix behavior. |
| `str_ends_with` | `str_ends_with(string $haystack, string $needle): bool` | partial | Needs exact empty-needle byte-suffix behavior. |
| `ctype_*` | PHP ctype reference | partial | Native bool carrier exists; exact byte-class behavior is next. |
| crypto/hash/password/random | PHP crypto/hash/password/random references | php-fallback | Original PHP fallback preserves correctness until exact native crypto exists. |
| string transform family | PHP string reference | placeholder | Coarse native carriers exist; exact per-function behavior is next. |
| array family | PHP array reference | placeholder | Requires native array storage beyond array-count stand-ins. |
| class/object/reflection | PHP class/object reference | placeholder | Requires class table and object model. |
| filesystem/stream/process/network/session/db | PHP function references | unsafe-native | Needs explicit sandbox policy before native behavior. |

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

`oracle-call` proving callability is not the same as exact PHP semantic parity. Exactness comes from this manual process plus PHP-vs-JINX fixtures.

## Next implementation order

1. Finish exact scalar/string functions first: `strlen`, `str_contains`, `str_starts_with`, `str_ends_with`, `strcmp`, `strcasecmp`, `substr`, `trim`, `strtolower`, `strtoupper`.
2. Finish exact numeric functions next: `abs`, `ceil`, `floor`, `sqrt`, trig/log functions with PHP-compatible edge behavior.
3. Keep crypto on original PHP fallback until a reviewed exact native crypto backend exists.
4. Add native array storage, then promote array functions out of placeholders.
5. Add object/class tables, then promote class/object/reflection functions.
6. Add sandbox policy for filesystem/stream/process/network/session/database functions.
