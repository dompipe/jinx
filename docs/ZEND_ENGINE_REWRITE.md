# Zend Engine Rewrite Plan

JINX rewrites the Zend Engine as native JINX runtime families that can lower into Oracle SM, Oracle ASM, and then PASM.

This is not a wholesale import of php-src headers. The point is to mirror the Zend concepts in JINX-owned structures so the native executable can run without linking PHP.

## Native source layer

```text
runtime/jinx_zend_engine.h
runtime/jinx_zend_engine.c
runtime/jinx_zend_array_delete.h
runtime/jinx_zend_foreach.h
runtime/jinx_zend_foreach_opcode.h
runtime/jinx_zend_foreach_program.h
runtime/jinx_zend_object.h
runtime/jinx_zend_method_opcode.h
runtime/jinx_zend_error.h
runtime/jinx_zend_throw_opcode.h
runtime/jinx_zend_opcode_vm.h
runtime/jinx_zend_lowering_fixture.h
runtime/jinx_zend_ir_fixture.h
runtime/jinx_oracle_zend_array_carrier.h
runtime/jinx_oracle_zend_array_builtins.h
native/jinx_zend_smoke.c
native/jinx_zend_array_builtin_smoke.c
native/jinx_zend_array_delete_smoke.c
native/jinx_zend_foreach_smoke.c
native/jinx_zend_foreach_execute_smoke.c
native/jinx_zend_foreach_opcode_smoke.c
native/jinx_zend_foreach_program_smoke.c
native/jinx_zend_object_smoke.c
native/jinx_zend_method_opcode_smoke.c
native/jinx_zend_error_smoke.c
native/jinx_zend_throw_opcode_smoke.c
native/jinx_zend_opcode_vm_smoke.c
native/jinx_zend_lowering_fixture_smoke.c
native/jinx_zend_ir_fixture_smoke.c
native/jinx_oracle_zend_array_carrier_smoke.c
native/jinx_oracle_zend_array_builtin_smoke.c
native/jinx_oracle_dispatch_zend_array_smoke.c
native/jinx_oracle_array_fixture_cli.c
native/jinx_cli.c
```

Build and smoke test:

```bash
./scripts/build-zend-smoke.sh
./build/native/jinx-zend-smoke
./build/native/jinx-zend-array-builtin-smoke
./build/native/jinx-zend-array-delete-smoke

./scripts/build-zend-foreach-smoke.sh
./build/native/jinx-zend-foreach-smoke

./scripts/build-zend-foreach-execute-smoke.sh
./build/native/jinx-zend-foreach-execute-smoke

./scripts/build-zend-foreach-opcode-smoke.sh
./build/native/jinx-zend-foreach-opcode-smoke

./scripts/build-zend-foreach-program-smoke.sh
./build/native/jinx-zend-foreach-program-smoke

./scripts/build-zend-object-smoke.sh
./build/native/jinx-zend-object-smoke

./scripts/build-zend-method-opcode-smoke.sh
./build/native/jinx-zend-method-opcode-smoke

./scripts/build-zend-error-smoke.sh
./build/native/jinx-zend-error-smoke

./scripts/build-zend-throw-opcode-smoke.sh
./build/native/jinx-zend-throw-opcode-smoke

./scripts/build-zend-opcode-vm-smoke.sh
./build/native/jinx-zend-opcode-vm-smoke

./scripts/build-zend-lowering-fixture-smoke.sh
./build/native/jinx-zend-lowering-fixture-smoke

./scripts/build-zend-ir-fixture-smoke.sh
./build/native/jinx-zend-ir-fixture-smoke

./scripts/build-oracle-zend-array-carrier-smoke.sh
./build/native/jinx-oracle-zend-array-carrier-smoke

./scripts/build-oracle-zend-array-builtin-smoke.sh
./build/native/jinx-oracle-zend-array-builtin-smoke

./scripts/build-oracle-dispatch-zend-array-smoke.sh
./build/native/jinx-oracle-dispatch-zend-array-smoke

./scripts/build-oracle-array-fixture-cli.sh
./build/native/jinx-oracle-array-fixture-cli oracle-call count za:deleted
```

