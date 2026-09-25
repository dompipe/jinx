# Zend Engine Rewrite Plan

JINX rewrites Zend Engine concepts into JINX-owned native runtime families that can lower into Oracle SM, Oracle ASM, and PASM without linking PHP.

This document is the ledger for what has changed so far. Each family lists the files that own it, the PHP/Zend portion it replaces, what has been implemented, what smoke tests prove it, and where it should lower next.

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
./scripts/build-zend-scalar-vm-smoke.sh
./build/native/jinx-zend-scalar-vm-smoke
./scripts/build-zend-lowering-fixture-smoke.sh
./build/native/jinx-zend-lowering-fixture-smoke
./scripts/build-zend-ir-fixture-smoke.sh
./build/native/jinx-zend-ir-fixture-smoke
./scripts/build-zend-statement-ir-smoke.sh
./build/native/jinx-zend-statement-ir-smoke
./scripts/build-zend-variable-ir-smoke.sh
./build/native/jinx-zend-variable-ir-smoke
./scripts/build-zend-variable-scalar-ir-smoke.sh
./build/native/jinx-zend-variable-scalar-ir-smoke

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

## Family status table

| Family | php-src area | JINX state | Oracle SM target | PASM target |
|---|---|---:|---|---|
| `zval` | `Zend/zend_types.h`, `Zend/zend.h` | started | `oracle-sm/zend/zval.osm` | `runtime/pasm/zend/zval.pasm` |
| `zend_string` | `Zend/zend_string.h`, `Zend/zend_string.c` | started | `oracle-sm/zend/string.osm` | `runtime/pasm/zend/string.pasm` |
| `HashTable/zend_array` | `Zend/zend_hash.h`, `Zend/zend_array.c` | started | `oracle-sm/zend/hash.osm` | `runtime/pasm/zend/hash.pasm` |
| `foreach/executor` | `Zend/zend_vm_def.h`, `Zend/zend_execute.c` | started | `oracle-sm/zend/foreach.osm` | `runtime/pasm/zend/foreach.pasm` |
| `objects/classes` | `Zend/zend_object_handlers.c`, `Zend/zend_objects_API.c` | started | `oracle-sm/zend/object.osm` | `runtime/pasm/zend/object.pasm` |
| `method calls` | `Zend/zend_compile.c`, `Zend/zend_execute.c` | started | `oracle-sm/zend/method-call.osm` | `runtime/pasm/zend/method-call.pasm` |
| `errors/exceptions` | `Zend/zend_exceptions.c`, `Zend/zend_errors.h` | started | `oracle-sm/zend/errors.osm` | `runtime/pasm/zend/errors.pasm` |
| `compiler/opcodes` | `Zend/zend_compile.c`, `Zend/zend_vm_def.h` | started | `oracle-sm/zend/opcodes.osm` | `runtime/pasm/zend/opcodes.pasm` |
| `Oracle/PASM bridge` | generated wrapper layer | started | `oracle-sm/zend/bridge.osm` | `runtime/pasm/zend/bridge.pasm` |

## Family ledger

### 1. `zval` / `JinxZendValue`

**Files changed**

```text
runtime/jinx_zend_engine.h
runtime/jinx_zend_engine.c
native/jinx_zend_smoke.c
```

**What changed**

```text
JinxZendValue        tagged value shell for null, bool, long, double, string, array, object, reference, resource
JinxZendType         native enum mirroring Zend value families
jinx_zend_null       null value construction
jinx_zend_bool       boolean value construction
jinx_zend_long       integer value construction
jinx_zend_double     double value construction
jinx_zend_value_copy shallow/refcount-aware copy entry point
jinx_zend_value_release value release entry point
```

**What it replaces**

```text
Zend/zend_types.h zval shape
Zend/zend.h common zval construction/release concepts
```

**Proven by**

```text
./build/native/jinx-zend-smoke
```

**Lowering target**

```text
Oracle SM: oracle-sm/zend/zval.osm
Oracle ASM: build/oracle-asm/zend/zval.oracle_asm.h
PASM: runtime/pasm/zend/zval.pasm
```

### 2. `zend_string`

