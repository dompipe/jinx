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
fixture. Include paths are resolved relative to the current source file;
PHP include_path lookup and missing-file warning behavior are not implemented.
The interpreter rejects overflow rather than promoting integers to floats.
Undefined variables currently read as null without PHP's warning.

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
before target output.

The high-value semantic edge suite is a separate gate. The native interpreter
fixes both include and both filesystem first-wave cases, reducing mismatches
from 17 to 13 out of 20. Reference, object/static/clone, closure, callback, typed
property and type-error cases remain unsupported. Do not infer full-stack
success from this focused test.