Full native build regenerates Oracle dispatch and compiles the native binary:

```bash
./scripts/build-native-jinx.sh
./build/native/jinx
```

## Rewrite families

| Family | php-src area | JINX native state | Oracle SM target | PASM target |
|---|---|---:|---|---|
| `zval` | `Zend/zend_types.h`, `Zend/zend.h` | started | `oracle-sm/zend/zval.osm` | `runtime/pasm/zend/zval.pasm` |
| `zend_string` | `Zend/zend_string.h`, `Zend/zend_string.c` | started | `oracle-sm/zend/string.osm` | `runtime/pasm/zend/string.pasm` |
| `HashTable/zend_array` | `Zend/zend_hash.h`, `Zend/zend_hash.c`, `Zend/zend_array.c` | started | `oracle-sm/zend/hash.osm` | `runtime/pasm/zend/hash.pasm` |
| `executor/call-frame` | `Zend/zend_execute.c`, `Zend/zend_vm_def.h`, `Zend/zend_vm_execute.h` | started | `oracle-sm/zend/executor.osm` | `runtime/pasm/zend/executor.pasm` |
| `objects/classes` | `Zend/zend_object_handlers.c`, `Zend/zend_objects_API.c`, `Zend/zend_compile.c` | started | `oracle-sm/zend/object.osm` | `runtime/pasm/zend/object.pasm` |
| `errors/exceptions` | `Zend/zend_exceptions.c`, `Zend/zend_errors.h` | started | `oracle-sm/zend/errors.osm` | `runtime/pasm/zend/errors.pasm` |
| `compiler/opcodes` | `Zend/zend_language_parser.y`, `Zend/zend_compile.c`, `Zend/zend_vm_def.h` | started | `oracle-sm/zend/opcodes.osm` | `runtime/pasm/zend/opcodes.pasm` |

## What exists now

The native Zend-shaped layer now has:

```text
JinxZendValue       zval-like tagged value
JinxZendString      borrowed views, owned buffers, refcount, COW, hashing
JinxZendArray       packed buckets, mixed string-key buckets, append, lookup, update, COW, insertion-order iteration
JinxZendBucket      numeric, string-key, or tombstone bucket carrying retained JinxZendValue
JinxZendForeachIterator live foreach cursor over Zend-array buckets
JinxZendForeachBody executor-style foreach body callback
JinxZendForeachFrame FE_RESET/FE_FETCH foreach opcode frame
JinxZendForeachProgramState minimal FE_RESET/FE_FETCH/BODY/JMP/HALT program runner
JinxZendClassEntry class metadata with method table
JinxZendClassTable native class lookup table
JinxZendMethodEntry named method handler slot
JinxZendMethodCallFrame INIT_METHOD_CALL/SEND_ARGS/DO_METHOD_CALL lowering frame
JinxZendErrorState warning/error/exception state carrier
JinxZendThrowable throwable descriptor over Exception/Error-style objects
JinxZendCatchFrame CATCH lowering frame over active throwable state
JinxZendVmState combined register VM for foreach/method/throw control flow
JinxZendVmOp fixture emitters for PHP-shaped foreach/method and throw/catch constructs
JinxZendIrFixture tiny text IR descriptor for fixture-shaped parser lowering
JinxValue carrier   Oracle/PASM value that can carry borrowed or retained JinxZendArray *
Oracle array bridge PHP builtin names routing carried JinxZendArray * through live-aware helpers
Generated dispatch pre-hook for carried JinxZendArray * before count-only fallbacks
Native CLI fixtures `za:sample` / `za:deleted` for real carried Zend arrays in `./jinx oracle-call`
JinxZendObject      object/class shell with property table
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
array delete/unset tombstones for numeric and string keys
live-aware PHP builtin-name bridge after tombstones
live count/key_exists/is_list/values/keys after tombstones
live iteration that skips tombstones
foreach iterator skips tombstones and yields live keys/values in insertion order
foreach_execute invokes an executor body once per live entry and advances executed_ops
FE_RESET accepts Zend-array sources and rejects non-arrays
FE_FETCH skips tombstones and yields live key/value slots
FE_RUN_ALL dispatches a lowered foreach body and advances executor ops
foreach opcode program sequences FE_RESET, FE_FETCH, FE_BODY, JMP, and HALT
foreach opcode program loops over live entries and skips tombstones
class table registration and lookup
object allocation with class metadata
object property set/get through native Zend arrays
method lookup and dispatch by class + method name
missing method error state without advancing executed_ops
INIT_METHOD_CALL rejects non-object sources
SEND_METHOD_ARGS stores argument slots
DO_METHOD_CALL dispatches through class table and stores return slot
method-call opcode lowering reports missing methods
warning/error state records level, message, file, and line
throwable objects carry message, code, file, and line properties
throw propagation stores throwable state and executor error state
THROW stores throwable objects into active exception state
THROW rejects non-object values as errors
CATCH matches active throwable class names or catch-all handlers
CLEAR_EXCEPTION clears executor and error-state exception slots
combined opcode VM sequences foreach, method calls, throw/catch, clear-exception, jumps, and halt
combined opcode VM uses one register stream over arrays, objects, methods, and throwable state
lowering fixtures emit VM op streams for foreach-method and throw-catch PHP-shaped constructs
lowered fixture programs run through the combined VM and produce expected register/error state
tiny text IR parses fixture-shaped commands into the same VM op streams
text IR smoke executes parsed foreach-method and throw-catch shapes through the combined VM
executor error state can be cleared
array compaction after tombstones
Oracle JinxValue borrowed/retained carriers for JinxZendArray pointers
Oracle array builtin bridge for carried JinxZendArray values
generated `jinx_call_builtin_through_oracle` routes carried Zend arrays before generated fallbacks
fixture CLI invokes generated dispatch with carried Zend arrays
main `./jinx oracle-call` parses `za:sample` and `za:deleted`
call-frame enter/leave
return-value propagation
family manifest enumeration
```

