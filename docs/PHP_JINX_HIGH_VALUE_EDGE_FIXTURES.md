# PHP vs JINX high-value semantic edge fixtures

This probe adds a focused semantic edge suite instead of adding more generated ledger IDs.

It exercises 20 direct PHP-vs-JINX fixtures across 10 high-value PHP semantic areas:

1. references
2. Zend array key/copy behavior
3. static properties and typed static errors
4. clone semantics
5. closures and binding
6. exceptions and normalized runtime errors
7. include/include_once scope behavior
8. callbacks and invalid callback errors
9. typed property errors
10. filesystem/resource edge behavior

The fixtures are generated under:

```text
build/differential/php-vs-jinx-high-value-edge-fixtures/
```

Each generated fixture is run through both direct PHP and repository-root native `./jinx`.
The test compares:

```text
exit code
stdout
```

Negative/error cases catch `Throwable` and print normalized markers such as:

```text
ERR:TypeError
ERR:Error
```

This avoids unstable path and line-number differences in stderr while still checking that PHP and JINX agree on observable behavior.

Run directly:

```bash
./jinx scripts/test-php-jinx-high-value-edge-fixtures.php
```

Run through the focused native edge suite:

```bash
./jinx scripts/test-jinx-native-edge-suite.php
```

This suite is intentionally semantic and high-risk. Failures here should be treated as real PHP/Zend compatibility gaps, not as generated-ledger bookkeeping failures.


## Combined edge coverage

Wave two adds 20 more fixtures across 10 additional semantic areas. Together the focused edge suite now contains:

```text
40 direct PHP-vs-JINX fixtures
20 unique semantic areas
2 focused semantic waves
```

The combined structural audit is:

```bash
./jinx scripts/test-php-jinx-high-value-edge-coverage-audit.php
```

The focused suite runs the audit and both waves:

```bash
./jinx scripts/test-jinx-native-edge-suite.php
```

The coverage audit verifies exact fixture counts, exact area counts, uniqueness across both waves, and focused-suite wiring. It does not claim that the fixtures pass until the native `./jinx` executions actually match PHP.
