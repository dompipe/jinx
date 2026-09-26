# Native Window C Emission

This document explains the JX native-window C emission workflow:

```bash
jx -o out.c in.php
```

The workflow starts with PHP input that declares a native page through `jx_*` declarations. JX emits a standalone C source file, and GCC/MinGW-w64 compiles that C file into a native Win32 window executable.

The generated executable is a native Win32/GDI window program. It does **not** require WebView. It does **not** open a browser. It does **not** depend on an installed browser runtime.

---

## 1. Overview

Native-window C emission is the path for turning a small PHP page declaration into a compiled Windows GUI program.

The pipeline is:

```text
PHP input
  -> JX native C emission
    -> generated C file
      -> GCC / MinGW-w64
        -> native Win32 .exe
```

Typical command:

```bash
jx -o out.c in.php
```

The input file can contain `jx_*` declarations such as:

```php
<?php
jx_page_title('JX Native Window');
jx_page_badge('native');
jx_page_body('<h1>Hello from JX</h1><p>This page is compiled into C.</p>');
jx_page_json('{"status":"ok","source":"compiled"}');
jx_local_api(true);
```

JX reads those declarations, emits C, embeds supported page data/assets, and prints the follow-up GCC command needed to compile the generated C file.

The output executable owns the native window. It renders through Win32/GDI and may expose a small localhost API for live local inspection or updates.

---

## 2. Requirements

Required tools:

- JX built from `src/native/jx.c`
- GCC or MinGW-w64
- On Windows/MSYS2: `C:\msys64\ucrt64\bin\gcc.exe`
- Required Windows libraries:
  - `ws2_32`
  - `gdi32`
  - `user32`

On Windows, the recommended GCC is MSYS2 UCRT64:

```text
C:\msys64\ucrt64\bin\gcc.exe
```

On Linux/WSL, use the MinGW-w64 cross-compiler:

```bash
x86_64-w64-mingw32-gcc
```

---

## 3. Basic Workflow

From the repo root:

```bash
jx -o out.c in.php
```

This command emits `out.c` from `in.php`.

After emission, JX prints the follow-up GCC command. Use that printed command as the source of truth for the next compile step, especially when the emitted file contains embedded local API support or bundled assets.

The general shape is:

```bash
gcc -O2 -Wall -Wextra -mwindows -I . -o out.exe out.c -lws2_32 -lgdi32 -luser32
```

Result:

```text
out.exe
```

Running `out.exe` starts the native Win32 window.

---

## 4. Compile Command

Correct GCC command:

```bash
gcc -O2 -Wall -Wextra -mwindows -I . -o out.exe out.c -lws2_32 -lgdi32 -luser32
```

Important ordering rule:

```text
source/object files first, libraries after
```

That means `out.c` must appear before the `-l...` flags:

```bash
# Correct
gcc -O2 -Wall -Wextra -mwindows -I . -o out.exe out.c -lws2_32 -lgdi32 -luser32
```

Do not put `-lws2_32` before `out.c`:

```bash
# Wrong on many GCC/MinGW linkers
gcc -O2 -Wall -Wextra -mwindows -I . -lws2_32 -lgdi32 -luser32 -o out.exe out.c
```

`ws2_32` is especially order-sensitive because the generated native executable uses Winsock symbols when the localhost API is enabled.

---

## 5. PowerShell Example

Windows PowerShell using MSYS2 UCRT64 GCC:

```powershell
# From the repository root
$Gcc = "C:\msys64\ucrt64\bin\gcc.exe"

# Optional: build jx if your tree keeps the native source at src/native/jx.c
& $Gcc -O2 -Wall -Wextra -I . -o jx.exe src/native/jx.c

# Emit C from a PHP native-window declaration file
.\jx.exe -o out.c examples\native_window_input.php

# Compile the generated C into a native Win32 GUI executable
& $Gcc -O2 -Wall -Wextra -mwindows -I . -o out.exe out.c -lws2_32 -lgdi32 -luser32

# Run it
.\out.exe
```