## Native opcode/control-flow helpers

The combined opcode VM and lowering fixture layers are:

```text
runtime/jinx_zend_opcode_vm.h
runtime/jinx_zend_lowering_fixture.h
runtime/jinx_zend_ir_fixture.h
```

They provide:

```text
JinxZendVmOpcode
JinxZendVmResult
JinxZendVmOp
JinxZendVmState
JinxZendIrFixture
jinx_zend_vm_state_init
jinx_zend_vm_run
jinx_zend_lower_foreach_method_fixture
jinx_zend_lower_throw_catch_fixture
jinx_zend_ir_fixture_parse
jinx_zend_ir_fixture_lower
```

Current combined VM / lowering smoke executables:

```bash
./build/native/jinx-zend-opcode-vm-smoke
./build/native/jinx-zend-lowering-fixture-smoke
./build/native/jinx-zend-ir-fixture-smoke
```

The VM is register-based and currently sequences `LOAD_CONST`, `FE_RESET`, `FE_FETCH`, `METHOD_CALL`, `THROW`, `CATCH`, `CLEAR_EXCEPTION`, `JMP`, `JMP_IF_EXCEPTION`, and `HALT`. The lowering fixture layer emits stable `JinxZendVmOp` streams for small PHP-shaped constructs. The tiny IR layer parses simple commands such as `foreach_method collect` and `throw_catch Exception input.ir`, then lowers them through the same emitters so a future parser can reuse the instruction stream.

## Native array builtin helpers

Implemented against `JinxZendArray`:

```text
jinx_zend_array_count_builtin
jinx_zend_array_key_exists_index
jinx_zend_array_key_exists_string
jinx_zend_array_is_list_builtin
jinx_zend_array_values_builtin
jinx_zend_array_keys_builtin
```

The tombstone-safe bridge smoke proves these PHP names route to live-aware helpers after delete/unset tombstones:

```text
count
array_key_exists
array_is_list
array_values
array_keys
```

## Native delete/tombstone helpers

The additive tombstone layer is:

```text
runtime/jinx_zend_array_delete.h
```

