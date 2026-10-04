# JINX Finish Audit

Audit date: 2026-10-03. Runtime baseline: commit `ffc26bf`.
Local comparison PHP: 8.4.25. CI requests 8.4.23 but validates only the 8.4 minor family.

## Scope and limitations

Inventoried and text-scanned all 737 tracked files, totaling 411,485 lines,
including generated manifests, code, tests, configuration and documentation.
Reviewed the execution front doors, native source interpreter, Zend carriers,
dispatch ledger, PHP-hosted family registry, build/CI, benchmarks and release
claims in depth. This is not a semantic line-by-line verification of every line.
Ignored build outputs, binaries and historical ZIP snapshots were not reviewed
as authoritative source. The complete full-stack suite was not rerun.

No runtime implementation was changed during this audit. The new audit driver
records independently compared native source results in
`build/audits/native-source-parity.json`.

## Verified state

| Gate | Current result | What it establishes |
| --- | --- | --- |
| First-wave ordinary comparison | 20/20 pass, verified in the preceding implementation run | Root JINX output matches the selected PHP cases; bridge use is possible |
| First-wave strict native audit | 18/20 exact matches, including stderr | These 18 generated fixtures run with PHP absent from target PATH and bridge disabled |
| Second-wave ordinary comparison | 20/20 comparisons fail | Additional semantics remain unfinished |
| Second-wave strict native audit | 0 native matches; 19 mismatches and 1 nonzero PHP baseline | No successful native proof from this wave |
| Native wiring audit | FAIL at `posix_mknod` | Reviewed ledger and extracted implementation disagree |
| Native method receiver probes | PASS: 500 newly added callable routes | Bounded dedicated receiver-path proof, not complete class/method parity |
| Oracle family coverage audit | PASS: 1,164 families across 53 parity tests | PHP-hosted family/fixture evidence, not PHP-independent source execution |
| 1,144-case coverage audit | PASS | Case-count/assertion/wiring audit; not execution of all 1,144 cases in this run |

The two first-wave native gaps are:

- `exception-catch-order-finally.php`: throw, builtin throwable construction, catch ordering and finally.
- `zend-array-nested-copy-on-write.php`: nested offset assignment and isolated array copies.

Wave two's `array-nested-destructuring.php` mixes keyed and unkeyed destructuring
and PHP exits 255. Repair that intended positive fixture, or explicitly make it
a negative parse-error parity test. Do not count it as a normal execution probe.

## The principal architecture gap

`native/jinx_cli.c` still uses `execvp("php", ...)` for orchestration scripts
and the PHP-hosted Oracle runner when native source admission fails.
`runtime/OracleExecutionFamilies.php` and the `Oracle*Executor.php` owners contain
substantial reusable behavior, but those owners are PHP implementations.
`oracle-sm/zend/*.osm` are documented family targets/stubs, not proof that the
whole PHP source language executes through a complete native Oracle interpreter.

The native source front door is `runtime/jinx_oracle_native_script.c`. It admits
21 builtin names. Backend registration is far broader, but a backend handler
existing does not make its call usable in arbitrary native PHP source.

The recorded wiring ledger contains 3,527 wrappers: 1,196 builtins and 2,331
methods. It records 974 named builtin routes and 2,553 intentional builtin-path
faults. Those numbers are inventory classifications, not a completion percentage.
Method names also have a separate receiver path; do not label every method
unimplemented just because its ordinary builtin route intentionally faults.
The failed wiring gate means the ledger needs reconciliation before its recorded
counts can be treated as current implementation counts.

## Work to finish, in order

### 1. Restore trustworthy release gates

Review the `posix_mknod` implementation and its arity, errors, platform behavior
and side effects, then reconcile the reviewed wiring ledger. Continue until the
whole wiring audit passes; the first mismatch may hide more drift. Repair the
destructuring fixture. Choose and document an exact PHP compatibility version
or an explicit version matrix. Keep real PHP only as a separate comparison tool.

Exit: wiring and fixture-baseline checks pass without weakening assertions or
reclassifying missing behavior as completed work.

### 2. Finish the native language execution core

First close the two strict first-wave gaps. Then port the remaining language
behavior from existing PHP-hosted owners into native Oracle execution:

- Comparisons, boolean short-circuiting, casts, floats, interpolation and the full expression/operator surface.
- If/else, switch/match, while/for/do, by-value and keyed foreach, break/continue and labels/goto.
- Nested arrays, append/delete, negative and coercing keys, spread/destructuring and reference ownership.
- Defaults, variadics, unpacking, named arguments, by-reference arguments/returns, static locals, globals and forward declarations.
- Namespace/import resolution, interfaces, traits, enums, attributes, readonly/protected members and private inheritance layouts.
- Static method syntax, parent/self/static resolution, late static binding, dynamic calls, autoload and remaining magic methods.
- Throw, builtin/custom throwable objects, multiple catches, finally/unwinding, warnings, handlers and accurate diagnostics.
- Generators, yield from/send/return, fibers and other resumable execution.
- Include-path resolution, missing include/require behavior, cycles, scope, once identity and dynamic loader paths.

Reuse the existing Zend/VM primitives and PHP-hosted behavior tests. Build one
coherent native Oracle execution contract rather than maintaining permanently
different semantics between a source parser, family executor and receiver API.
Oracle remains the interpreter; parse-once instruction execution is compatible
with that goal and does not require PASM or native binary emission.

