# Native Oracle callable wiring audit

PHP/Zend behavior remains the compatibility contract. Oracle is the execution
and mirroring layer. The generated callable inventory is not a claim that the
native runtime implements every PHP function or method.

`spec/native-oracle-wiring.json` records a reviewed route for every registered
callable. Its `counts` object reports the inventory and route totals. Route
values have deliberately narrow meanings:

| Route | Meaning |
| --- | --- |
| `asm` | A named outer dispatcher branch reaches the scalar Oracle runtime. |
| `zend-container` | The generated dispatcher routes the name to a corresponding carried-container implementation. |
| `asm+zend-container` | The dispatcher selects between those paths according to arguments. |
| `extended` | The wrapper falls through to a named implementation in the linked extended C backend. Optional extension backends can still be feature-gated at build time. |
| `context` | The wrapper reaches a named backend that requires the live Oracle/Zend call context, typically for references or caller-frame state. |
| combined routes | A `+`-joined route means more than one native layer recognizes the callable. |
| `intentional-native-fault` | No named native implementation exists in any audited backend. The generated wrapper must reach the explicit terminal fault, and checked dispatch must report failure. |

A named route may support only a bounded set of PHP values, overloads, flags,
callbacks, or argument forms. The ledger records wiring, not full semantic
parity. Registered methods currently follow the native fault path. PHP worker
wrappers and PHP Oracle executors are separate execution surfaces: a callable
can use original PHP there while intentionally failing in native execution.
The native builtin dispatcher does not silently invoke an external PHP process.
The `./jinx` CLI still delegates its established PHP frontend commands, such as
`web-plan` and `web-compile`, to `bin/jinx`.

Run the audit after building:

```sh
bash scripts/build-native-jinx.sh
php scripts/test-native-oracle-wiring.php
```

The audit is also registered in `scripts/test-bin-jinx.php`, so the full fresh
checkout parity script runs it. It checks:

- The reflection manifest, PHP worker metadata and compact name inventory,
  native name list, ASM index and wrappers, and generated dispatch table have
  exactly the same case-insensitive names, without duplicate entries.
- Every wrapper targets the correct builtin or method and forwards each
  declared parameter in order, with the correct reference and variadic form.
  Every dispatch entry has the manifest's required, total, and variadic arity.
- Every container route has an implementation and every container
  implementation has a route. Scalar inner name cases must remain reachable
  through their outer dispatch guards. Extended and context backends are also
  inventoried by their named dispatch conditions, and their delegation chain is
  checked so those implementations cannot become silently unreachable.
- Both PHP direct-dispatch methods agree with their name list and invoke the
  matching PHP function. Native manual classifications and the CLI first-100
  list cannot introduce unregistered names. A manual entry claiming native
  behavior cannot refer to a callable classified as an intentional native fault.
- Every registered wrapper resolves in a compiled C harness. Every callable
  with no audited ASM, container, extended, or context route reaches the
  explicit native terminal fault with its declared arguments supplied, and
  checked dispatch reports failure. Extended/context semantic success remains
  covered by their family parity tests and the full native build.
- Too few arguments are rejected for every required signature, and too many
  for every fixed signature. A real variadic call retains its tail, reference
  variadics retain caller slots, oversized frames retain their fault, and
  unknown names cannot succeed through a family catch-all.

The ledger is pinned independently of the runtime scanner. Removing a branch
therefore fails the audit instead of quietly changing that function's status
to unsupported. After an intentional implementation change, create a candidate:

```sh
php scripts/test-native-oracle-wiring.php --write-ledger
```

Review the ledger diff together with the implementation and its PHP-vs-Jinx
differential tests. Do not accept a downgrade merely to make the audit pass.
Run the default audit again after review. This audit complements the native
math, scalar, string, pure, array, zlib, and error-parity suites; it does not
replace them or establish PHP parity for untested semantics.