**Files changed**

```text
runtime/jinx_zend_engine.h
runtime/jinx_zend_engine.c
native/jinx_zend_smoke.c
```

**What changed**

```text
JinxZendString              borrowed view and owned buffer representation
jinx_zend_string_view       non-owning string view
jinx_zend_string_new        owned allocation
jinx_zend_string_retain     refcount retain
jinx_zend_string_release    refcount release/destruct
jinx_zend_string_separate   copy-on-write separation
jinx_zend_string_set_byte   mutable byte write after COW
jinx_zend_string_hash_bytes stable string hash
jinx_zend_string_hash       hash for JinxZendString
jinx_zend_string_equals_bytes byte equality
jinx_zend_string_value      string value wrapper
```

**What it replaces**

```text
Zend/zend_string.h
Zend/zend_string.c
```

**Proven by**

```text
./build/native/jinx-zend-smoke
```

**Lowering target**

```text
Oracle SM: oracle-sm/zend/string.osm
Oracle ASM: build/oracle-asm/zend/string.oracle_asm.h
PASM: runtime/pasm/zend/string.pasm
```

### 3. `HashTable` / `zend_array`

**Files changed**

```text
runtime/jinx_zend_engine.h
runtime/jinx_zend_engine.c
runtime/jinx_zend_array_delete.h
native/jinx_zend_smoke.c
native/jinx_zend_array_builtin_smoke.c
native/jinx_zend_array_delete_smoke.c
```

**What changed**

```text
JinxZendArray                         packed/mixed array shell
JinxZendBucket                        numeric, string-key, or tombstone bucket
jinx_zend_array_new_packed            array allocation
jinx_zend_array_retain/release        refcount lifecycle
jinx_zend_array_clone                 clone
jinx_zend_array_separate              copy-on-write array separation
jinx_zend_array_append                append retained value
jinx_zend_array_add_assoc             string-key insert/update
jinx_zend_array_index                 numeric lookup
jinx_zend_array_find                  string-key lookup
jinx_zend_array_iter_at               physical bucket iteration
jinx_zend_array_delete_index          numeric tombstone delete/unset
jinx_zend_array_delete_string         string-key tombstone delete/unset
jinx_zend_array_live_count            tombstone-safe live count
jinx_zend_array_live_key_exists_*     tombstone-safe key checks
jinx_zend_array_live_is_list          tombstone-safe list check
jinx_zend_array_live_values           tombstone-safe array_values
jinx_zend_array_live_keys             tombstone-safe array_keys
jinx_zend_array_live_iter_at          tombstone-safe iteration
jinx_zend_array_compact               tombstone compaction
```

**What it replaces**

```text
Zend/zend_hash.h
Zend/zend_hash.c
Zend/zend_array.c
```

**Proven by**

```text
./build/native/jinx-zend-smoke
./build/native/jinx-zend-array-builtin-smoke
./build/native/jinx-zend-array-delete-smoke
```

**Lowering target**

```text
Oracle SM: oracle-sm/zend/hash.osm
Oracle ASM: build/oracle-asm/zend/hash.oracle_asm.h
PASM: runtime/pasm/zend/hash.pasm
```

### 4. Native array builtin bridge

**Files changed**

```text
runtime/jinx_zend_engine.h
runtime/jinx_zend_engine.c
runtime/jinx_zend_array_delete.h
runtime/jinx_oracle_zend_array_builtins.h
runtime/jinx_builtin_dispatch.generated.c
scripts/generate-oracle-dispatch-table.php
native/jinx_zend_array_builtin_smoke.c
native/jinx_oracle_zend_array_builtin_smoke.c
native/jinx_oracle_dispatch_zend_array_smoke.c
```

**What changed**

```text
count                  native live count for carried Zend arrays
array_key_exists       native live key check for numeric/string keys
array_is_list          native tombstone-safe list detection
array_values           native packed values extraction
array_keys             native packed key extraction
jinx_call_builtin_through_oracle pre-hook for carried Zend-array values
```

**What it replaces**

```text
PHP userland fallback for array-family builtins once JinxZendArray is available
Generated count-only placeholder fallback for carried Zend arrays
```