If `jx.exe` already exists, skip the build step:

```powershell
.\jx.exe -o out.c examples\native_window_input.php
& "C:\msys64\ucrt64\bin\gcc.exe" -O2 -Wall -Wextra -mwindows -I . -o out.exe out.c -lws2_32 -lgdi32 -luser32
.\out.exe
```

To query the compiled localhost JSON endpoint from PowerShell:

```powershell
Invoke-RestMethod http://127.0.0.1:8787/json
```

If the generated executable prints or documents a different port, use that port instead.

---

## 6. Linux/WSL Cross-Compile Example

Install MinGW-w64 in WSL/Linux:

```bash
sudo apt-get update
sudo apt-get install -y mingw-w64
```

Build `jx` for the host environment if needed:

```bash
gcc -O2 -Wall -Wextra -I . -o jx src/native/jx.c
```

Emit C:

```bash
./jx -o out.c examples/native_window_input.php
```

Cross-compile a Windows `.exe`:

```bash
x86_64-w64-mingw32-gcc -O2 -Wall -Wextra -mwindows -I . -o out.exe out.c -lws2_32 -lgdi32 -luser32
```

Run on Windows:

```powershell
.\out.exe
```

Or, if Wine is installed and the generated program works under your Wine setup:

```bash
wine out.exe
```

Query the local API while the executable is running:

```bash
curl http://127.0.0.1:8787/json
```

If the generated executable reports a different localhost port, use that port.

---

## 7. `jx_*` Page Declarations

The native-window emitter recognizes page declarations in the PHP input. These declarations describe the page/window payload that gets compiled into C.

Supported declarations:

| Declaration | Purpose |
| --- | --- |
| `jx_page_title` | Sets the native window/page title. |
| `jx_page_badge` | Sets a short badge/label rendered with the page. |
| `jx_page_body` | Sets the main page body content. HTML-like text may be accepted by the emitter and rendered through the native renderer. |
| `jx_modal_title` | Sets the initial modal title. |
| `jx_modal_body` | Sets the initial modal body content. |
| `jx_iframe_title` | Sets the iframe/island panel title. |
| `jx_iframe_html` | Sets iframe/island HTML content for the compiled attachment. |
| `jx_page_json` | Sets the compiled attachment JSON returned by `/json`. |
| `jx_local_api` | Enables or disables the localhost API in the generated executable. |

Example:

```php
<?php
jx_page_title('Native JX Window');
jx_page_badge('compiled');
jx_page_body('<h1>Native window</h1><p>No WebView. No browser.</p>');

jx_modal_title('Compiled modal');
jx_modal_body('This modal text was declared in PHP and compiled into C.');

jx_iframe_title('Compiled island');
jx_iframe_html('<section><strong>Island content</strong></section>');

jx_page_json('{"ok":true,"kind":"native-window"}');
jx_local_api(true);
```

Notes:

- Declarations should use static values when possible.
- Static quoted strings are the best-supported input shape for native emission and asset bundling.
- Dynamic PHP runtime behavior is separate from this emission path.

---

## 8. Local API

When enabled with `jx_local_api(true)`, the native executable starts a localhost API. The API is intended for local inspection and simple live update/testing of the native window payload.

Supported endpoints:

| Endpoint | Description |
| --- | --- |
| `/json` | Returns the compiled attachment JSON from `jx_page_json`. |
| `/update?title=...&badge=...&body=...` | Updates the visible title, badge, and body text. |
| `/modal?title=...&body=...` | Updates the modal title and modal body. |
| `/iframe?title=...&html=...` | Updates the iframe/island title and HTML payload. |

Examples:

```bash
curl http://127.0.0.1:8787/json
```

```bash
curl "http://127.0.0.1:8787/update?title=Updated&badge=live&body=Hello%20from%20localhost"
```

```bash
curl "http://127.0.0.1:8787/modal?title=Modal%20Title&body=Modal%20Body"
```

