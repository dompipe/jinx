<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/HybridJinxServer.php';

$docroot = $argv[1] ?? null;
$hostPort = $argv[2] ?? null;

if ($docroot === null || $hostPort === null || !str_contains($hostPort, ':')) {
    fwrite(STDERR, "Usage: php scripts/jinx-hybrid-server.php <docroot> <host:port>\n");
    exit(1);
}

[$host, $port] = explode(':', $hostPort, 2);

$server = new jinx\web\HybridJinxServer($docroot);
$server->serveTcp($host, (int) $port);
