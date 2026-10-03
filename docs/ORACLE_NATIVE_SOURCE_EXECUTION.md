# Oracle source execution inside JINX

The root native executable now contains a PHP source interpreter implemented in
`runtime/jinx_oracle_native_script.c`. Select it explicitly with:

```bash
./jinx --native-php fixtures/oracle-native-source.php
JINX_NATIVE_ONLY=1 ./jinx fixtures/oracle-native-source.php
```

This path never starts PHP or the PHP-hosted Oracle script bridge. Scalar calls
use the existing native Oracle builtin dispatch. Input is checked before that
file executes, including statements following a return. Unsupported syntax
produces an error instead of a PHP fallback.

The initial supported subset includes integer/string/bool/null literals, local
assignments, integer arithmetic, concatenation, parentheses, echo, return,
strict_types declarations, strlen/strtoupper/strtolower/abs, __DIR__/__FILE__,
and include/require/include_once/require_once with shared variables and file
return values. It also supports array literals through native Zend carriers,
JSON output through existing Oracle dispatch, nowdoc literals, +=, lazy ??,
string-keyed GLOBALS access, and the native filesystem calls used by the edge
fixture. Reference bindings (`=&`) share native Zend reference cells between
variables and array elements. Array-offset reads/writes, +=, *=, postfix ++,
variable unset, and braced foreach-by-reference execute inside JINX. Foreach
leaves its variable bound to the last element until unset. Ordinary array
assignments use independent containers while preserving explicit reference
cells.

Public class properties now have native declaration metadata for int/string/bool
and untyped values. Static property lookup shares inherited storage unless a
child redeclares it. Instances retain their own property storage and preserve
object identity through ordinary assignments. Uninitialized reads raise Error;
incompatible typed assignments raise TypeError without replacing the old value.
Braced try/catch handles Throwable, Error and TypeError (including nested catches),
and `$e::class` exposes the caught error class. String call_user_func callbacks
can invoke admitted native builtins; missing targets raise TypeError.

Named user functions now execute in isolated native call frames, including
nested calls, scalar int/string/bool parameters and return types, and catchable
TypeError/ArgumentCountError. Both declarations and calls require strict_types=1.
Arguments containing arrays receive independent containers with shared explicit
reference cells. Caller locals are restored even when a nested call throws.
Forward calls before declaration, defaults, variadics, by-reference parameters,
type hints other than scalars/Closure and weak scalar coercion are not yet admitted.

Anonymous functions support explicit `use ($value)` and `use (&$value)` captures,
scalar signatures and variable calls in expressions. Reference captures retain
the shared Zend cell after the outer variable is unset. By-value captures take
an independent snapshot and restore that snapshot on each call. Prefix ++ on
admitted slots also executes natively. Arrow functions capture local values by
snapshot. Both ordinary closures and arrows created in an instance method
retain `$this` and its private-access scope. Closure return hints are admitted.
Closure reflection/rebinding, and closures stored in Zend arrays/reference cells remain
outside this subset.

Public/private instance methods execute in native call frames with scalar
argument/return checks, nested calls, and class-scoped property visibility.
Private static properties also enforce scope. Native clone copies the object's
property container, preserves ordinary shallow-copy semantics, and invokes an
admitted __clone hook. Private scalar state is independent in cloned objects.
User constructors, property promotion, protected members, private inheritance
layouts and magic methods other than __clone are not yet admitted.

Include paths are resolved relative to the current source file;
PHP include_path lookup and missing-file warning behavior are not implemented.
The interpreter rejects overflow rather than promoting integers to floats.
Undefined variables currently read as null without PHP's warning.
Foreach key bindings, by-value foreach, loop break/continue, nested array-offset
syntax, and structural mutation during foreach are outside this native subset.
Registered builtin constructors, constructors with arguments, protected/readonly properties,
custom exception hierarchies, finally, multiple catches, array/closure callbacks,
and PHP-identical diagnostic messages are still outside this subset. This work
proves normalized error classes and preserved state, not full exception parity.

This is an initial native subset, not arbitrary PHP compatibility. Ordinary
non-orchestration PHP inputs first attempt native syntax admission. Accepted
files execute inside JINX. Files rejected before execution still go through
the existing PHP-hosted Oracle runner. A failure after native execution starts
does not retry through that runner. JINX_NATIVE_ONLY and --native-php disable
the bridge entirely.
Existing PHP executor families have not all been ported into this native
interpreter. Build generators and the test driver also still use PHP.

Verify using the root executable:

```bash
git pull origin master
./scripts/build-native-jinx.sh
./jinx scripts/test-native-oracle-source.php
PATH=/jinx-test-no-executables JINX_NATIVE_ONLY=1 ./jinx fixtures/oracle-native-source.php
PATH=/jinx-test-no-executables ./jinx fixtures/oracle-native-edge-source.php
```

The test compares against PHP independently, then launches the absolute JINX
binary with an empty executable search path and an invalid bridge path. It
checks arithmetic, native builtin calls, include returns, shared include scope,
once behavior, runtime-created includes, GLOBALS updates, JSON output,
filesystem resource round trips, suppressed missing-file reads, and rejection
before target output. `fixtures/oracle-native-references.php` also checks
reference mutation, rebinding, unset detachment, lingering foreach bindings,
array-copy isolation, aliases surviving bucket growth, and coerced array keys.
`fixtures/oracle-native-properties.php` compares static shadowing and sharing,
typed defaults/assignments, uninitialized reads, independent instances, object
identity, nested catch matching, invalid callbacks, and include return values
when catch/foreach return statements are not executed.
`fixtures/oracle-native-functions.php` checks typed calls, nested frames,
local isolation, missing arguments, return type validation and caller state
after nested exceptions, with PHP absent from the target process PATH.
`fixtures/oracle-native-closures.php` compares reference mutation, repeated
by-value calls, unset lifetime and catchable typed closure calls in the same
PHP-independent target environment.
`fixtures/oracle-native-clone-methods.php` checks private clone state, nested
methods and errors, access restrictions, private static storage and __clone
hooks. Closure fixtures also check returned bound closures and arrow snapshots.

The high-value semantic edge suite is a separate gate. The native interpreter
fixes both include, both filesystem and both reference first-wave cases.
Property and invalid-callback execution additionally reduces mismatches from
11 to 6 out of 20. Native function frames reduce the remaining failures to 5:
two clone cases, two closure cases,
and static-method/closure callback composition. Reference-capturing closure
execution reduces this further to 4 out of 20; cloning, closure `$this` binding
and static-method/closure callback composition remain unsupported. Do not infer full-stack
success from this focused test.

Native methods, private-state clone and bound arrows now reduce that gate to
2 failures out of 20: constructor-driven deep cloning and static-method/closure
callback composition. This is not a claim of arbitrary PHP compatibility.
