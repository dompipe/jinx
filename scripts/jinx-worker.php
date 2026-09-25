<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/WebWorkerServer.php';

$source = $argv[1] ?? null;
$hostPort = $argv[2] ?? null;

if ($source === null || $hostPort === null || !str_contains($hostPort, ':')) {
    fwrite(STDERR, "Usage: php scripts/jinx-worker.php <source.php> <host:port>\n");
    exit(1);
}

[$host, $port] = explode(':', $hostPort, 2);

jinx\web\WebWorkerServer::serveTcp($source, $host, (int) $port);
