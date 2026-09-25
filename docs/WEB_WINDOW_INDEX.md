# JINX browser window index

The browser window index is the safe resident layer for page defaults and arrangements.

It behaves like a large mutable per-user browser database of page shapes that JINX can feed into a browser window repeatedly:

```text
resident JINX window index
  -> page defaults
  -> page arrangements
  -> versioned index updates
  -> JINX-emitted browser runtime
  -> browser index registration
  -> live browser frame patches
  -> DOM updates at will
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

`runtime/WebWindowIndex.php` owns the server-side resident index and emits the browser-side runtime inline.

No external browser file is required:

```text
no <script src="/jinx-window-index.js"></script>
```

The browser still executes generated code because DOM mutation requires browser execution, but the application code can stay in JINX/PHP.

## Browser boot from the JINX file

```php
$index = WebWindowIndex::withStandardDefaults();
echo '<script>' . $index->toBrowserBootScript() . '</script>';
```

Equivalent explicit call:

```php
echo '<script>' . $index->toBrowserRegistrationScript() . '</script>';
```

Both include the inline emitted runtime and then register the current index snapshot.

That fills:

```text
window.__JINX_WINDOW_INDEX__.index_version
window.__JINX_WINDOW_INDEX__.fingerprint
window.__JINX_WINDOW_INDEX__.defaults
window.__JINX_WINDOW_INDEX__.frames
window.__JINX_WINDOW_INDEX__.windows
```

The emitted runtime exposes:

```text
window.JINXWindowIndex.registerIndex(index)
window.JINXWindowIndex.liveUpdate(frame)
window.JINXWindowIndex.applyFrame(frame)
window.JINXWindowIndex.mount(windowId, pageKey, arrangement)
window.JINXWindowIndex.applyPatch(patch)
window.JINXWindowIndex.state()
```

## Feeding live DOM updates from JINX

Server side:

```php
$frame = $index->feedWindow($state, 'main-window', 'feed.window', [
    'title' => 'Live Feed',
    'components' => [
        'detail' => ['text' => 'Selected item'],
    ],
]);

echo '<script>' . WebWindowIndex::toBrowserScript($frame) . '</script>';
```

`toBrowserScript()` also includes the inline emitted runtime by default. For repeated frames after boot, emit only the frame call:

```php
echo '<script>' . WebWindowIndex::toBrowserScript($frame, includeRuntime: false) . '</script>';
```

The runtime applies patches with DOM targeting:

```text
replaceText
replaceHTML
appendHTML
setAttribute
removeAttribute
toggleClass
```

## DOM target convention

The browser applier uses zone targets like:

```html
<div data-jinx-window="main-window" data-jinx-zone="title"></div>
<div data-jinx-window="main-window" data-jinx-zone="main"></div>
<div data-jinx-window="main-window" data-jinx-zone="status"></div>
```

JINX emits selectors or window/zone patches. The runtime updates matching DOM targets from the emitted JINX frame.

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

## Browser events

The emitted runtime fires:

```text
jinx-window-index-registered
jinx-window-frame
jinx-window-live-update
```

That gives the browser a JS-style programming surface while keeping the resident JINX side deterministic and safe.

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
- the browser runtime is emitted from WebWindowIndex.php
- no external JS file or script src is required
- the emitted runtime exposes registerIndex/liveUpdate/applyFrame/mount/applyPatch
- live DOM update markers are present
- one page's arrangement does not leak into the next page
- forbidden request-state fields are not resident in the frame
```
