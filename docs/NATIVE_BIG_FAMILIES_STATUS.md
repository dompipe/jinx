# Native big-family parity

Baseline: e0df807b8ccd5de391b16d735f7ab80741a89e9b.

The official differential scripts now isolate the JINX child with an empty
executable search path, JINX_NATIVE_ONLY=1, and an unusable script bridge.
PHP runs separately as the reference. Exit status, stdout, and stderr are
compared. Fixture inventory counts are independent of executed parity counts.

Verified on Ubuntu through WSL:

| Suite | Baseline | After changes |
| --- | --- | --- |
| Official wave one | 19/20 | 20/20 |
| Official wave two | 14/20 | 20/20 |
| Big-family strict probes | 6/10 | 10/10 |

The coverage audit confirms 40 official fixtures across 20 distinct semantic
areas. It does not prove 40 passing native executions.

Magic-property overloading and both official enum cases pass. The dedicated
magic and enum/match probes also pass.

Changes implement keyed nested destructuring, array spread token handling,
array append destinations, ordered catches, optional catch bindings, finally
return/exception precedence, and direct exception construction in throw
statements. They also fix standalone nullsafe calls and parsing after a
readonly dynamic-property exception.

Destructor declarations fail before execution until lifecycle execution is
implemented. The source suite tests int|array as a supported union and retains
invalid-union rejection checks.

Namespace execution now supports semicolon and braced scopes, separate class,
function, and constant imports, aliases, qualified and absolute names, lexical
scope in function/method bodies, namespaced declarations, and global builtin
function fallback. Regression coverage includes imported object types, static
properties, enums, and namespace collisions.

Native generators now retain local variables and parser position between
suspensions. Creation is lazy; current/key/valid/next/send/rewind/getReturn use
the retained execution state. Yield from delegates to arrays or another native
generator, forwards sent values, and supplies the delegate's return value to an
assignment. Foreach and iterator_to_array consume that same state.

The lifecycle fixture additionally proves lazy side effects, early getReturn,
rewind errors, automatic integer keys, duplicate-key behavior, and send through
a delegated generator.

No official wave-two failures remain. This is bounded fixture parity, not
complete PHP compatibility. Yield is admitted at standalone or assignment
statement boundaries. Generator closures, yields nested in try/foreach, and
return-yield expressions fail before output until deeper continuation support
is implemented. Unsupported executions fail with PHP fallback disabled.
