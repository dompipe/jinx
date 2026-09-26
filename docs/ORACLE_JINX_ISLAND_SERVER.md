# Oracle JINX island server

This document records the Oracle family that turns the browser-island work into a JINX-native webserver plan.

## Family

| Family | PHP comparison test |
|---|---|
| `jinx-island-server` | `scripts/test-oracle-jinx-island-server-execution.php` |

Runtime owner:

```text
runtime/OracleJinxIslandServerExecutor.php
```

Family ledger:

```text
runtime/OracleJinxWebExecutionFamilies.php
```

## Oracle plan

The family owns this executable route/request plan:

```text
O_HTTP_ROUTE_TABLE
O_BACK_PAGE_API_BRIDGE
O_RESIDENT_ISLAND_STATE
O_WINDOW_INDEX_FRAME
O_IFRAME_ISLAND_RENDER
O_EVENTSOURCE_INVALIDATION
O_RESPONSE_ENVELOPE
```

It covers these JINX worker routes:

```text
GET  /
GET  /island
GET  /events/island-state
GET  /api/island-state
POST /api/island-state
GET  /__health
```

## Purpose

The family is the Oracle version of the high-speed JINX web worker shape:

```text
browser
  -> native JINX HTTP route table
    -> resident island state
    -> back-page/API bridge
    -> window index frame
    -> iframe island HTML
    -> EventSource island invalidation
```

The actual socket accept loop can use this plan without treating the PHP demo router as the source of truth.

## Safety contract

Resident state may keep:

```text
window index
compiled back-page bridge
island state revision
allowed page/detail/status properties
```

Per-request data must stay isolated:

```text
request body
headers
cookies
authorization/auth state
```

The parity test verifies that read-only requests do not mutate the resident fingerprint, state-changing API requests intentionally do mutate it, and request-local secrets are not retained in the resident state.

## Verification

```bash
./jinx scripts/test-oracle-jinx-island-server-execution.php
./jinx scripts/test-oracle-full-coverage-audit.php
./jinx scripts/test-jinx-native-suite.php
```
