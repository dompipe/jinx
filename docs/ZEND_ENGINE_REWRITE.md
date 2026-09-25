# Zend Engine Rewrite Plan

JINX keeps PHP as the source language and compatibility contract. Oracle is the interpreter/mirroring layer for php-src and Zend behavior. Native Zend-shaped runtime pieces accelerate selected PHP calls and hot runtime families after Oracle has a faithful representation. PASM/native binary emission is secondary later output, not a blocker for PHP/Zend mirroring.

This document is the family ledger. Every family changed so far lists the owner files, what was added, what Zend/php-src area it mirrors, what smoke tests prove it, and the Oracle target. PASM targets are kept only as optional later backend notes.

## Architecture rule

```text
PHP stays visible.
PHP semantics stay authoritative.
Oracle records and interprets php-src/Zend behavior first.
Native JINX paths accelerate calls and runtime families after Oracle coverage exists.
PASM/native binary emission is optional later output.
Generated lower layers are not a second source of truth.
```

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
./scripts/build-native-jinx.sh
./jinx scripts/test-oracle-program-compiler.php
./jinx scripts/test-zend-arbitrary-code-oracle.php
./jinx scripts/test-zend-runtime-ops-oracle.php

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

| Family | PHP/Zend role | Native state | Oracle SM | PASM |
|---|---|---:|---|---|
| zval / JinxZendValue | PHP value carrier | started | `oracle-sm/zend/zval.osm` | `runtime/pasm/zend/zval.pasm` |
| zend_string | PHP string carrier | started | `oracle-sm/zend/string.osm` | `runtime/pasm/zend/string.pasm` |
| HashTable / zend_array | PHP array carrier | started | `oracle-sm/zend/hash.osm` | `runtime/pasm/zend/hash.pasm` |
| array builtins | accelerated PHP array calls | started | `oracle-sm/zend/array_builtins.osm` | `runtime/pasm/zend/array_builtins.pasm` |
| foreach | accelerated PHP foreach over arrays | started | `oracle-sm/zend/foreach.osm` | `runtime/pasm/zend/foreach.pasm` |
| objects/classes | PHP object/class runtime | started | `oracle-sm/zend/object.osm` | `runtime/pasm/zend/object.pasm` |
| method calls | accelerated PHP method dispatch | started | `oracle-sm/zend/method_call.osm` | `runtime/pasm/zend/method_call.pasm` |
| errors/throwables | PHP warnings/errors/throwables | started | `oracle-sm/zend/errors.osm` | `runtime/pasm/zend/errors.pasm` |
| throw/catch | PHP exception control flow | started | `oracle-sm/zend/throw_catch.osm` | `runtime/pasm/zend/throw_catch.pasm` |
| opcode VM | native execution substrate for accelerated PHP paths | started | `oracle-sm/zend/opcode_vm.osm` | `runtime/pasm/zend/opcode_vm.pasm` |
| fixture lowering | early compiler-shaped emitters | started | `oracle-sm/zend/fixture_lowering.osm` | `runtime/pasm/zend/fixture_lowering.pasm` |
| IR fixture | tiny fixture text IR | started | `oracle-sm/zend/ir_fixture.osm` | `runtime/pasm/zend/ir_fixture.pasm` |
| statement IR | raw-register statement IR | started | `oracle-sm/zend/statement_ir.osm` | `runtime/pasm/zend/statement_ir.pasm` |
| variable IR | PHP-style variable names mapped to registers | started | `oracle-sm/zend/variable_ir.osm` | `runtime/pasm/zend/variable_ir.pasm` |
| scalar ops | accelerated scalar expression subset | started | `oracle-sm/zend/scalar_ops.osm` | `runtime/pasm/zend/scalar_ops.pasm` |
| Oracle carrier | accelerated call bridge for native Zend arrays | started | `oracle-sm/zend/oracle_carrier.osm` | `runtime/pasm/zend/oracle_carrier.pasm` |
| CLI/dispatch | PHP-call acceleration entry points | started | `oracle-sm/zend/cli_dispatch.osm` | `runtime/pasm/zend/cli_dispatch.pasm` |
| arbitrary Zend records | Oracle interpreter records for broad PHP/Zend source | started | `runtime/OracleProgramCompiler.php` | optional later backend |

## Family ledger

Each family below is a PHP compatibility layer first and an acceleration target second.

### 1. zval / JinxZendValue

- Native owner: `runtime/jinx_zend_engine.h`, `runtime/jinx_zend_engine.c`
- PHP role: value carrier for PHP null/bool/int/float/string/array/object/reference/resource values.
- Changed: added `JinxZendValue`, `JinxZendType`, value constructors, shallow/refcount-aware copy and release entry points.
- Proven by: `./build/native/jinx-zend-smoke`
- Oracle SM target: `oracle-sm/zend/zval.osm`
- PASM target: `runtime/pasm/zend/zval.pasm`

### 2. zend_string