It proves delete, live count, live key existence, live list detection, values/keys, live iteration, and compaction.

## Native foreach lowering helpers

The foreach lowering layers are:

```text
runtime/jinx_zend_foreach.h
runtime/jinx_zend_foreach_opcode.h
runtime/jinx_zend_foreach_program.h
```

They provide iterator state, executor-style body dispatch, FE_RESET/FE_FETCH primitive frames, and a minimal FE_RESET/FE_FETCH/BODY/JMP/HALT program runner.

Current foreach smoke executables:

```bash
./build/native/jinx-zend-foreach-smoke
./build/native/jinx-zend-foreach-execute-smoke
./build/native/jinx-zend-foreach-opcode-smoke
./build/native/jinx-zend-foreach-program-smoke
```

## Native object/class and method-call helpers

The object/class dispatch layers are:

```text
runtime/jinx_zend_object.h
runtime/jinx_zend_method_opcode.h
```

They provide class metadata, method tables, property storage through native arrays, method dispatch, and INIT_METHOD_CALL / SEND_ARGS / DO_METHOD_CALL-style lowering.

Current object/method smoke executables:

```bash
./build/native/jinx-zend-object-smoke
./build/native/jinx-zend-method-opcode-smoke
```

## Native error/warning/exception helpers

The error/exception layers are:

```text
runtime/jinx_zend_error.h
runtime/jinx_zend_throw_opcode.h
```

They provide:

```text
JinxZendErrorLevel
JinxZendThrowable
JinxZendErrorState
JinxZendThrowOpcodeResult
JinxZendCatchFrame
jinx_zend_error_state_init
jinx_zend_error_state_has_error
jinx_zend_error_state_has_throwable
jinx_zend_executor_clear_error
jinx_zend_executor_raise
jinx_zend_error_state_raise
jinx_zend_error_level_name
jinx_zend_throwable_class_entry
jinx_zend_throwable_new
jinx_zend_throwable_describe
jinx_zend_error_state_throw
jinx_zend_catch_frame_init
jinx_zend_throw_value
jinx_zend_catch_exception
jinx_zend_clear_exception
```

Current error/throw smoke executables:

```bash
./build/native/jinx-zend-error-smoke
./build/native/jinx-zend-throw-opcode-smoke
```

The error layer records warnings/errors into executor and error-state slots, models exception/error objects as native objects with message/code/file/line properties, describes throwable properties, and propagates thrown objects into executor-visible exception state. The throw opcode layer maps this into THROW, CATCH, and CLEAR_EXCEPTION-style primitives with exact object/catch-class matching.

## Oracle/PASM JinxValue carrier and array builtin bridge

The bridge layers are:

```text
runtime/jinx_oracle_zend_array_carrier.h
runtime/jinx_oracle_zend_array_builtins.h
```

They provide carried Zend arrays in Oracle/PASM `JinxValue` and route `count`, `array_key_exists`, `array_is_list`, `array_values`, and `array_keys` to live-aware array helpers before generated fallbacks.

## Main native CLI examples

Build:

```bash
./scripts/build-native-jinx.sh
```

Run carried live arrays through `./jinx oracle-call`:

```bash
./jinx oracle-call count za:sample
# int:4

./jinx oracle-call count za:deleted
# int:2

./jinx oracle-call array_key_exists i:1 za:deleted
# bool:false

./jinx oracle-call array_key_exists s:keep za:deleted
# bool:true

./jinx oracle-call array_values za:deleted
# zend-array:2

./jinx oracle-call array_keys za:deleted
# zend-array:2
```

## Next implementation steps

1. Expand the tiny IR input language beyond fixtures toward statement/expression lowering.
2. Add parser/IR lowering so arbitrary PHP can run through the Zend-shaped executor.

## Rule

A PHP function should move from PHP fallback to exact native only when the Zend family it depends on exists natively. For example:

```text
array_values -> needs HashTable/zend_array
method_exists -> needs objects/classes
try/catch -> needs errors/exceptions
foreach -> needs HashTable/zend_array + executor/opcodes
```
