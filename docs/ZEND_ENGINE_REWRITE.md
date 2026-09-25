# Zend Engine Rewrite Plan

JINX rewrites Zend Engine concepts into JINX-owned native runtime families that can lower into Oracle SM, Oracle ASM, and PASM without linking PHP.

This document is the family ledger. Every family changed so far lists the native owner files, what was added, what Zend/php-src area it replaces, what smoke tests prove it, and the exact Oracle SM / PASM target.

## Oracle SM manifest

```text
oracle-sm/zend/README.md
```

The manifest names every Oracle SM family stub created for this rewrite. Each `.osm` file is a stable middle target between the current native C implementation and future PASM lowering.

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

## Smoke test commands

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

| Family | Replaces | Native state | Oracle SM | PASM |
|---|---|---:|---|---|
| zval / JinxZendValue | `Zend/zend_types.h`, `Zend/zend.h` | started | `oracle-sm/zend/zval.osm` | `runtime/pasm/zend/zval.pasm` |
| zend_string | `Zend/zend_string.h`, `Zend/zend_string.c` | started | `oracle-sm/zend/string.osm` | `runtime/pasm/zend/string.pasm` |
| HashTable / zend_array | `Zend/zend_hash.h`, `Zend/zend_hash.c`, `Zend/zend_array.c` | started | `oracle-sm/zend/hash.osm` | `runtime/pasm/zend/hash.pasm` |
| array builtins | selected array builtins | started | `oracle-sm/zend/array_builtins.osm` | `runtime/pasm/zend/array_builtins.pasm` |
| foreach | `FE_RESET`, `FE_FETCH`, foreach loop | started | `oracle-sm/zend/foreach.osm` | `runtime/pasm/zend/foreach.pasm` |
| objects/classes | object handlers and object API | started | `oracle-sm/zend/object.osm` | `runtime/pasm/zend/object.pasm` |
| method calls | INIT/SEND/DO method-call path | started | `oracle-sm/zend/method_call.osm` | `runtime/pasm/zend/method_call.pasm` |
| errors/throwables | errors and exceptions | started | `oracle-sm/zend/errors.osm` | `runtime/pasm/zend/errors.pasm` |
| throw/catch | exception control flow | started | `oracle-sm/zend/throw_catch.osm` | `runtime/pasm/zend/throw_catch.pasm` |
| opcode VM | Zend VM subset | started | `oracle-sm/zend/opcode_vm.osm` | `runtime/pasm/zend/opcode_vm.pasm` |
| fixture lowering | early compiler-shaped emitters | started | `oracle-sm/zend/fixture_lowering.osm` | `runtime/pasm/zend/fixture_lowering.pasm` |
| IR fixture | tiny fixture text IR | started | `oracle-sm/zend/ir_fixture.osm` | `runtime/pasm/zend/ir_fixture.pasm` |
| statement IR | raw-register statement IR | started | `oracle-sm/zend/statement_ir.osm` | `runtime/pasm/zend/statement_ir.pasm` |
| variable IR | named variable slots | started | `oracle-sm/zend/variable_ir.osm` | `runtime/pasm/zend/variable_ir.pasm` |
| scalar ops | arithmetic/comparison/boolean branch subset | started | `oracle-sm/zend/scalar_ops.osm` | `runtime/pasm/zend/scalar_ops.pasm` |
| Oracle carrier | Oracle/PASM Zend-array carrier | started | `oracle-sm/zend/oracle_carrier.osm` | `runtime/pasm/zend/oracle_carrier.pasm` |
| CLI/dispatch | main CLI fixture + generated dispatch | started | `oracle-sm/zend/cli_dispatch.osm` | `runtime/pasm/zend/cli_dispatch.pasm` |

## Family ledger

### 1. zval / JinxZendValue

**Files changed**

```text
runtime/jinx_zend_engine.h
runtime/jinx_zend_engine.c
native/jinx_zend_smoke.c
oracle-sm/zend/zval.osm
```