- Native owner: `runtime/jinx_zend_engine.h`, `runtime/jinx_zend_engine.c`
- PHP role: string storage for PHP strings and string array keys.
- Changed: added borrowed views, owned buffers, refcount lifecycle, COW separation, byte mutation after COW, hashing, equality, and string value wrappers.
- Proven by: `./build/native/jinx-zend-smoke`
- Oracle SM target: `oracle-sm/zend/string.osm`
- PASM target: `runtime/pasm/zend/string.pasm`

### 3. HashTable / zend_array

- Native owner: `runtime/jinx_zend_engine.h`, `runtime/jinx_zend_engine.c`, `runtime/jinx_zend_array_delete.h`
- PHP role: PHP array storage and object property storage.
- Changed: added packed/mixed buckets, string keys, numeric keys, COW, insertion-order iteration, tombstone deletes, live count, live key checks, live list detection, live values/keys, live iteration, and compaction.
- Proven by: `./build/native/jinx-zend-smoke`, `./build/native/jinx-zend-array-builtin-smoke`, `./build/native/jinx-zend-array-delete-smoke`
- Oracle SM target: `oracle-sm/zend/hash.osm`
- PASM target: `runtime/pasm/zend/hash.pasm`

### 4. Native array builtin bridge

- Native owner: `runtime/jinx_oracle_zend_array_builtins.h`, `scripts/generate-oracle-dispatch-table.php`, generated dispatch table
- PHP role: accelerate PHP calls `count`, `array_key_exists`, `array_is_list`, `array_values`, and `array_keys` when the value is a native Zend array.
- Changed: generated dispatch now routes carried Zend-array values into live-aware native helpers before fallback paths.
- Proven by: `./build/native/jinx-oracle-zend-array-builtin-smoke`, `./build/native/jinx-oracle-dispatch-zend-array-smoke`, `./jinx oracle-call count za:deleted`
- Oracle SM target: `oracle-sm/zend/array_builtins.osm`
- PASM target: `runtime/pasm/zend/array_builtins.pasm`

### 5. Foreach iteration and lowering

- Native owner: `runtime/jinx_zend_foreach.h`, `runtime/jinx_zend_foreach_opcode.h`, `runtime/jinx_zend_foreach_program.h`
- PHP role: accelerate `foreach ($array as $key => $value)` over native arrays.
- Changed: added tombstone-skipping foreach iterator, executor body callback, FE_RESET/FE_FETCH frame, and small foreach program runner.
- Proven by: `./build/native/jinx-zend-foreach-smoke`, `./build/native/jinx-zend-foreach-execute-smoke`, `./build/native/jinx-zend-foreach-opcode-smoke`, `./build/native/jinx-zend-foreach-program-smoke`
- Oracle SM target: `oracle-sm/zend/foreach.osm`
- PASM target: `runtime/pasm/zend/foreach.pasm`

### 6. Objects, classes, and properties

- Native owner: `runtime/jinx_zend_object.h`
- PHP role: PHP object/class/property storage for accelerated method/property paths.
- Changed: added class entries, class table, method entries, object allocation/retain/release, property set/get through native arrays, and native method dispatch.
- Proven by: `./build/native/jinx-zend-object-smoke`
- Oracle SM target: `oracle-sm/zend/object.osm`
- PASM target: `runtime/pasm/zend/object.pasm`

### 7. Method-call lowering

- Native owner: `runtime/jinx_zend_method_opcode.h`
- PHP role: accelerate method calls once object/class metadata is native.
- Changed: added INIT_METHOD_CALL, SEND_ARGS, DO_METHOD_CALL-style frame and dispatch helpers.
- Proven by: `./build/native/jinx-zend-method-opcode-smoke`
- Oracle SM target: `oracle-sm/zend/method_call.osm`
- PASM target: `runtime/pasm/zend/method_call.pasm`

### 8. Errors, warnings, and throwable objects

- Native owner: `runtime/jinx_zend_error.h`
- PHP role: preserve PHP warning/error/throwable state for accelerated paths.
- Changed: added error levels, error state, executor error propagation, throwable class entry, throwable object creation, throwable description, and active thrown-object state.
- Proven by: `./build/native/jinx-zend-error-smoke`
- Oracle SM target: `oracle-sm/zend/errors.osm`
- PASM target: `runtime/pasm/zend/errors.pasm`

### 9. Throw/catch opcode lowering

- Native owner: `runtime/jinx_zend_throw_opcode.h`
- PHP role: accelerate `throw`, `catch`, and exception clearing while preserving PHP-style throwable objects.
- Changed: added THROW validation, catch-class matching, catch-all matching, and CLEAR_EXCEPTION.
- Proven by: `./build/native/jinx-zend-throw-opcode-smoke`
- Oracle SM target: `oracle-sm/zend/throw_catch.osm`
- PASM target: `runtime/pasm/zend/throw_catch.pasm`

### 10. Combined opcode VM

