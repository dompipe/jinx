# Lower-Level PASM Profile

This branch tightens PASM so it is closer to an assembly target and easier to emit into C/ASM.

## Purpose

PASM is not a PHP-shaped tree. PASM is the portable assembly contract after PHP has already been normalized through the Oracle-shaped ASM form.

```text
PHP source
→ JINX structural IR
→ Oracle-shaped ASM normal form
→ lower-level PASM
→ evaluator or native C/ASM backend
```

## Required shape

Lower-level PASM should prefer:

```text
LABEL
MOV
LOAD_CONST
LOAD_LOCAL
STORE_LOCAL
LOAD_SLOT
STORE_SLOT
CMP
JMP
JMP_IF_FALSE
JMP_IF_TRUE
PUSH_ARG
CALL
RET
ITER_INIT
ITER_VALID
ITER_KEY
ITER_VALUE
ITER_NEXT
ITER_FREE
THROW
```

It should avoid high-level PHP phrases in executable code such as:

```text
EVAL_WHILE
RUN_FOREACH
CALL_PHP_FUNCTION_MAGICALLY
READ_THIS_PROPERTY_BY_NAME_AT_RUNTIME
```

Those forms may exist in parser/JINX metadata, but not in the canonical PASM execution layer.

## Register/stack convention

The default portable convention is:

```text
ACC      current accumulator value
R0..Rn   temporary registers
SP       value stack pointer
FP       call-frame pointer
SELF     current object pointer for methods
ARGS     call argument vector
RET      return-value register
```

A simple property return lowers as:

```text
METHOD_BEGIN class.Hold.method.getValue
BIND_SELF SELF
LOAD_SLOT ACC, SELF, 1        ; value
RET ACC
METHOD_END
```

A builtin call lowers as:

```text
LOAD_LOCAL R0, string
PUSH_ARG R0
CALL_BUILTIN strlen, argc=1
STORE_LOCAL result, RET
```

## Loop lowering

Loops must become labels and jumps before PASM is considered executable.

### while

```text
LABEL loop.start
CMP_LT R0, i, 10
JMP_IF_FALSE R0, loop.end
; body
JMP loop.start
LABEL loop.end
```

### for

```text
; init
LABEL loop.start
; condition
JMP_IF_FALSE R0, loop.end
; body
LABEL loop.continue
; step
JMP loop.start
LABEL loop.end
```

### foreach

```text
ITER_INIT IT0, source
LABEL foreach.start
ITER_VALID R0, IT0
JMP_IF_FALSE R0, foreach.end
ITER_KEY R1, IT0
ITER_VALUE R2, IT0
; body
LABEL foreach.continue
ITER_NEXT IT0
JMP foreach.start
LABEL foreach.end
ITER_FREE IT0
```

## Native emission rule

The C/ASM backend emits from this lower-level PASM only. It may choose to emit C statements, macros, compiler intrinsics, or inline assembly, but the source operation must be PASM.

```text
PASM: LOAD_SLOT ACC, SELF, 1
C:    acc = self->slots[1];
ASM:  mov acc, [self + slot_offset]
```

## Imported builtins

php-src imported function/method signatures are only inventory. A function is not implemented until it has:

```text
signature imported
PASM lowering selected
evaluator behavior implemented
native strategy chosen
parity fixture passing
```

## Builtin call lowering

Builtin and extension calls lower through the same register/stack convention as user calls. The importer emits call skeletons like:

```text
LOAD_ARG R0, string
PUSH_ARG R0
CALL_BUILTIN strlen, argc=1
MOV ACC, RET
```

By-reference and variadic parameters are explicit:

```text
LOAD_ARG R0, array
PUSH_ARG_REF R0
LOAD_ARG R1, values
PUSH_ARG_VARIADIC R1
CALL_BUILTIN array_push, argc=2
MOV ACC, RET
```

This is still a signature/call skeleton. The actual function implementation must later bind `CALL_BUILTIN` to one of:

```text
intrinsic
runtime_helper
extension_bridge
filesystem_os_bridge
reflection_metadata_bridge
deferred_unsafe_or_unsupported
```

## C Inline Oracle-ASM Stub Backend

The lower-level call profile can be emitted as C inline Oracle-ASM wrappers. A stub-derived callable lowers to this sequence:

```text
LOAD_ARG Rn, parameter-name
PUSH_ARG / PUSH_ARG_REF / PUSH_ARG_VARIADIC Rn
CALL_BUILTIN or CALL_METHOD_BUILTIN name, argc=N
MOV ACC, RET
```

The generated C wrapper preserves that exact sequence with `JINX_ORA_*` operations. That keeps the eventual GCC/ASM backend as a consumer of PASM-shaped operations instead of a shortcut around PASM.
