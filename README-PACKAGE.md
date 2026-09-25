# JINX PHP Stub → C Inline Oracle-ASM Branch

This branch adds a later backend representation step. The primary project target remains PHP-compatible JINX execution: PHP behavior is authoritative, JINX mirrors and accelerates selected Zend/PHP families through the Oracle interpreter path, and unsupported or unsafe behavior must stay PHP-compatible or fail closed.

PASM/native binary emission is secondary. It is a possible output path after a PHP/Zend family has a known behavior contract, a JINX/Oracle representation, and parity evidence; it should not block mirroring PHP behavior inside JINX.

```text
php-src *.stub.php
→ function manifest
→ PHP-compatible JINX/Oracle call contract
→ optional lower-level PASM call skeleton
→ optional C inline Oracle-ASM representation
```

## New files

```text
scripts/generate-c-inline-oracle-asm.php
scripts/test-c-inline-oracle-asm-generator.php
docs/php-pasm-book/10-c-inline-oracle-asm-stubs.md
```

## Main command

```bash
php scripts/import-php-src-stubs.php /path/to/php-src spec/php-functions.from-php-src.json
php scripts/generate-c-inline-oracle-asm.php spec/php-functions.from-php-src.json build/oracle-asm
```

If the full php-src checkout is not local yet, the runtime Reflection manifest can be used as a temporary inventory:

```bash
php scripts/import-php-runtime-reflection.php spec/php-functions.from-runtime.json
php scripts/generate-c-inline-oracle-asm.php spec/php-functions.from-runtime.json build/oracle-asm-runtime
```

## Generated output

```text
build/oracle-asm/
├── jinx_oracle_asm_runtime.h
├── oracle_asm_index.json
└── stubs/
    └── <source-stub>.oracle_asm.h
```

Each callable becomes a static inline C function using `JINX_ORA_*` operations:

```text
LOAD_ARG
PUSH_ARG / PUSH_ARG_REF / PUSH_ARG_VARIADIC
CALL_BUILTIN / CALL_METHOD_BUILTIN
MOV ACC, RET
```

## Doctrine

This is still not a shortcut to native behavior. It is an optional backend representation for call families that are already understood by PHP-compatible JINX/Oracle execution.

```text
Primary:   PHP behavior → JINX executable → Oracle interpretation/mirroring/acceleration
Secondary: Oracle form → PASM/C inline Oracle-ASM → native backend
```