**Proven by**

```text
./build/native/jinx-zend-array-builtin-smoke
./build/native/jinx-oracle-zend-array-builtin-smoke
./build/native/jinx-oracle-dispatch-zend-array-smoke
./build/native/jinx-oracle-array-fixture-cli oracle-call count za:deleted
./jinx oracle-call count za:deleted
```

**Lowering target**

```text
Oracle SM: oracle-sm/zend/array-builtins.osm
Oracle ASM: build/oracle-asm/zend/array-builtins.oracle_asm.h
PASM: runtime/pasm/zend/array-builtins.pasm
```

### 5. Foreach iteration and lowering

**Files changed**

```text
runtime/jinx_zend_foreach.h
runtime/jinx_zend_foreach_opcode.h
runtime/jinx_zend_foreach_program.h
native/jinx_zend_foreach_smoke.c
native/jinx_zend_foreach_execute_smoke.c
native/jinx_zend_foreach_opcode_smoke.c
native/jinx_zend_foreach_program_smoke.c
```

**What changed**

```text
JinxZendForeachIterator     physical bucket cursor that skips tombstones
JinxZendForeachEntry        yielded key/value/live-position entry
JinxZendForeachBody         lowered loop body callback type
jinx_zend_foreach_next      live foreach iteration
jinx_zend_foreach_execute   executor-style loop body dispatch
JinxZendForeachFrame        FE_RESET/FE_FETCH frame
jinx_zend_fe_reset          foreach reset over arrays
jinx_zend_fe_fetch          foreach fetch into key/value slots
jinx_zend_fe_run_all        run loop body over all live entries
JinxZendForeachProgramState FE_RESET/FE_FETCH/BODY/JMP/HALT program runner
```

**What it replaces**

```text
Zend VM FE_RESET / FE_FETCH behavior for arrays
The foreach-specific pieces of Zend/zend_vm_def.h and Zend/zend_execute.c
```

**Proven by**

```text
./build/native/jinx-zend-foreach-smoke
./build/native/jinx-zend-foreach-execute-smoke
./build/native/jinx-zend-foreach-opcode-smoke
./build/native/jinx-zend-foreach-program-smoke
```

**Lowering target**

```text
Oracle SM: oracle-sm/zend/foreach.osm
Oracle ASM: build/oracle-asm/zend/foreach.oracle_asm.h
PASM: runtime/pasm/zend/foreach.pasm
```

### 6. Objects, classes, and properties

**Files changed**

```text
runtime/jinx_zend_engine.h
runtime/jinx_zend_object.h
native/jinx_zend_object_smoke.c
```

**What changed**

```text
JinxZendObject             object shell with class_name and properties table
JinxZendClassEntry         native class metadata
JinxZendClassTable         native class lookup table
JinxZendMethodEntry        named method slot
JinxZendMethodHandler      native method callback type
jinx_zend_object_new       object allocation from class entry
jinx_zend_object_retain    object retain
jinx_zend_object_release   object release
jinx_zend_class_table_*    class registry init/register/find/release
jinx_zend_object_set_property property write through JinxZendArray
jinx_zend_object_get_property property read through JinxZendArray
jinx_zend_call_method      native class-table method dispatch
```

**What it replaces**

```text
Zend/zend_object_handlers.c
Zend/zend_objects_API.c
Class metadata needed by Zend/zend_compile.c
```

**Proven by**

```text
./build/native/jinx-zend-object-smoke
```

**Lowering target**

```text
Oracle SM: oracle-sm/zend/object.osm
Oracle ASM: build/oracle-asm/zend/object.oracle_asm.h
PASM: runtime/pasm/zend/object.pasm
```

### 7. Method-call lowering

**Files changed**

```text
runtime/jinx_zend_method_opcode.h
native/jinx_zend_method_opcode_smoke.c
```

**What changed**

```text
JinxZendMethodCallFrame     INIT_METHOD_CALL/SEND_ARGS/DO_METHOD_CALL frame
jinx_zend_method_frame_init reset method call state
jinx_zend_init_method_call  validate object receiver and method name
jinx_zend_send_method_args  store argument span
jinx_zend_do_method_call    resolve class/method and call native handler
```

