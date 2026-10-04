# PHP to native Oracle instructions

The separated benchmark in E:/jx2/jinx uses PHP-hosted Oracle executors. On
this machine its 14 workloads reproduced 11.48x overall against repeated PHP
require with CLI OPcache off. With CLI OPcache on and timestamp validation
disabled for the immutable benchmark fixtures, PHP took 10.068 ms and the
Oracle executors took 2464.581 ms. The comparison changed substantially once
repeated loading/compilation was removed. The original 17.13x screenshot is
not a demonstrated hot-runtime speedup.

The first native compiled backend consumes the existing coalesced Oracle
integer instruction set. The backend is linked into the main ./jinx command.
It remains a bounded prototype, not the broader Oracle family executor registry.

Build and use:

    sh scripts/build-native-jinx.sh
    php scripts/compile-oracle-native.php input.php program.jxo
    ./jinx program.jxo x=20 y=7
    ./jinx oracle-run program.jxo x=3 y=9

Example source:

    <?php
    $sum = $x + $y;
    return $sum * $y;

Compile once and supply different x/y values on each run. Inputs are not
frozen into the artifact. Compilation uses PHP token validation and a structured
expression lowerer targeting coalesced instructions. Native execution needs no PHP, source file,
tokenizer, helper process, or script bridge. The .jxo artifact is a versioned instruction
stream, not embedded PHP or an assembly listing.

Supported: integer constants, assignment and copies, add/subtract/multiply
with PHP precedence and parentheses, unary signs, compound assignments,
and integer returns. Lazy ternary and shorthand ternary expressions choose a
branch at runtime using PHP integer truthiness, including nested expressions.
Immutable temporary slots preserve copies during reassignment.
Unsupported syntax, malformed
artifacts, undefined slots, missing/unknown inputs, and runtime integer
overflow reject. PHP float promotion on overflow, strings, arrays, statement branches,
loops, objects, and builtin calls are not implemented in this prototype.

Validation:

    php scripts/test-native-oracle-integer-vm.php
    php scripts/test-native-oracle-expressions.php
    php scripts/benchmark-native-oracle-integer.php
    php scripts/benchmark-native-oracle-integer.php build/oracle-integer/branch-benchmark.json --branch

The benchmark varies x every iteration and checks both final results and
checksums. Five serial samples, alternating engine order, compared one million
native executions with a loaded PHP closure, with CLI OPcache enabled. Median
PHP/native speedup was 7.46x on this small arithmetic workload. Both timed
loops exclude process startup and compilation; the PHP side warms its closure
before timing. This does not establish a 17x application speedup.

The integrated ./jinx run measured 8.41x median on the same arithmetic workload.
Expression coverage compares fifteen source families with six input sets each
against PHP and executes every compiled artifact with PHP absent from PATH.
Tests additionally prove that an unselected overflowing expression is skipped
and that selecting it rejects instead of silently returning an incorrect integer.

Branch artifacts use JXOR_INT_2, adding JZ, JUMP, and MOVE; JXOR_INT_1 artifacts
remain supported. Loading validates forward targets and intersects initialized
slots at branch joins, rejecting any read that is undefined on a possible path.
Validation occurs once before timed execution. Backward jumps are not admitted.
The first branch workload measured 3.59x median versus loaded PHP, while the
original arithmetic workload measured 7.60x in the same revision. These remain
small hot-execution benchmarks, not application performance claims.

Next: runtime strings/arrays and Oracle builtin-ID calls, statement branches and loops,
broader main CLI compilation support, and representative application benchmarks with compile/
load costs reported separately. Keep cached-PHP comparisons alongside any
uncached fixture comparisons.