Exit: both edge waves and the existing differential corpus pass in strict
native mode, not merely through the PHP-hosted bridge.

### 3. Connect and complete builtins, internal classes and extensions

Replace the 21-name source admission boundary with signature-aware access to
proven native handlers. Preserve named/default/by-reference arguments, returned
containers/resources and error state. Connect native internal-class constructor
and receiver carriers to source-level object execution. Audit methods separately.
Complete remaining standard, SPL/reflection, date/time, regex/encoding, crypto,
streams/filesystem, session, networking, database, graphics and extension APIs
for the declared build. Native libraries may provide core algorithms, but PHP
argument, return, state and error semantics still need adapters and tests.

The imported runtime inventory is not an inventory of every php-src extension
under every build configuration. Maintain an explicit php-src/module matrix.
Disabled or sandbox-blocked functions must be identified as policy/build limits,
not counted as universally implemented.

Exit: every required callable has an owner, signature/behavior coverage and
independent native proof; no placeholder or undocumented fallback remains.

### 4. Finish runtime services and resource ownership

Complete true global/request state, argv/argc, constants, superglobals, ini,
output buffering, sessions, resource lifetime and request cleanup. Replace
execution-long arena retention and eager array copies where appropriate with
correct lifetime/refcount/COW behavior, cycle handling and destructor execution.
Review hard-coded call/include depth, file-size and file-count limits against
the PHP contract and documented configurable resource limits.

For the full php-src goal, also port request/SAPI behavior. Existing hybrid,
worker and cache servers are PHP-hosted; native CLI success does not complete
web execution, database integration or HTTP request lifecycle behavior.

### 5. Turn test coverage into native acceptance evidence

Run the upstream PHP PHPT corpus for the chosen version/build, preserving skips
and unsupported extensions as visible gaps. Port the existing 1,144-case corpus
to strict native children. Add syntax/error, aliasing, mutation, lifecycle and
multi-request tests. Add sanitizer and differential-fuzzing runs.
CI must run the strict native source gate and semantic edge waves, not only
registry, route, smoke and PHP-hosted-family checks. Declare required native
dependencies explicitly; absent PCRE2/crypto/encoding/etc. capabilities must not
silently pass a release claiming those families.

### 6. Prove the acceleration goal

Benchmark the actual source interpreter, separately from PHP-hosted Oracle
families and isolated native builtin kernels. Measure cold start, repeated
execution, full applications, memory and representative array/object workloads.
The current native source interpreter reparses call bodies, eagerly copies
arrays, and uses array lookups with linear scans in `jinx_zend_engine.c`.
These are concrete optimization targets after correctness is established.
Do not infer arbitrary-code speedup from a small math-kernel benchmark, and do
not promise that every possible PHP program will be faster.

### 7. Remove production PHP dependence and ship

Only after native coverage is sufficient, remove the production PHP-hosted
runner path. Install and run the distribution on a machine without PHP,
including real applications with Composer/autoload and include/require trees.
PHP may remain a development baseline/build tool if explicitly documented;
it must not be required by the delivered application's execution path.
Keep test-driver hosting distinct from user code: the current `scripts/`
substring heuristic is not a sound final production routing boundary.

Update README, RC notes, family ledgers, Oracle SM descriptions and benchmarks
to distinguish registered, PHP-hosted, bounded-native and fully proven-native.
For example, RC notes' unqualified claim that the native CLI does not shell out
to PHP is incompatible with its current PHP-input routes. Historical fallback
tables and zero-placeholder benchmark examples are not current coverage proof.
Package reproducible builds, explicit dependency/capability manifests and clean
release contents; historical ZIP checkpoints are not the production release.
PASM stays optional and is not an acceptance gate for this work.

## The end condition

`./jinx application.php` executes the declared PHP/Zend surface through native
Oracle, with compatible outputs, errors, state and resource behavior, without
starting PHP or requiring a PHP runtime. Required language/module coverage and
real-application tests pass in that environment. Representative source workloads
have measured acceleration. Documentation and packaging describe exactly that
verified build, with no hidden bridge or inflated inventory-based completion.

## Commands

Run in the repository root. Failed suites still generate their fixtures.

```bash
git pull origin master
./scripts/build-native-jinx.sh
./jinx scripts/test-native-oracle-source.php
./jinx scripts/test-native-oracle-wiring.php
./jinx scripts/test-php-jinx-high-value-edge-fixtures.php
./jinx scripts/test-php-jinx-high-value-edge-fixtures-two.php
./jinx scripts/audit-native-source-parity.php
./scripts/test-full-stack.sh
```

Currently wiring, wave two, strict native parity and consequently full-stack
verification are not green. Run each command independently to collect all gaps;
do not hide failure by appending `|| true` to a release gate.
# Baseline repair follow-up (2026-10-03)

The reviewed `posix_mknod` ledger now records its existing extended native
handler: 975 named routes, 663 extended routes, and 2552 intentional faults.
`scripts/test-native-posix-mknod.php` verifies native FIFO creation and
existing-path rejection against PHP for arities 2, 3, and 4. This is bounded
coverage, not proof of privileged device creation or complete error parity.

The second-wave nested-destructuring fixture now uses explicit keys at its
outer level, avoiding PHP's prohibition on mixed keyed/unkeyed destructuring.
Its PHP baseline succeeds with `[10,20,30,"jinx"]`. Second-wave JINX execution
still fails all 20 fixtures. These repairs do not add source-language support.