**What it replaces**

```text
Zend VM INIT_METHOD_CALL / SEND_VAL / DO_FCALL-style method dispatch path
```

**Proven by**

```text
./build/native/jinx-zend-method-opcode-smoke
```

**Lowering target**

```text
Oracle SM: oracle-sm/zend/method-call.osm
Oracle ASM: build/oracle-asm/zend/method-call.oracle_asm.h
PASM: runtime/pasm/zend/method-call.pasm
```

### 8. Errors, warnings, and throwable objects

**Files changed**

```text
runtime/jinx_zend_error.h
native/jinx_zend_error_smoke.c
```

**What changed**

```text
JinxZendErrorLevel          notice/warning/recoverable/error/exception levels
JinxZendErrorState          active diagnostic/throwable state
JinxZendThrowable           descriptor over native throwable object properties
jinx_zend_error_state_raise records warning/error metadata
jinx_zend_executor_raise    maps error to executor last_error/error_level
jinx_zend_executor_clear_error clears executor error fields
jinx_zend_throwable_class_entry creates throwable class entry
jinx_zend_throwable_new     native Exception/Error-style object with message/code/file/line
jinx_zend_throwable_describe reads throwable properties back into metadata
jinx_zend_error_state_throw records active thrown object
```

**What it replaces**

```text
Zend/zend_errors.h
Zend/zend_exceptions.c base Throwable/Error/Exception state concepts
```

**Proven by**

```text
./build/native/jinx-zend-error-smoke
```

**Lowering target**

```text
Oracle SM: oracle-sm/zend/errors.osm
Oracle ASM: build/oracle-asm/zend/errors.oracle_asm.h
PASM: runtime/pasm/zend/errors.pasm
```

### 9. Throw/catch opcode lowering

**Files changed**

```text
runtime/jinx_zend_throw_opcode.h
native/jinx_zend_throw_opcode_smoke.c
```

**What changed**

```text
JinxZendCatchFrame          catch class + caught object state
jinx_zend_throw_value       THROW object validation and active exception state
jinx_zend_catch_exception   exact-class or catch-all match over active throwable
jinx_zend_clear_exception   clear throwable and executor error state
```

**What it replaces**

```text
Zend VM THROW / CATCH / exception clear flow
```

**Proven by**

```text
./build/native/jinx-zend-throw-opcode-smoke
```

**Lowering target**

```text
Oracle SM: oracle-sm/zend/throw-catch.osm
Oracle ASM: build/oracle-asm/zend/throw-catch.oracle_asm.h
PASM: runtime/pasm/zend/throw-catch.pasm
```

### 10. Combined opcode VM

**Files changed**

```text
runtime/jinx_zend_opcode_vm.h
native/jinx_zend_opcode_vm_smoke.c
native/jinx_zend_scalar_vm_smoke.c
```

**What changed**

```text
JinxZendVmState          register VM state
JinxZendVmOp             instruction record
JinxZendVmOpcode         VM opcode enum
jinx_zend_vm_run         combined instruction runner
LOAD_CONST               value-slot load
COPY                     register assignment
ADD/SUB                  integer scalar arithmetic
EQ/LT                    integer comparison into bool values
FE_RESET/FE_FETCH        foreach array iteration
METHOD_CALL              object method call
THROW/CATCH/CLEAR        exception flow
JMP/JMP_IF_EXCEPTION     control flow
JMP_IF_TRUE/FALSE        boolean control flow
HALT                     clean stop
```

**What it replaces**

```text
A small, JINX-owned executable subset of Zend/zend_vm_def.h and Zend/zend_vm_execute.h
```

**Proven by**

```text
./build/native/jinx-zend-opcode-vm-smoke
./build/native/jinx-zend-scalar-vm-smoke
```

**Lowering target**

```text
Oracle SM: oracle-sm/zend/opcodes.osm
Oracle ASM: build/oracle-asm/zend/opcodes.oracle_asm.h
PASM: runtime/pasm/zend/opcodes.pasm
```

