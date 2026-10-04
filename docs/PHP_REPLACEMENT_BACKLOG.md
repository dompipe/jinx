# PHP replacement work

Replacement means native application execution, with no PHP executable or
script bridge available to the target. PHP remains the independent reference
used by differential tests.

The original two official waves still contain 40 fixtures across 20 areas.
They are regression coverage, not a complete language or extension inventory.

## Expanded audit

Run php scripts/audit-native-php-replacement.php for the full bounded audit.
It generates fixtures and build/audits/php-replacement.json, including each
reference result, native result, timeout status, and family counts. Any failed
case makes the full audit exit nonzero. Each child has a five-second time limit
and a one-MiB output budget.

Run the same command with --gate to require all 30 proven cases in
spec/php-replacement-proof-ledger.json while still printing the open failures.
The focused edge suite also runs this gate.

The first 27 new probes passed 0/27 before this implementation. With additional
regressions and weak-typing probes the expanded set now passes 30/41:

| Family | Passing | Tested |
| --- | --- | --- |
| Operators | 11 | 11 |
| Control flow | 14 | 14 |
| Builtins | 3 | 4 |
| Objects | 0 | 4 |
| Generators | 0 | 2 |
| Namespaces | 1 | 2 |
| Errors | 1 | 2 |
| Weak typing | 0 | 2 |

New execution includes scalar comparisons, short-circuit logical operators,
integer bitwise/shift operators, division and exponentiation, braced if/elseif/
else, while/for/do loops, single-level break/continue, switch fallthrough,
float storage in native arrays, global constant fallback, and source admission
for native str_replace, array_keys, and json_decode handlers.

The array-filtering probe exercises an application-style combination of
iteration, branching, comparison, arithmetic, array append, and JSON output.
It is a small workload, not evidence that an external application works.

## Failing fixtures

Prioritize object contracts, the largest remaining family in this audit:

- Interfaces and implements validation.
- Traits and trait member composition.
- Destructor lifecycle execution.
- Instanceof.
- By-reference sorting and argument mutation.
- Generator suspension inside loops and finally.
- Grouped namespace imports.
- Exception construction and throwing an existing exception variable.
- Function execution without strict_types and weak scalar coercion.

## Further coverage required

The audit must expand alongside implementation. Unbraced statements, multiple
break levels, complete for clauses, floating literals and mixed arithmetic,
recursive array comparisons, complete expression precedence, reference
semantics, builtin signatures, extension behavior, and real application
workloads remain unproven here. Native wrapper registration counts are not
passing callable counts. Full PHP replacement has no justified completion
percentage from this bounded set.