```bash
curl "http://127.0.0.1:8787/iframe?title=Island&html=%3Ch1%3EIsland%3C%2Fh1%3E"
```

`/json` returns the compiled attachment JSON exactly as embedded by `jx_page_json`, subject to any escaping/normalization performed by the emitter.

Use URL encoding for query-string values.

---

## 9. Example Input

Main example:

```text
examples/native_window_input.php
```

A short annotated version:

```php
<?php

// Window title shown by the native Win32 program.
jx_page_title('JX Native Window Demo');

// Small label/badge rendered near the page identity.
jx_page_badge('native-c');

// Main body content compiled into the generated C file.
jx_page_body('
  <h1>JX Native Window</h1>
  <p>This PHP declaration emits C and compiles into a native Win32 executable.</p>
');

// Optional modal payload.
jx_modal_title('Native modal');
jx_modal_body('This modal content was embedded at compile time.');

// Optional iframe/island payload.
jx_iframe_title('Embedded island');
jx_iframe_html('<article><p>Island HTML compiled into the executable.</p></article>');

// JSON attachment returned by the local /json endpoint.
jx_page_json('{"demo":"native-window","compiled":true}');

// Enable localhost inspection/update endpoints.
jx_local_api(true);
```

Generate C from the example:

```bash
jx -o out.c examples/native_window_input.php
```

Compile:

```bash
gcc -O2 -Wall -Wextra -mwindows -I . -o out.exe out.c -lws2_32 -lgdi32 -luser32
```

Run:

```powershell
.\out.exe
```

---

## 10. CSS

If a sibling `style.css` exists next to the PHP input file, JX infers it and embeds it into the generated C output.

Example layout:

```text
examples/native_page/
  page.php
  style.css
```

Command:

```bash
jx -o out.c examples/native_page/page.php
```

In this layout, `examples/native_page/style.css` is detected as the sibling stylesheet and bundled into `out.c`.

The compiled executable then carries the stylesheet payload without needing to read `style.css` at runtime.

---

## 11. Includes, Requires, CSS Images

Native C emission supports bridge-mode bundling for static quoted relative file references.

Supported PHP statements:

```php
include 'partial.php';
include_once 'partial.php';
require 'partial.php';
require_once 'partial.php';
```

Best-supported shape:

```php
<?php
require_once 'header.php';
include 'content.php';

jx_page_title('Single EXE Page');
jx_page_body($body);
```

The bundler expects static quoted relative paths. Avoid dynamic include paths for native single-exe bundling:

```php
// Not recommended for native bundling
include $path;
include dirname(__FILE__) . '/partial.php';
```

Reference example:

```text
examples/single_exe_page/page.php
```

Expected layout:

```text
examples/single_exe_page/
  page.php
  style.css
  header.php
  content.php
  assets/
    logo.png
    hero.jpg
```

CSS asset bundling supports static relative assets referenced from CSS `url(...)` values and quoted image references.

Examples:

```css
.hero {
  background-image: url("assets/hero.jpg");
}

.logo {
  background-image: url('assets/logo.png');
}

.badge {
  background-image: url(assets/badge.png);
}
```

Quoted image asset references in the supported bridge path can also be bundled when they are static and relative:

```html
<img src="assets/logo.png" alt="Logo">
<img src='assets/hero.jpg' alt='Hero'>
```

The goal of bridge-mode bundling is a single generated C file that carries the page declaration, inferred CSS, static included PHP fragments, and static local assets needed by the native executable.

---

## 12. Troubleshooting

### Linker error: Winsock symbols

Common error:

```text
undefined reference to __imp_socket
undefined reference to __imp_WSAStartup
```

Meaning:

```text
-lws2_32 is missing, or it appears before out.c / object files in the GCC command.
```

Fix:

```bash
gcc -O2 -Wall -Wextra -mwindows -I . -o out.exe out.c -lws2_32 -lgdi32 -luser32
```

Do not use:

