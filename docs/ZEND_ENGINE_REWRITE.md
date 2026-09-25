# Zend Engine Rewrite Plan

JINX rewrites the Zend Engine as native JINX runtime families that can lower into Oracle SM, Oracle ASM, and then PASM.

This is not a wholesale import of php-src headers. The point is to mirror the Zend concepts in JINX-owned structures so the native executable can run without linking PHP.

## Native source layer

```text
runtime/jinx_zend_engine.h
runtime/jinx_zend_engine.c
native/jinx_zend_smoke.c
native/jinx_zend_array_builtin_smoke.c
```

Build and smoke test:

```bash
./scripts/build-zend-smoke.sh
./build/native/jinx-zend-smoke
./build/native/jinx-zend-array-builtin-smoke
```

Full native build also compiles the Zend smoke binary:

```bash
./scripts/build-native-jinx.sh
./build/native/jinx-zend-smoke
```

## Rewrite families

| Family | php-src area | JINX native state | Oracle SM target | PASM target |
|---|---|---:|---|---|
| `zval` | `Zend/zend_types.h`, `Zend/zend.h` | started | `oracle-sm/zend/zval.osm` | `runtime/pasm/zend/zval.pasm` |
| `zend_string` | `Zend/zend_string.h`, `Zend/zend_string.c` | started | `oracle-sm/zend/string.osm` | `runtime/pasm/zend/string.pasm` |
| `HashTable/zend_array` | `Zend/zend_hash.h`, `Zend/zend_hash.c`, `Zend/zend_array.c` | started | `oracle-sm/zend/hash.osm` | `runtime/pasm/zend/hash.pasm` |
| `executor/call-frame` | `Zend/zend_execute.c`, `Zend/zend_vm_def.h`, `Zend/zend_vm_execute.h` | started | `oracle-sm/zend/executor.osm` | `runtime/pasm/zend/executor.pasm` |
| `objects/classes` | `Zend/zend_object_handlers.c`, `Zend/zend_objects_API.c`, `Zend/zend_compile.c` | planned | `oracle-sm/zend/object.osm` | `runtime/pasm/zend/object.pasm` |
| `errors/exceptions` | `Zend/zend_exceptions.c`, `Zend/zend_errors.h` | planned | `oracle-sm/zend/errors.osm` | `runtime/pasm/zend/errors.pasm` |
| `compiler/opcodes` | `Zend/zend_language_parser.y`, `Zend/zend_compile.c`, `Zend/zend_vm_def.h` | planned | `oracle-sm/zend/opcodes.osm` | `runtime/pasm/zend/opcodes.pasm` |

## What exists now

The native Zend-shaped layer now has:

```text
JinxZendValue       zval-like tagged value
JinxZendString      borrowed views, owned buffers, refcount, COW, hashing
JinxZendArray       packed buckets, mixed string-key buckets, append, lookup, update, COW, insertion-order iteration
JinxZendBucket      numeric or string-key bucket carrying retained JinxZendValue
JinxZendObject      object/class shell
JinxZendReference   reference shell
JinxZendCallFrame   function call frame shell
JinxZendExecutor    executor/request state shell
```

The smoke tests prove:

```text
borrowed/interned string view creation
owned string allocation
string retain/release
copy-on-write separation before mutation
string hash/equality helpers
packed array bucket allocation
append with retained values
numeric index lookup
mixed string-key insertion
string-key lookup
string-key update without duplicate bucket growth
insertion-order bucket iteration
array release/destruct of contained values and keys
array retain/clone/copy-on-write separation
count() over native JinxZendArray
array_key_exists() for numeric and string keys
array_is_list() over packed/insertion-order buckets
array_values() producing a packed values array
array_keys() producing numeric/string key values
PHP builtin-name bridge for count, array_key_exists, array_is_list, array_values, array_keys
call-frame enter/leave
return-value propagation
family manifest enumeration
```

## Native array builtin helpers

These helpers are implemented against `JinxZendArray`:

```text
jinx_zend_array_count_builtin
jinx_zend_array_key_exists_index
jinx_zend_array_key_exists_string
jinx_zend_array_is_list_builtin
jinx_zend_array_values_builtin
jinx_zend_array_keys_builtin
```

The standalone bridge smoke now proves the PHP names below route to those helpers:

```text
count
array_key_exists
array_is_list
array_values
array_keys
```

Current bridge executable:

```bash
./build/native/jinx-zend-array-builtin-smoke
```

The next integration point is the generated Oracle builtin dispatch header so native `./jinx oracle-call` can route these PHP names to Zend arrays once CLI/Oracle values can carry `JinxZendArray *` pointers.

## Next implementation steps

1. Add a JinxValue pointer-carrier for native Zend arrays in the Oracle/PASM value model.
2. Wire `count`, `array_key_exists`, `array_values`, `array_keys`, and `array_is_list` into the Oracle builtin dispatch when arguments are native Zend arrays.
3. Add deletion/tombstones and compaction rules.
4. Lower `foreach` onto `jinx_zend_array_iter_at`.
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
