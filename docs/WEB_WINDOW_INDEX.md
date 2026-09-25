# JINX browser window index

The browser window index is the safe resident layer for page defaults and arrangements.

It is meant to behave like a large mutable per-user browser database of page shapes that JINX can feed into a browser window repeatedly:

```text
resident JINX window index
  -> page defaults
  -> page arrangements
  -> versioned index updates
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

## Runtime files

```text
runtime/WebWindowIndex.php
public/jinx-window-index.js
```

`runtime/WebWindowIndex.php` owns the server-side resident index.

`public/jinx-window-index.js` is the original browser-side runtime. It registers a large user-side index in the browser and applies live update frames into the DOM.

## Browser boot

Load the browser runtime first:

```html
<script src="/jinx-window-index.js"></script>
```

Then register the current JINX index snapshot:

```php
$index = WebWindowIndex::withStandardDefaults();
echo '<script>' . $index->toBrowserRegistrationScript() . '</script>';
```

That fills:

```text
window.__JINX_WINDOW_INDEX__.index_version
window.__JINX_WINDOW_INDEX__.fingerprint
window.__JINX_WINDOW_INDEX__.defaults
window.__JINX_WINDOW_INDEX__.frames
window.__JINX_WINDOW_INDEX__.windows
```

The browser runtime also exposes:

```text
window.JINXWindowIndex.registerIndex(index)
window.JINXWindowIndex.liveUpdate(frame)
window.JINXWindowIndex.applyFrame(frame)
window.JINXWindowIndex.mount(windowId, pageKey, arrangement)
window.JINXWindowIndex.snapshot()
```

## Feeding live DOM updates

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

Browser side, when a JSON frame is received over any live channel:

```js
window.JINXWindowIndex.liveUpdate(frame);
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

JINX can emit direct selectors or window/zone patches. The runtime uses `querySelectorAll`, so one frame can update any number of matching DOM targets.

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

The runtime fires:

```text
jinx-window-index-registered
jinx-window-frame
jinx-window-patched
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
- the browser index registration script is emitted
- the browser runtime exposes registerIndex/liveUpdate/applyFrame/mount
- live DOM update markers are present
- one page's arrangement does not leak into the next page
- forbidden request-state fields are not resident in the frame
```
