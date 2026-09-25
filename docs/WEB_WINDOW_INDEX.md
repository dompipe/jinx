# JINX browser window index

The browser window index is the safe resident layer for page defaults and arrangements.

It is meant to behave like a small mutable database of page shapes that JINX can feed into a browser window repeatedly:

```text
resident JINX window index
  -> page defaults
  -> page arrangements
  -> versioned index updates
  -> browser frame patches
  -> tiny JS window applier
```

## Safety rule

Resident state may contain:

```text
page defaults
layout names
zone names
component defaults
browser patch instructions
index version
resident fingerprint
```

Resident state must not contain:

```text
request body
cookies
session
Authorization headers
auth/user identity
last user's browser input
```

The page arrangement/index is resident. The request context stays fresh and isolated.

## Runtime

```text
runtime/WebWindowIndex.php
```

Main API:

```php
$index = WebWindowIndex::withStandardDefaults();
$frame = $index->feedWindow($state, 'main-window', 'feed.window', [
    'title' => 'Live Feed',
    'components' => [
        'detail' => ['text' => 'Selected item'],
    ],
]);

$script = WebWindowIndex::toBrowserScript($frame);
```

## Updating the resident index

The index can update intentionally. This is separate from request/browser state:

```php
$index->patchPageDefault('feed.window', [
    'title' => 'Updated Resident Feed',
    'components' => [
        'detail' => ['text' => 'Resident detail default updated'],
        'status' => ['text' => 'updated-default'],
    ],
]);

$frame = $index->feedWindow($state, 'main-window', 'feed.window');
```

Each explicit index mutation increments:

```text
index_version
```

and changes:

```text
resident_index_fingerprint
```

A normal `feedWindow()` call does not mutate the resident index. It only creates a browser frame from the current index plus per-feed overrides.

## Browser surface

The emitted browser script writes to:

```text
window.__JINX_WINDOW_INDEX__
```

including:

```text
window.__JINX_WINDOW_INDEX__.index_version
window.__JINX_WINDOW_INDEX__.fingerprint
window.__JINX_WINDOW_INDEX__.frames[window_id]
window.__JINX_WINDOW_INDEX__.defaults[page_key]
```

and fires:

```text
jinx-window-frame
```

That gives the browser a JS-style programming surface while keeping the resident JINX side deterministic and safe.

## DOM target convention

The browser applier uses zone targets like:

```html
<div data-jinx-window="main-window" data-jinx-zone="title"></div>
<div data-jinx-window="main-window" data-jinx-zone="main"></div>
<div data-jinx-window="main-window" data-jinx-zone="status"></div>
```

JINX emits patches that replace the zone text. A later renderer can extend this from text replacement into keyed DOM nodes, attributes, lists, and component mounts.

## Verification

```bash
./jinx scripts/test-web-window-index.php
./jinx scripts/test-jinx-native-suite.php
```

The test proves:

```text
- defaults can feed a browser window repeatedly
- changing page arrangements updates the window frame
- normal feeds do not mutate the resident index
- explicit index patches increment index_version
- explicit index patches change resident_index_fingerprint
- updated defaults feed into later browser frames
- one page's arrangement does not leak into the next page
- forbidden request-state fields are not resident in the frame
```