**What changed**

```text
JinxZendValue tagged value carrier
null, bool, long, double, string, array, object, reference, resource families
retain/release entry points for contained refcounted values
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

### 2. zend_string

**Files changed**

```text
runtime/jinx_zend_engine.h
runtime/jinx_zend_engine.c
native/jinx_zend_smoke.c
oracle-sm/zend/string.osm
```

**What changed**

```text
borrowed string views
owned string allocations
refcount retain/release
copy-on-write separation
hash and equality helpers
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

### 3. HashTable / zend_array

**Files changed**

```text
runtime/jinx_zend_engine.h
runtime/jinx_zend_engine.c
runtime/jinx_zend_array_delete.h
native/jinx_zend_smoke.c
native/jinx_zend_array_delete_smoke.c
oracle-sm/zend/hash.osm
```

**What changed**

```text
packed and mixed arrays
numeric and string-key buckets
array retain/release/clone/COW
append, lookup, update
numeric and string-key tombstone delete/unset
live count, live iteration, compaction
```

**Proven by**

```text
./build/native/jinx-zend-smoke
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
runtime/jinx_zend_array_delete.h
runtime/jinx_oracle_zend_array_builtins.h
runtime/jinx_builtin_dispatch.generated.c
scripts/generate-oracle-dispatch-table.php
native/jinx_zend_array_builtin_smoke.c
native/jinx_oracle_zend_array_builtin_smoke.c
native/jinx_oracle_dispatch_zend_array_smoke.c
oracle-sm/zend/array_builtins.osm
```

**What changed**

```text
count over native Zend arrays
array_key_exists over live buckets
array_is_list over live numeric keys
array_values over live values
array_keys over live keys
generated dispatch pre-hook for carried Zend arrays
```

**Proven by**

```text
./build/native/jinx-zend-array-builtin-smoke
./build/native/jinx-oracle-zend-array-builtin-smoke
./build/native/jinx-oracle-dispatch-zend-array-smoke
```

**Lowering target**

```text
Oracle SM: oracle-sm/zend/array_builtins.osm
Oracle ASM: build/oracle-asm/zend/array_builtins.oracle_asm.h
PASM: runtime/pasm/zend/array_builtins.pasm
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
oracle-sm/zend/foreach.osm
```

**What changed**

```text
live foreach iterator over physical bucket order
skip tombstones
yield numeric/string keys and values
executor-style foreach body dispatch
FE_RESET / FE_FETCH primitive frame
minimal FE_RESET / FE_FETCH / BODY / JMP / HALT runner
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
runtime/jinx_zend_object.h
native/jinx_zend_object_smoke.c
oracle-sm/zend/object.osm
```

**What changed**

```text
class entries and method tables
class table register/find/release
object allocation/retain/release
property set/get through native Zend arrays
method lookup support
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
oracle-sm/zend/method_call.osm
```

**What changed**

```text
INIT_METHOD_CALL frame
SEND_ARGS frame storage
DO_METHOD_CALL through class table
return-value storage
missing-method error handling
```

**Proven by**

```text
./build/native/jinx-zend-method-opcode-smoke
```

**Lowering target**

```text
Oracle SM: oracle-sm/zend/method_call.osm
Oracle ASM: build/oracle-asm/zend/method_call.oracle_asm.h
PASM: runtime/pasm/zend/method_call.pasm
```

### 8. Errors, warnings, and throwable objects

**Files changed**

```text
runtime/jinx_zend_error.h
native/jinx_zend_error_smoke.c
oracle-sm/zend/errors.osm
```

**What changed**

```text
error levels
executor last_error/error_level bridge
error state with message/file/line/throwable
throwable class entry helper
throwable object construction with message/code/file/line
throwable descriptor readback
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
oracle-sm/zend/throw_catch.osm
```

**What changed**

```text
THROW over object zvals
CATCH exact-class or catch-all match
miss branch target
CLEAR_EXCEPTION state reset
non-object throw type error
```

