#!/usr/bin/env bash
set -euo pipefail

php scripts/test-web-program-compiler.php
php scripts/test-web-statement-coverage.php
php scripts/test-web-api-compiler.php
php scripts/test-web-api-compiler-validated.php
php scripts/test-web-api-native-vs-compiled.php

php scripts/test-web-cache-server.php
php scripts/test-bin-jinx-web-cache.php
php scripts/test-bin-jinx-cache-server-http.php
php scripts/test-bin-jinx-worker.php
php scripts/test-bin-jinx-worker-keepalive.php
php scripts/test-bin-jinx-hybrid-server.php
echo "PASS: JINX Web compiler main suite is green"
