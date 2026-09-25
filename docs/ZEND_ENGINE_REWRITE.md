# Zend Engine Rewrite Plan

JINX rewrites Zend Engine concepts into JINX-owned native runtime families that can lower into Oracle SM, Oracle ASM, and PASM without linking PHP.

## Native runtime layers

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
runtime/jinx_zend_statement_ir.h
runtime/jinx_zend_variable_ir.h
runtime/jinx_oracle_zend_array_carrier.h
runtime/jinx_oracle_zend_array_builtins.h
```

## Build and smoke tests

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
./scripts/build-zend-statement-ir-smoke.sh
./build/native/jinx-zend-statement-ir-smoke
./scripts/build-zend-variable-ir-smoke.sh
./build/native/jinx-zend-variable-ir-smoke

./scripts/build-oracle-zend-array-carrier-smoke.sh
./build/native/jinx-oracle-zend-array-carrier-smoke
./scripts/build-oracle-zend-array-builtin-smoke.sh
./build/native/jinx-oracle-zend-array-builtin-smoke
./scripts/build-oracle-dispatch-zend-array-smoke.sh
./build/native/jinx-oracle-dispatch-zend-array-smoke
./scripts/build-oracle-array-fixture-cli.sh
./build/native/jinx-oracle-array-fixture-cli oracle-call count za:deleted
```

Full native build:

```bash
./scripts/build-native-jinx.sh
./jinx
```

## Rewrite families

| Family | php-src area | JINX state | Oracle SM target | PASM target |
|---|---|---:|---|---|
| `zval` | `Zend/zend_types.h`, `Zend/zend.h` | started | `oracle-sm/zend/zval.osm` | `runtime/pasm/zend/zval.pasm` |
| `zend_string` | `Zend/zend_string.h`, `Zend/zend_string.c` | started | `oracle-sm/zend/string.osm` | `runtime/pasm/zend/string.pasm` |
| `HashTable/zend_array` | `Zend/zend_hash.h`, `Zend/zend_hash.c`, `Zend/zend_array.c` | started | `oracle-sm/zend/hash.osm` | `runtime/pasm/zend/hash.pasm` |
| `executor/call-frame` | `Zend/zend_execute.c`, `Zend/zend_vm_def.h` | started | `oracle-sm/zend/executor.osm` | `runtime/pasm/zend/executor.pasm` |
| `objects/classes` | `Zend/zend_object_handlers.c`, `Zend/zend_objects_API.c` | started | `oracle-sm/zend/object.osm` | `runtime/pasm/zend/object.pasm` |
| `errors/exceptions` | `Zend/zend_exceptions.c`, `Zend/zend_errors.h` | started | `oracle-sm/zend/errors.osm` | `runtime/pasm/zend/errors.pasm` |
| `compiler/opcodes` | `Zend/zend_compile.c`, `Zend/zend_vm_def.h` | started | `oracle-sm/zend/opcodes.osm` | `runtime/pasm/zend/opcodes.pasm` |

## What exists now

```text
JinxZendValue              zval-like tagged value
JinxZendString             string view/owned/refcount/COW/hash helpers
JinxZendArray              packed/mixed buckets, COW, live iteration, tombstones
JinxZendForeachIterator    live foreach cursor over Zend-array buckets
JinxZendForeachFrame       FE_RESET/FE_FETCH foreach opcode frame
JinxZendForeachProgram     FE_RESET/FE_FETCH/BODY/JMP/HALT runner
JinxZendClassEntry/Table   native class metadata and lookup table
JinxZendMethodCallFrame    INIT_METHOD_CALL/SEND_ARGS/DO_METHOD_CALL frame
JinxZendErrorState         warning/error/exception state carrier
JinxZendThrowable          throwable descriptor over Exception/Error-style objects
JinxZendCatchFrame         CATCH lowering frame
JinxZendVmState            combined register VM for foreach/method/throw/copy control flow
JinxZendIrFixture          tiny text fixture IR bridge
JinxZendStatementIrProgram line-oriented statement/expression IR bridge
JinxZendVariableIrProgram  variable-name-to-register lowering bridge
JinxValue carrier          Oracle/PASM value carrying Zend arrays
```

## Variable IR

`runtime/jinx_zend_variable_ir.h` maps variable names onto VM registers and lowers assignment-like forms into VM ops. This is the bridge from raw register IR toward PHP-style `$name` slots.

Supported forms:

```text
var <name> <reg>
load <name> <value-slot>
set <dst-name> <src-name>
callv <dst-name> <object-name> <method> [arg-name]
throwv <name> [file]
catchv <name> <class-or-*> <miss-label>
clear
jump <label>
jump_if_exception <label>
halt
```

`set` lowers into the VM `COPY` opcode. `load` still takes caller-provided value slots so runtime value construction stays separate from syntax lowering.

Example:

```text
var value 0
var tmp 1
var object 2
var result 3
load value 0
set tmp value
load object 1
callv result object record tmp
halt
```

## Statement IR

`runtime/jinx_zend_statement_ir.h` lowers a small line-oriented IR directly into `JinxZendVmOp` streams. It is the current raw-register bridge between fixture lowering and variable/parser lowering.

Supported forms:

```text
label <name>
const <dst-reg> <value-slot>
call <dst-reg> <object-reg> <method> [arg-start] [argc]
throw <src-reg> [file]
catch <dst-reg> <class-or-*> <miss-label>
clear
jump <label>
jump_if_exception <label>
halt
```

## Combined VM

`runtime/jinx_zend_opcode_vm.h` provides a register VM that sequences:

```text
LOAD_CONST
COPY
FE_RESET
FE_FETCH
METHOD_CALL
THROW
CATCH
CLEAR_EXCEPTION
JMP
JMP_IF_EXCEPTION
HALT
```

The VM ties together arrays, live foreach iteration, object method dispatch, throwable state, catch/clear control flow, variable assignment through register copy, and jumps.

## Oracle/PASM bridge

```text
runtime/jinx_oracle_zend_array_carrier.h
runtime/jinx_oracle_zend_array_builtins.h
```

These carry native Zend arrays through Oracle/PASM `JinxValue` and route `count`, `array_key_exists`, `array_is_list`, `array_values`, and `array_keys` to live-aware helpers before generated fallbacks.

Main CLI examples:

```bash
./scripts/build-native-jinx.sh
./jinx oracle-call count za:sample
./jinx oracle-call count za:deleted
./jinx oracle-call array_key_exists i:1 za:deleted
./jinx oracle-call array_key_exists s:keep za:deleted
./jinx oracle-call array_values za:deleted
./jinx oracle-call array_keys za:deleted
```

Expected outputs:

```text
int:4
int:2
bool:false
bool:true
zend-array:2
zend-array:2
```

## Next implementation steps

1. Add scalar expression opcodes such as arithmetic and comparisons.
2. Add real parser/AST lowering into variable IR / `JinxZendVmOp` streams.
3. Wire small PHP examples through the native executor path.

## Rule

A PHP function should move from fallback to exact native only when the Zend family it depends on exists natively.

```text
array_values -> needs HashTable/zend_array
method_exists -> needs objects/classes
try/catch -> needs errors/exceptions
foreach -> needs HashTable/zend_array + executor/opcodes
```
