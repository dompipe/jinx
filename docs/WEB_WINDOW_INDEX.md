# JINX browser window index

The browser window index is the safe resident layer for page defaults and arrangements.

It supports three browser output modes:

```text
1. JINX-emitted stream runtime
   - JINX/PHP emits the tiny runtime inline
   - browser opens a JINX server stream with EventSource
   - server pushes frames without full-page refresh
   - no external JS file and no app-authored JS

2. JINX-emitted direct runtime
   - JINX/PHP emits the tiny runtime inline
   - JINX/PHP emits direct live frames as script blocks
   - no external JS file is needed
   - supports in-place live DOM mutation

3. No-JS browser registrar
   - JINX/PHP emits plain HTML/templates/data attributes
   - no script tag, no window runtime, no app JS
   - supports browser-native render/reload/swap flows
```

## The hard browser boundary

A current browser cannot arbitrarily mutate existing DOM nodes without some browser-side execution. That execution is normally JavaScript.

JINX now cuts this three ways:

```text
For no-refresh server-pushed mutation:
  JINX emits a specialized browser runtime once.
  That runtime opens a JINX EventSource stream.
  The server pushes JINX frames; the browser applies them.

For direct one-off live mutation:
  JINX emits the runtime itself, inline, from the JINX/PHP file.
  The app author does not write or include JS.

For no-JS operation:
  JINX emits real HTML/DOM as the registrar.
  Updates happen by server-rendered replacement, navigation, iframe/fragment swap, or meta refresh.
```

So the stream runtime competes with app-level JavaScript by moving the application program to the JINX server and leaving only a generic JINX frame registrar in the browser.

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

`runtime/WebWindowIndex.php` owns the server-side resident index and emits all browser modes.

The old optional file remains available:

```text
public/jinx-window-index.js
```

but it is not required when using JINX-emitted stream runtime, inline runtime, or no-JS registrar output.

## No-refresh JINX stream runtime mode

The page boots once:

```php
$index = WebWindowIndex::withStandardDefaults();
echo '<script>' . $index->toBrowserStreamBootScript('/jinx/window-stream', 'main-stream') . '</script>';
```

Then the server stream emits frames:

```php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');

echo WebWindowIndex::toServerSentEventFrame($frame);
flush();
```

The browser runtime listens for:

```text
jinx-window-frame
jinx-window-index
```

and applies frame patches in-place. The whole page does not refresh.

## JINX-emitted direct browser runtime mode

No separate `<script src>` is required.

```php
$index = WebWindowIndex::withStandardDefaults();
echo '<script>' . $index->toBrowserBootScript() . '</script>';
```

Live frames can be emitted from JINX:

```php
$frame = $index->feedWindow($state, 'main-window', 'feed.window', [
    'title' => 'Live Feed',
    'components' => [
        'detail' => ['text' => 'Selected item'],
    ],
]);

echo '<script>' . WebWindowIndex::toBrowserScript($frame) . '</script>';
```

For repeated updates after boot:

```php
echo '<script>' . WebWindowIndex::toBrowserScript($frame, includeRuntime: false) . '</script>';
```

The browser receives:

```text
window.__JINX_WINDOW_INDEX__.index_version
window.__JINX_WINDOW_INDEX__.fingerprint
window.__JINX_WINDOW_INDEX__.defaults
window.__JINX_WINDOW_INDEX__.frames
window.__JINX_WINDOW_INDEX__.windows
window.__JINX_WINDOW_INDEX__.streams
```

## No-JS browser registrar mode

This mode emits only HTML.

```php
$index = WebWindowIndex::withStandardDefaults();
$frame = $index->feedWindow($state, 'main-window', 'feed.window');

echo $index->toNoJsRegistrarHtml();
echo WebWindowIndex::toNoJsFrameHtml($frame);
```

Or emit a full document:

```php
echo $index->toNoJsDocumentHtml($frame, refreshUrl: '/feed.window', refreshSeconds: 1);
```

The output contains:

```text
data-jinx-no-js-registrar
data-jinx-index-json
data-jinx-page-default
data-jinx-no-js-frame
data-jinx-frame-json
data-jinx-window
data-jinx-zone
```

The test enforces that the no-JS output does not contain:

```text
<script
window.
JINXWindowIndex
```

## DOM target convention

The browser/runtime applier and no-JS frame output use zone targets like:

```html
<div data-jinx-window="main-window" data-jinx-zone="title"></div>
<div data-jinx-window="main-window" data-jinx-zone="main"></div>
<div data-jinx-window="main-window" data-jinx-zone="status"></div>
```

JINX can emit direct selectors or window/zone frames.

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
- the JINX-emitted browser runtime is available
- the JINX stream runtime emits EventSource/connectStream boot code
- server-sent JINX frames serialize with event/data framing
- the no-JS registrar emits DOM/templates without scripts
- no-JS document output can include browser-native refresh
- one page's arrangement does not leak into the next page
- forbidden request-state fields are not resident in the frame
```
