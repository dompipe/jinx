<?php

$data = json_decode(file_get_contents('php://input'), true);
$name = $data['name'];
echo json_encode(['ok' => true, 'name' => $name]);
