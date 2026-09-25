# Web resident frame safety

Best production shape:

```text
closed resident frame = compiled route plan/template only
per-request context  = fresh isolated envelope data
```

The closed resident frame may keep:

- compiled route plan
- fast-template shape
- static response fragments
- route metadata

It must not keep:

- request body
- headers
- cookies
- session data
- auth state
- user-local variables
- response buffer
- previous request errors

`runtime/WebBackPageBridge.php` enforces this shape by copying every request into a fresh isolated request context before route execution. The retained bridge state is limited to the compiled plan/template.

The resident state can be checked with:

```php
$before = $bridge->residentStateFingerprint();
$bridge->handleEnvelopeJson($requestJson);
$after = $bridge->residentStateFingerprint();
```

The fingerprints must match across requests. That proves the closed resident frame did not absorb per-user request data.

Verification:

```bash
./jinx scripts/test-web-back-page-bridge.php
./jinx scripts/test-jinx-native-suite.php
```

Expected pass marker:

```text
PASS: WebBackPageBridge handles request/response envelopes with isolated per-request state
```

For web serving, keep the hot object resident and feed it new envelopes:

```text
resident worker process
  closed WebBackPageBridge compiled once
  request 1 -> fresh isolated context -> response
  request 2 -> fresh isolated context -> response
  request N -> fresh isolated context -> response
```

This is the safe fast path: code/plan stays hot, user data stays per-request.
