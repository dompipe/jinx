# Oracle SM Zend Family Stubs

These `.osm` files are stable Oracle SM targets for the JINX-owned Zend-shaped runtime families documented in `docs/ZEND_ENGINE_REWRITE.md`.

PHP remains the source language and compatibility contract. Oracle SM does not replace PHP syntax, PHP behavior, or PHP fallback. These files describe lower-level acceleration targets for selected PHP calls and hot runtime families after the matching native family has been implemented and smoke-tested.

## Families

```text
zval.osm                 PHP value carrier / JinxZendValue tagged value model
string.osm               PHP string storage: view/owned/refcount/COW/hash model
hash.osm                 PHP array storage: buckets, COW, tombstones, live helpers
array_builtins.osm       accelerated PHP array calls over native Zend arrays
foreach.osm              accelerated PHP foreach over native arrays
object.osm               PHP classes, objects, properties
method_call.osm          accelerated PHP method INIT/SEND/DO dispatch path
errors.osm               PHP warnings, errors, throwable objects
throw_catch.osm          PHP THROW/CATCH/CLEAR_EXCEPTION control flow
opcode_vm.osm            native VM substrate for accelerated PHP fragments
fixture_lowering.osm     fixture emitters into VM ops
ir_fixture.osm           tiny fixture text IR
statement_ir.osm         raw-register statement IR
variable_ir.osm          PHP-style variable-name-to-register assignment IR
scalar_ops.osm           accelerated integer ADD/SUB/EQ/LT and boolean jumps
oracle_carrier.osm       Oracle/PASM JinxValue Zend-array carrier for accelerated calls
cli_dispatch.osm         main CLI fixtures and generated Oracle dispatch integration
```

## Rule

Each `.osm` file must stay aligned with its native smoke tests before it is lowered into PASM. Oracle SM is a middle acceleration form, not a second source of truth. PHP semantics remain authoritative.