# C Inline Oracle-ASM Stub Representation

The `.stub.php` files in `php-src` describe internal function and method signatures. JINX uses them as an inventory source for PHP-compatible Oracle interpreter call mirroring. C inline Oracle-ASM is a later representation backend, not the condition for mirroring a PHP/Zend behavior family.

The direction is:

```text
php-src *.stub.php
→ php-functions manifest
→ PHP-compatible JINX/Oracle call contract
→ optional lower-level PASM call skeleton
→ optional C inline Oracle-ASM wrapper
→ runtime helper / intrinsic / future GCC inline asm backend when ready
```

A generated wrapper does not guess PHP behavior. It only expresses the calling convention:

```text
LOAD_ARG R0, string
PUSH_ARG R0
CALL_BUILTIN strlen, argc=1
MOV ACC, RET
```

That becomes C carrier code:

```c
static inline JinxValue jinx_ora_strlen(JinxOracleAsmContext *ctx) {
    JINX_ORA_LOAD_ARG(ctx, JINX_ORA_R0, 0, "string");
    JINX_ORA_PUSH_ARG(ctx, JINX_ORA_R0);
    (void)JINX_ORA_CALL_BUILTIN(ctx, "strlen", 1);
    return JINX_ORA_MOV(ctx, JINX_ORA_ACC, JINX_ORA_RET);
}
```

By-reference and variadic signatures are preserved:

```text
PUSH_ARG_REF R0
PUSH_ARG_VARIADIC R1
```

Those forms are critical because a correct PHP compiler cannot treat normal arguments, by-reference arguments, and variadic spread arguments as the same machine operation.

The first implementation is a representation backend. PHP behavior still has to be known, mirrored through JINX/Oracle, and proven against PHP-compatible fixtures before a callable should be treated as implemented. Later, the same macros can expand to:

1. hosted PASM/runtime calls for web execution,
2. ASM-shaped C for portability,
3. GCC/Clang inline assembly for lower-level native builds.

The doctrine remains:

```text
PHP behavior first.
JINX/Oracle mirroring and fallback before native claims.
PASM/native emission only when that family is ready for a secondary backend.
```
