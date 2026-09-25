<?php

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['name'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing name']);
    return;
}

$name = $data['name'];
echo json_encode(['ok' => true, 'name' => $name]);