### 11. Fixture lowering into VM ops

**Files changed**

```text
runtime/jinx_zend_lowering_fixture.h
native/jinx_zend_lowering_fixture_smoke.c
```

**What changed**

```text
jinx_zend_lower_foreach_method_fixture emits VM ops for foreach($array as $key=>$value){ $object->method($value); }
jinx_zend_lower_throw_catch_fixture emits VM ops for try/throw/catch/clear shape
```

**What it replaces**

```text
Hand-written VM op arrays for early compiler-shaped tests
```

**Proven by**

```text
./build/native/jinx-zend-lowering-fixture-smoke
```

**Lowering target**

```text
Oracle SM: oracle-sm/zend/lowering-fixtures.osm
Oracle ASM: build/oracle-asm/zend/lowering-fixtures.oracle_asm.h
PASM: runtime/pasm/zend/lowering-fixtures.pasm
```

### 12. Tiny fixture IR

**Files changed**

```text
runtime/jinx_zend_ir_fixture.h
native/jinx_zend_ir_fixture_smoke.c
```

**What changed**

```text
JinxZendIrFixture          parsed fixture descriptor
foreach_method <method>    parses and lowers through foreach fixture emitter
throw_catch <class> <file> parses and lowers through throw/catch fixture emitter
```

**What it replaces**

```text
Direct C calls to fixture emitters, creating a first text-to-VM bridge
```

**Proven by**

```text
./build/native/jinx-zend-ir-fixture-smoke
```

**Lowering target**

```text
Oracle SM: oracle-sm/zend/ir-fixture.osm
Oracle ASM: build/oracle-asm/zend/ir-fixture.oracle_asm.h
PASM: runtime/pasm/zend/ir-fixture.pasm
```

### 13. Statement IR

**Files changed**

```text
runtime/jinx_zend_statement_ir.h
native/jinx_zend_statement_ir_smoke.c
```

**What changed**

```text
JinxZendStatementIrProgram line-oriented raw-register IR
label <name>               defines a jump target
const <dst> <slot>         lowers to LOAD_CONST
call <dst> <obj> <method>  lowers to METHOD_CALL
throw <src> [file]         lowers to THROW
catch <dst> <class> <miss> lowers to CATCH with patch target
clear                      lowers to CLEAR_EXCEPTION
jump                       lowers to JMP with resolved label
jump_if_exception          lowers to JMP_IF_EXCEPTION with resolved label
halt                       lowers to HALT
```

**What it replaces**

```text
Manual raw-register VM op arrays and hard-coded jump targets
```

**Proven by**

```text
./build/native/jinx-zend-statement-ir-smoke
```

**Lowering target**

```text
Oracle SM: oracle-sm/zend/statement-ir.osm
Oracle ASM: build/oracle-asm/zend/statement-ir.oracle_asm.h
PASM: runtime/pasm/zend/statement-ir.pasm
```

### 14. Variable IR and assignment

**Files changed**

```text
runtime/jinx_zend_variable_ir.h
native/jinx_zend_variable_ir_smoke.c
```

**What changed**

```text
JinxZendVariableIrProgram variable-name-to-register table
var <name> <reg>          binds PHP-like name to VM register
load <name> <slot>        value-slot load into named variable
set <dst> <src>           named assignment lowered to COPY
callv                     named method call lowering
throwv                    named throw lowering
catchv                    named catch lowering
jump/jump_if_exception    label-aware control flow passthrough
```

**What it replaces**

```text
Pure numeric-register source IR where every user variable had to be assigned by hand
```

**Proven by**

```text
./build/native/jinx-zend-variable-ir-smoke
```

**Lowering target**

```text
Oracle SM: oracle-sm/zend/variable-ir.osm
Oracle ASM: build/oracle-asm/zend/variable-ir.oracle_asm.h
PASM: runtime/pasm/zend/variable-ir.pasm
```

### 15. Scalar expressions and conditional branches

**Files changed**

```text
runtime/jinx_zend_opcode_vm.h
runtime/jinx_zend_variable_ir.h
native/jinx_zend_scalar_vm_smoke.c
native/jinx_zend_variable_scalar_ir_smoke.c
```

