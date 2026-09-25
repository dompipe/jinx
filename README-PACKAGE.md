# JINX PHP Stub → C Inline Oracle-ASM Branch

This branch adds the next backend step:

```text
php-src *.stub.php
→ function manifest
→ lower-level PASM call skeleton
→ C inline Oracle-ASM representation
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

This is still not a shortcut to native behavior. It is PASM-shaped Oracle ASM carried in C.

```text
PHP → JINX → Oracle ASM normal form → PASM → C inline Oracle-ASM → native backend
```
