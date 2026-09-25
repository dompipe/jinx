#!/usr/bin/env bash
set -euo pipefail

php scripts/test-jinx-intended-architecture.php
php scripts/test-coalesced-oracle-compiler.php
php scripts/test-coalesced-oracle-runtime-inputs.php
php scripts/test-jinx-state-sweep.php
php scripts/test-jinx-run-cli.php
php scripts/test-oracle-pasm-chain.php
php scripts/test-oracle-pasm-replica.php
php scripts/test-php-source-to-jinx-to-pasm.php
php scripts/test-php-source-builtin-to-jinx-to-pasm.php

echo "PASS: main JINX/PASM/Coalesced Oracle suite is green"
