# JINX browser window index

The browser window index is the safe resident layer for page defaults and arrangements.

It is meant to behave like a small database of page shapes that JINX can feed into a browser window repeatedly:

```text
resident JINX window index
  -> page defaults
  -> page arrangements
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

The page arrangement is resident. The request context stays fresh and isolated.

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

The emitted browser script writes to:

```text
window.__JINX_WINDOW_INDEX__
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
- resident defaults do not mutate between feeds
- one page's arrangement does not leak into the next page
- forbidden request-state fields are not resident in the frame
```
