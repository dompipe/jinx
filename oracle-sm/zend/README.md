# Oracle SM Zend Family Stubs

These `.osm` files are the stable Oracle SM targets for the JINX-owned Zend-shaped runtime families documented in `docs/ZEND_ENGINE_REWRITE.md`.

They are intentionally declarative stubs first: each file names the native family, the C runtime files that currently implement it, the VM/IR surfaces that exercise it, and the PASM path that should receive the next-lower lowering.

## Families

```text
zval.osm                 zval / JinxZendValue tagged value model
string.osm               zend_string view/owned/refcount/COW/hash model
hash.osm                 HashTable / zend_array buckets, COW, tombstones, live helpers
array_builtins.osm       PHP array builtin bridge over native Zend arrays
foreach.osm              foreach iterator, FE_RESET/FE_FETCH, foreach program lowering
object.osm               classes, objects, properties
method_call.osm          method INIT/SEND/DO lowering
errors.osm               warnings, errors, throwable objects
throw_catch.osm          THROW/CATCH/CLEAR_EXCEPTION lowering
opcode_vm.osm            combined VM instruction stream
fixture_lowering.osm     fixture emitters into VM ops
ir_fixture.osm           tiny fixture text IR
statement_ir.osm         raw-register statement IR
variable_ir.osm          variable-name-to-register assignment IR
scalar_ops.osm           ADD/SUB/EQ/LT and boolean jump scalar ops
oracle_carrier.osm       Oracle/PASM JinxValue Zend-array carrier
cli_dispatch.osm         main CLI fixtures and generated Oracle dispatch integration
```

## Rule

Each `.osm` file must stay aligned with its native smoke tests before it is lowered into PASM. Oracle SM is the middle form, not a second source of truth.
