# JINX island invalidation events

This is the event-driven version of the browser island demo.

It replaces this pattern:

```text
island refreshes every N seconds
```

with this pattern:

```text
back-page/API state changes
  -> state revision increments
  -> EventSource emits jinx-island-refresh
  -> parent reloads only matching iframe island URLs
```

## Run

```bash
./jinx scripts/serve-no-js-islands-demo.php
```

Then open:

```text
http://127.0.0.1:8099/
```

The server helper starts the PHP dev server with multiple workers by default:

```text
PHP_CLI_SERVER_WORKERS=4
```

That is important for the local demo because a long-held EventSource request can otherwise block another request on a single-worker development server.

## Change the island from another terminal

```bash
curl -X POST http://127.0.0.1:8099/api/island-state \
  -H 'Content-Type: application/json' \
  -d '{"detail":"Changed by API event","status":"Event pushed status"}'
```

The open page listens at:

```text
/events/island-state?after=<current-revision>
```

When the state revision is greater than `after`, the endpoint emits:

```text
event: jinx-island-refresh
data: {"kind":"JINX_ISLAND_INVALIDATION","revision":2,"window":"demo-window","zones":["detail","status"]}
```

The tiny JINX listener then reloads only the iframes matching those zones:

```text
iframe[data-jinx-no-js-island="1"][data-jinx-zone="detail"]
iframe[data-jinx-no-js-island="1"][data-jinx-zone="status"]
```

## Boundary

Pure no-JS islands still exist, but they can only refresh through browser-native navigation or refresh headers.

Event-driven island refresh needs a small browser listener because `EventSource` is browser-side execution. The app logic remains on the JINX/back-page/API side; the browser listener is only a generic invalidation registrar.