- Native owner: `runtime/jinx_zend_opcode_vm.h`
- PHP role: execution substrate for accelerated PHP fragments, not a replacement for PHP parsing.
- Changed: added register VM ops for constants, copy, foreach, method calls, throw/catch, scalar math/comparisons, boolean jumps, exception jumps, and halt.
- Proven by: `./build/native/jinx-zend-opcode-vm-smoke`, `./build/native/jinx-zend-scalar-vm-smoke`
- Oracle SM target: `oracle-sm/zend/opcode_vm.osm`
- PASM target: `runtime/pasm/zend/opcode_vm.pasm`

### 11. Fixture lowering into VM ops

- Native owner: `runtime/jinx_zend_lowering_fixture.h`
- PHP role: early compiler-shaped emitters for selected PHP-shaped constructs.
- Changed: added fixture emitters for foreach-method and throw-catch shapes.
- Proven by: `./build/native/jinx-zend-lowering-fixture-smoke`
- Oracle SM target: `oracle-sm/zend/fixture_lowering.osm`
- PASM target: `runtime/pasm/zend/fixture_lowering.pasm`

### 12. Tiny fixture IR

- Native owner: `runtime/jinx_zend_ir_fixture.h`
- PHP role: temporary text bridge for fixture-shaped lowering until real parser/AST lowering is connected.
- Changed: added `foreach_method` and `throw_catch` text forms.
- Proven by: `./build/native/jinx-zend-ir-fixture-smoke`
- Oracle SM target: `oracle-sm/zend/ir_fixture.osm`
- PASM target: `runtime/pasm/zend/ir_fixture.pasm`

### 13. Statement IR

- Native owner: `runtime/jinx_zend_statement_ir.h`
- PHP role: raw-register statement representation for accelerated PHP fragments.
- Changed: added label, const, call, throw, catch, clear, jump, jump-if-exception, and halt forms.
- Proven by: `./build/native/jinx-zend-statement-ir-smoke`
- Oracle SM target: `oracle-sm/zend/statement_ir.osm`
- PASM target: `runtime/pasm/zend/statement_ir.pasm`

### 14. Variable IR and assignment

- Native owner: `runtime/jinx_zend_variable_ir.h`
- PHP role: map PHP-style variable names onto VM registers for accelerated fragments.
- Changed: added `var`, `load`, `set`, `callv`, `throwv`, `catchv`, and named control-flow helpers.
- Proven by: `./build/native/jinx-zend-variable-ir-smoke`
- Oracle SM target: `oracle-sm/zend/variable_ir.osm`
- PASM target: `runtime/pasm/zend/variable_ir.pasm`

### 15. Scalar expressions and conditional branches

- Native owner: `runtime/jinx_zend_opcode_vm.h`, `runtime/jinx_zend_variable_ir.h`
- PHP role: accelerate simple integer arithmetic/comparison conditions in PHP fragments.
- Changed: added ADD, SUB, EQ, LT, JMP_IF_TRUE, JMP_IF_FALSE plus `addv`, `subv`, `eqv`, `ltv`, `jump_if_truev`, and `jump_if_falsev`.
- Proven by: `./build/native/jinx-zend-scalar-vm-smoke`, `./build/native/jinx-zend-variable-scalar-ir-smoke`
- Oracle SM target: `oracle-sm/zend/scalar_ops.osm`
- PASM target: `runtime/pasm/zend/scalar_ops.pasm`

### 16. Oracle/PASM Zend-array carrier

- Native owner: `runtime/jinx_oracle_zend_array_carrier.h`
- PHP role: carry native PHP arrays through accelerated Oracle/PASM call paths without losing PHP array semantics.
- Changed: added borrowed/retained `JinxZendArray *` carrier values and release helper.
- Proven by: `./build/native/jinx-oracle-zend-array-carrier-smoke`
- Oracle SM target: `oracle-sm/zend/oracle_carrier.osm`
- PASM target: `runtime/pasm/zend/oracle_carrier.pasm`

### 17. Main CLI fixtures and generated dispatch integration

- Native owner: `native/jinx_cli.c`, `native/jinx_oracle_array_fixture_cli.c`, `scripts/generate-oracle-dispatch-table.php`, `scripts/build-native-jinx.sh`
- PHP role: expose accelerated call paths from the `./jinx oracle-call` entry point while preserving normal PHP call names.
- Changed: added `za:sample` and `za:deleted` fixture parsing, generated Zend-array dispatch pre-hook, and native build regeneration.
- Proven by: `./build/native/jinx-oracle-array-fixture-cli oracle-call count za:deleted`, `./jinx oracle-call count za:deleted`
- Oracle SM target: `oracle-sm/zend/cli_dispatch.osm`
- PASM target: `runtime/pasm/zend/cli_dispatch.pasm`

## Rule for promotion

A PHP call or fragment may move from PHP fallback to native acceleration only when its required family is present and tested.

```text
array_values -> needs HashTable/zend_array
method_exists -> needs objects/classes
try/catch -> needs errors/exceptions + throw/catch
foreach -> needs HashTable/zend_array + foreach/opcodes
integer expression -> needs zval + scalar ops + VM branches
```

PHP remains the user-facing language and semantic authority. Oracle SM, Oracle ASM, and PASM are lower-level acceleration outputs.