```bash
gcc -O2 -Wall -Wextra -mwindows -I . -lws2_32 -lgdi32 -luser32 -o out.exe out.c
```

### `gcc` not found on Windows

Use the full MSYS2 UCRT64 GCC path:

```powershell
& "C:\msys64\ucrt64\bin\gcc.exe" --version
```

Then compile:

```powershell
& "C:\msys64\ucrt64\bin\gcc.exe" -O2 -Wall -Wextra -mwindows -I . -o out.exe out.c -lws2_32 -lgdi32 -luser32
```

### Console window appears

Make sure `-mwindows` is present:

```bash
gcc -O2 -Wall -Wextra -mwindows -I . -o out.exe out.c -lws2_32 -lgdi32 -luser32
```

### `/json` does not respond

Check that the input enabled the local API:

```php
jx_local_api(true);
```

Then run the executable and query the documented port:

```bash
curl http://127.0.0.1:8787/json
```

If the generated executable prints a different port, use that port.

---

## 13. What This Is Not

This feature is not a WebView wrapper.

It is not opening a browser.

It is not a browser automation layer.

It is not the same as PHP runtime bridging.

It is a native Win32/GDI window renderer produced from generated C. The page declaration is extracted from `jx_*` declarations, compiled into a C source file, and linked into a native Windows executable.

PHP runtime bridging is separate from native `jx_*` page emission:

- Native `jx_*` page emission produces a compiled native window payload.
- PHP runtime bridging handles broader PHP/Zend compatibility and execution behavior.

Keep those paths distinct when testing, debugging, and documenting behavior.

---

## 14. Verification

Use this checklist from the repository root.

### 1. Build `jx`

Linux/WSL host build:

```bash
gcc -O2 -Wall -Wextra -I . -o jx src/native/jx.c
```

Windows PowerShell build with MSYS2 UCRT64 GCC:

```powershell
& "C:\msys64\ucrt64\bin\gcc.exe" -O2 -Wall -Wextra -I . -o jx.exe src/native/jx.c
```

### 2. Emit C

Linux/WSL:

```bash
./jx -o out.c examples/native_window_input.php
```

Windows PowerShell:

```powershell
.\jx.exe -o out.c examples\native_window_input.php
```

### 3. Compile EXE

Windows PowerShell:

```powershell
& "C:\msys64\ucrt64\bin\gcc.exe" -O2 -Wall -Wextra -mwindows -I . -o out.exe out.c -lws2_32 -lgdi32 -luser32
```

Linux/WSL cross-compile:

```bash
x86_64-w64-mingw32-gcc -O2 -Wall -Wextra -mwindows -I . -o out.exe out.c -lws2_32 -lgdi32 -luser32
```

### 4. Run the EXE

Windows PowerShell:

```powershell
.\out.exe
```

### 5. Query `/json`

PowerShell:

```powershell
Invoke-RestMethod http://127.0.0.1:8787/json
```

curl:

```bash
curl http://127.0.0.1:8787/json
```

Expected result:

```text
The response contains the compiled attachment JSON declared by jx_page_json(...).
```

---

## Minimal End-to-End Command Set

Windows PowerShell:

```powershell
$Gcc = "C:\msys64\ucrt64\bin\gcc.exe"
& $Gcc -O2 -Wall -Wextra -I . -o jx.exe src/native/jx.c
.\jx.exe -o out.c examples\native_window_input.php
& $Gcc -O2 -Wall -Wextra -mwindows -I . -o out.exe out.c -lws2_32 -lgdi32 -luser32
.\out.exe
Invoke-RestMethod http://127.0.0.1:8787/json
```

Linux/WSL cross-compile:

```bash
gcc -O2 -Wall -Wextra -I . -o jx src/native/jx.c
./jx -o out.c examples/native_window_input.php
x86_64-w64-mingw32-gcc -O2 -Wall -Wextra -mwindows -I . -o out.exe out.c -lws2_32 -lgdi32 -luser32
```
