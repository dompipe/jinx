# PHP vs JINX high-value semantic edge fixtures — wave two

Wave two adds 20 direct PHP-vs-JINX fixtures across 10 additional PHP semantic areas:

1. generators and `yield from`
2. variadics, unpacking, and named arguments
3. array destructuring and array spread
4. nullsafe access and null-coalescing assignment
5. late static binding
6. magic methods and property overloading
7. readonly classes/properties
8. backed and unit enums
9. namespace/import/function resolution
10. union and nullable types

The generated fixtures live under:

```text
build/differential/php-vs-jinx-high-value-edge-fixtures-two/
```

Each fixture is executed once through direct PHP and once through repository-root native `./jinx`. The probe compares exit code and stdout exactly. Negative runtime cases catch `Throwable` and emit normalized class markers such as `ERR:TypeError` or `ERR:Error`.

Run wave two directly:

```bash
./jinx scripts/test-php-jinx-high-value-edge-fixtures-two.php
```

Run both high-value waves:

```bash
./jinx scripts/test-jinx-native-edge-suite.php
```

These cases are intentionally semantic probes. They are not new ledger IDs and should not increase reported PHP/Zend coverage simply by existing. A failure identifies a concrete compatibility gap to implement next.


## Gap-map behavior

Wave two evaluates all 20 fixtures before failing. If multiple semantic areas differ, one run prints every mismatch with:

```text
fixture name
semantic area
PHP exit/stdout
JINX exit/stdout
PHP/JINX stderr when present
```

This makes wave two useful as an implementation queue rather than a first-failure-only gate.