**Proven by**

```text
./build/native/jinx-zend-throw-opcode-smoke
```

**Lowering target**

```text
Oracle SM: oracle-sm/zend/throw_catch.osm
Oracle ASM: build/oracle-asm/zend/throw_catch.oracle_asm.h
PASM: runtime/pasm/zend/throw_catch.pasm
```

### 10. Combined opcode VM

**Files changed**

```text
runtime/jinx_zend_opcode_vm.h
native/jinx_zend_opcode_vm_smoke.c
native/jinx_zend_scalar_vm_smoke.c
oracle-sm/zend/opcode_vm.osm
```

**What changed**

```text
register VM state
LOAD_CONST, COPY
ADD, SUB, EQ, LT
FE_RESET, FE_FETCH
METHOD_CALL
THROW, CATCH, CLEAR_EXCEPTION
JMP, JMP_IF_EXCEPTION, JMP_IF_TRUE, JMP_IF_FALSE
HALT
```

**Proven by**

```text
./build/native/jinx-zend-opcode-vm-smoke
./build/native/jinx-zend-scalar-vm-smoke
```

**Lowering target**

```text
Oracle SM: oracle-sm/zend/opcode_vm.osm
Oracle ASM: build/oracle-asm/zend/opcode_vm.oracle_asm.h
PASM: runtime/pasm/zend/opcode_vm.pasm
```

### 11. Fixture lowering into VM ops

**Files changed**

```text
runtime/jinx_zend_lowering_fixture.h
native/jinx_zend_lowering_fixture_smoke.c
oracle-sm/zend/fixture_lowering.osm
```

**What changed**

```text
foreach_method fixture emitter
throw_catch fixture emitter
stable JinxZendVmOp streams for compiler-shaped smoke programs
```

**Proven by**

```text
./build/native/jinx-zend-lowering-fixture-smoke
```

**Lowering target**

```text
Oracle SM: oracle-sm/zend/fixture_lowering.osm
Oracle ASM: build/oracle-asm/zend/fixture_lowering.oracle_asm.h
PASM: runtime/pasm/zend/fixture_lowering.pasm
```

### 12. Tiny fixture IR

**Files changed**

```text
runtime/jinx_zend_ir_fixture.h
native/jinx_zend_ir_fixture_smoke.c
oracle-sm/zend/ir_fixture.osm
```

**What changed**

```text
foreach_method <method>
throw_catch <catch-class> <throw-file>
parse/lower into fixture VM op streams
fail-closed parsing
```

**Proven by**

```text
./build/native/jinx-zend-ir-fixture-smoke
```

**Lowering target**

```text
Oracle SM: oracle-sm/zend/ir_fixture.osm
Oracle ASM: build/oracle-asm/zend/ir_fixture.oracle_asm.h
PASM: runtime/pasm/zend/ir_fixture.pasm
```

### 13. Statement IR

**Files changed**

```text
runtime/jinx_zend_statement_ir.h
native/jinx_zend_statement_ir_smoke.c
oracle-sm/zend/statement_ir.osm
```

