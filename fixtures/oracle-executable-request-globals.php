<?php

declare(strict_types=1);

$method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$script = $_SERVER['SCRIPT_FILENAME'] ?? 'missing.php';
$name = $_GET['name'] ?? 'missing';
$posted = $_POST['token'] ?? 'none';
$both = $_REQUEST['name'] ?? 'missing';
$hasQuery = isset($_GET['name']);
$missingEmpty = empty($_POST['missing']);
$count = count($_GET) + count($_POST);

echo $method . ' ' . $uri . ' ' . $name . ' ' . $posted . ' ' . (string) $count;

return $script . '|' . $both . '|' . $hasQuery . '|' . $missingEmpty;
