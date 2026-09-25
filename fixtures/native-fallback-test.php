<?php

echo "native fallback ok: " . ($_SERVER['REQUEST_METHOD'] ?? 'missing');