**What changed**

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
label patching and unknown-label failure
```

**Proven by**

```text
./build/native/jinx-zend-statement-ir-smoke
```

**Lowering target**

```text
Oracle SM: oracle-sm/zend/statement_ir.osm
Oracle ASM: build/oracle-asm/zend/statement_ir.oracle_asm.h
PASM: runtime/pasm/zend/statement_ir.pasm
```

### 14. Variable IR and assignment

**Files changed**

```text
runtime/jinx_zend_variable_ir.h
native/jinx_zend_variable_ir_smoke.c
oracle-sm/zend/variable_ir.osm
```

**What changed**

```text
var <name> <reg>
load <name> <value-slot>
set <dst-name> <src-name>
callv, throwv, catchv
named variable lookup
unknown-variable failure
set lowers into COPY
```

**Proven by**

```text
./build/native/jinx-zend-variable-ir-smoke
```

**Lowering target**

```text
Oracle SM: oracle-sm/zend/variable_ir.osm
Oracle ASM: build/oracle-asm/zend/variable_ir.oracle_asm.h
PASM: runtime/pasm/zend/variable_ir.pasm
```

### 15. Scalar expressions and conditional branches

**Files changed**

```text
runtime/jinx_zend_opcode_vm.h
runtime/jinx_zend_variable_ir.h
native/jinx_zend_scalar_vm_smoke.c
native/jinx_zend_variable_scalar_ir_smoke.c
oracle-sm/zend/scalar_ops.osm
```

**What changed**

```text
ADD, SUB over longs
EQ, LT over longs producing bools
JMP_IF_TRUE, JMP_IF_FALSE over bools
addv, subv, eqv, ltv
jump_if_truev, jump_if_falsev
fail-closed type checks for non-long arithmetic and non-bool branches
```

**Proven by**

```text
./build/native/jinx-zend-scalar-vm-smoke
./build/native/jinx-zend-variable-scalar-ir-smoke
```

**Lowering target**

```text
Oracle SM: oracle-sm/zend/scalar_ops.osm
Oracle ASM: build/oracle-asm/zend/scalar_ops.oracle_asm.h
PASM: runtime/pasm/zend/scalar_ops.pasm
```

### 16. Oracle/PASM Zend-array carrier

**Files changed**

```text
runtime/jinx_oracle_zend_array_carrier.h
runtime/jinx_oracle_zend_array_builtins.h
native/jinx_oracle_zend_array_carrier_smoke.c
native/jinx_oracle_zend_array_builtin_smoke.c
oracle-sm/zend/oracle_carrier.osm
```

**What changed**

```text
JINX_ORACLE_VALUE_ZEND_ARRAY carrier type
borrowed and retained JinxValue carriers for JinxZendArray pointers
carrier unwrap and release rules
Oracle dispatch bridge to native array builtins
```

**Proven by**

```text
./build/native/jinx-oracle-zend-array-carrier-smoke
./build/native/jinx-oracle-zend-array-builtin-smoke
```

**Lowering target**

```text
Oracle SM: oracle-sm/zend/oracle_carrier.osm
Oracle ASM: build/oracle-asm/zend/oracle_carrier.oracle_asm.h
PASM: runtime/pasm/zend/oracle_carrier.pasm
```

### 17. Main CLI fixtures and generated dispatch integration

**Files changed**

```text
native/jinx_cli.c
native/jinx_oracle_array_fixture_cli.c
runtime/jinx_builtin_dispatch.generated.c
scripts/generate-oracle-dispatch-table.php
scripts/build-native-jinx.sh
oracle-sm/zend/cli_dispatch.osm
```

**What changed**

```text
za:sample fixture
za:deleted fixture
./jinx oracle-call parsing for carried Zend arrays
generated dispatch pre-hook for carried arrays
native build regenerates dispatch before compile
```

**Proven by**

```text
./build/native/jinx-oracle-array-fixture-cli oracle-call count za:deleted
./jinx oracle-call count za:deleted
```

**Lowering target**

```text
Oracle SM: oracle-sm/zend/cli_dispatch.osm
Oracle ASM: build/oracle-asm/zend/cli_dispatch.oracle_asm.h
PASM: runtime/pasm/zend/cli_dispatch.pasm
```

## Next implementation steps

```text
1. Generate first Oracle ASM skeletons from the Oracle SM family stubs.
2. Add parser/AST lowering into variable IR / JinxZendVmOp streams.
3. Add more scalar operators and type coercions.
4. Wire small PHP examples through the native executor path.
```

## Rule

A PHP function should move from fallback to exact native only when the Zend family it depends on exists natively.

```text
array_values -> needs HashTable/zend_array
method_exists -> needs objects/classes
try/catch -> needs errors/exceptions
foreach -> needs HashTable/zend_array + executor/opcodes
```
