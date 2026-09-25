# Zend Engine Rewrite Plan

JINX rewrites the Zend Engine as native JINX runtime families that can lower into Oracle SM, Oracle ASM, and then PASM.

This is not a wholesale import of php-src headers. The point is to mirror the Zend concepts in JINX-owned structures so the native executable can run without linking PHP.

## Native source layer

```text
runtime/jinx_zend_engine.h
runtime/jinx_zend_engine.c
native/jinx_zend_smoke.c
```

Build and smoke test:

```bash
./scripts/build-native-jinx.sh
./build/native/jinx-zend-smoke
```

Expected smoke output includes:

```text
PASS: JINX Zend skeleton smoke passed
PASS: zend_string owned/refcount/COW smoke passed
```

## Rewrite families

| Family | php-src area | JINX native state | Oracle SM target | PASM target |
|---|---|---:|---|---|
| `zval` | `Zend/zend_types.h`, `Zend/zend.h` | started | `oracle-sm/zend/zval.osm` | `runtime/pasm/zend/zval.pasm` |
| `zend_string` | `Zend/zend_string.h`, `Zend/zend_string.c` | started | `oracle-sm/zend/string.osm` | `runtime/pasm/zend/string.pasm` |
| `HashTable/zend_array` | `Zend/zend_hash.h`, `Zend/zend_hash.c`, `Zend/zend_array.c` | planned | `oracle-sm/zend/hash.osm` | `runtime/pasm/zend/hash.pasm` |
| `executor/call-frame` | `Zend/zend_execute.c`, `Zend/zend_vm_def.h`, `Zend/zend_vm_execute.h` | started | `oracle-sm/zend/executor.osm` | `runtime/pasm/zend/executor.pasm` |
| `objects/classes` | `Zend/zend_object_handlers.c`, `Zend/zend_objects_API.c`, `Zend/zend_compile.c` | planned | `oracle-sm/zend/object.osm` | `runtime/pasm/zend/object.pasm` |
| `errors/exceptions` | `Zend/zend_exceptions.c`, `Zend/zend_errors.h` | planned | `oracle-sm/zend/errors.osm` | `runtime/pasm/zend/errors.pasm` |
| `compiler/opcodes` | `Zend/zend_language_parser.y`, `Zend/zend_compile.c`, `Zend/zend_vm_def.h` | planned | `oracle-sm/zend/opcodes.osm` | `runtime/pasm/zend/opcodes.pasm` |

## What exists now

The native Zend-shaped layer now has:

```text
JinxZendValue       zval-like tagged value
JinxZendString      zend_string-like view/owned buffer with refcount and COW separation
JinxZendArray       zend_array/HashTable count shell
JinxZendObject      object/class shell
JinxZendReference   reference shell
JinxZendCallFrame   function call frame shell
JinxZendExecutor    executor/request state shell
```

The smoke test proves:

```text
borrowed/interned string view creation
owned string allocation
string retain/release
copy-on-write separation before mutation
array-count shell creation
call-frame enter/leave
return-value propagation
family manifest enumeration
```

## `zend_string` layer

Implemented native helpers:

```text
jinx_zend_string_view      borrowed/interned view over static bytes
jinx_zend_string_new       owned NUL-terminated byte buffer
jinx_zend_string_retain    refcount increment for non-interned strings
jinx_zend_string_release   refcount decrement/free
jinx_zend_string_separate  COW detach when shared or borrowed
jinx_zend_string_set_byte  mutating byte write after separation
```

This is the string foundation needed before `HashTable/zend_array`, because array string keys, object property names, class names, function names, and interned identifiers all depend on stable string ownership rules.

## Next implementation steps

1. Add packed-array buckets and insertion-order iteration.
2. Add mixed hash buckets for string keys using owned `JinxZendString` keys.
3. Add zval copy/destruct helpers so arrays and objects retain/release nested values.
4. Lower `count`, `array_key_exists`, `array_values`, and `foreach` onto native `JinxZendArray`.
5. Add object class table and method dispatch.
6. Add error/warning/exception objects.
7. Add opcode/IR lowering so arbitrary PHP can run through the Zend-shaped executor.

## Rule

A PHP function should move from PHP fallback to exact native only when the Zend family it depends on exists natively. For example:

```text
array_values -> needs HashTable/zend_array
method_exists -> needs objects/classes
try/catch -> needs errors/exceptions
foreach -> needs HashTable/zend_array + executor/opcodes
```