**What changed**

```text
ADD/SUB                   integer arithmetic in the VM
EQ/LT                     integer comparisons into bool values
JMP_IF_TRUE/FALSE         bool-based branch opcodes
addv/subv                 named arithmetic lowering
eqv/ltv                   named comparison lowering
jump_if_truev/falsev      named bool branch lowering
```

**What it replaces**

```text
Missing native arithmetic/comparison layer needed for conditions, loop counters, and scalar expressions
```

**Proven by**

```text
./build/native/jinx-zend-scalar-vm-smoke
./build/native/jinx-zend-variable-scalar-ir-smoke
```

**Lowering target**

```text
Oracle SM: oracle-sm/zend/scalar-expr.osm
Oracle ASM: build/oracle-asm/zend/scalar-expr.oracle_asm.h
PASM: runtime/pasm/zend/scalar-expr.pasm
```

### 16. Oracle/PASM Zend-array carrier

**Files changed**

```text
runtime/jinx_oracle_zend_array_carrier.h
native/jinx_oracle_zend_array_carrier_smoke.c
```

**What changed**

```text
JINX_ORACLE_VALUE_ZEND_ARRAY           Oracle/PASM carrier type id
jinx_oracle_zend_array_value_borrowed  borrowed JinxZendArray carrier
jinx_oracle_zend_array_value_retained  retained JinxZendArray carrier
jinx_oracle_value_is_zend_array        carrier type check
jinx_oracle_zend_array_ptr             unwrap JinxZendArray pointer
jinx_oracle_zend_array_value_release   release retained carrier
```

**What it replaces**

```text
Count-only JinxValue placeholder for arrays, allowing real native ZendArray pointers to pass through Oracle dispatch
```

**Proven by**

```text
./build/native/jinx-oracle-zend-array-carrier-smoke
```

**Lowering target**

```text
Oracle SM: oracle-sm/zend/oracle-carrier.osm
Oracle ASM: build/oracle-asm/zend/oracle-carrier.oracle_asm.h
PASM: runtime/pasm/zend/oracle-carrier.pasm
```

### 17. Main CLI fixtures and generated dispatch integration

**Files changed**

```text
native/jinx_cli.c
native/jinx_oracle_array_fixture_cli.c
scripts/build-native-jinx.sh
scripts/build-oracle-array-fixture-cli.sh
scripts/generate-oracle-dispatch-table.php
runtime/jinx_builtin_dispatch.generated.c
```

**What changed**

```text
za:sample                 CLI parser fixture for live Zend array
za:deleted                CLI parser fixture for tombstoned Zend array
./jinx oracle-call        can pass real carried Zend arrays to generated dispatch
build-native-jinx.sh      regenerates Oracle dispatch before native compile
generated dispatch        checks carried Zend arrays before fallback wrappers
```

**What it replaces**

```text
Manual smoke-only testing of carried Zend arrays
Array placeholder/count-only dispatch path
```

**Proven by**

```text
./build/native/jinx-oracle-array-fixture-cli oracle-call count za:deleted
./jinx oracle-call count za:deleted
./jinx oracle-call array_key_exists i:1 za:deleted
./jinx oracle-call array_values za:deleted
```

**Lowering target**

```text
Oracle SM: oracle-sm/zend/dispatch.osm
Oracle ASM: build/oracle-asm/zend/dispatch.oracle_asm.h
PASM: runtime/pasm/zend/dispatch.pasm
```

## Native CLI examples

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

1. Add parser/AST lowering into variable IR / `JinxZendVmOp` streams.
2. Add more scalar operators and PHP type coercions.
3. Wire small PHP examples through the native executor path.
4. Start emitting matching Oracle SM stubs for each family above.
5. Lower those Oracle SM stubs into Oracle ASM / PASM.

## Rule

A PHP function should move from fallback to exact native only when the Zend family it depends on exists natively.

```text
array_values -> needs HashTable/zend_array
method_exists -> needs objects/classes
try/catch -> needs errors/exceptions
foreach -> needs HashTable/zend_array + executor/opcodes
```
