<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/CoalescedOracleCompiler.php';

use jinx\oracle\CoalescedOracleCompiler;

/**
 * Oracle-backed real PHP workload.
 *
 * CLI mode:
 *   php fixtures/oracle-post-curl-dynamic.php
 *
 * Server mode:
 *   php -S 127.0.0.1:8099 -t fixtures
 *
 * Then this file receives POST JSON and computes the dynamic answer through
 * the coalesced Oracle compiler/runtime, not plain PHP arithmetic.
 */

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid JSON body');
    }

    return $decoded;
}

function require_string(array $data, string $key): string
{
    if (!isset($data[$key]) || !is_string($data[$key]) || trim($data[$key]) === '') {
        throw new RuntimeException("Missing or invalid string field: {$key}");
    }

    return trim($data[$key]);
}

function require_int(array $data, string $key): int
{
    if (!isset($data[$key]) || !is_numeric($data[$key])) {
        throw new RuntimeException("Missing or invalid integer field: {$key}");
    }

    return (int) $data[$key];
}

/**
 * Compile once, then execute many times.
 *
 * This is the Oracle/coalesced Oracle math program.
 *
 * Prices use cents to avoid floats:
 *
 *   base_cents = 300
 *   extra_images = images - included_images
 *   image_fee = extra_images * image_fee_cents
 *   daily_total = base_cents + image_fee
 *   campaign_total = daily_total * days
 *   radius_fee = radius_miles * radius_fee_cents
 *   total = campaign_total + radius_fee
 *
 * Score:
 *
 *   message_len = strlen(message)
 *   length_bonus = message_len * 1
 *   raw_score = starting_score + length_bonus
 *
 * This is intentionally arithmetic/string-length only because that is what
 * the current Oracle compiler build supports.
 */
function oracle_programs(): array
{
    static $programs = null;

    if ($programs !== null) {
        return $programs;
    }

    $priceJinx = <<<JINX
assign extra_images sub local images local included_images
assign image_fee mul local extra_images local image_fee_cents
assign daily_total add local base_cents local image_fee
assign campaign_total mul local daily_total local days
assign radius_fee mul local radius_miles local radius_fee_cents
return add local campaign_total local radius_fee
JINX;

    $scoreJinx = <<<JINX
assign message_len strlen local message
assign length_bonus mul local message_len local length_weight
return add local starting_score local length_bonus
JINX;

    /*
     * Current compiler supports return builtin strlen, but not assign strlen yet.
     * So score is split into:
     *   1. Oracle strlen program
     *   2. Oracle score arithmetic program
     */
    $messageLengthJinx = <<<JINX
return builtin strlen local message
JINX;

    $scoreMathJinx = <<<JINX
assign length_bonus mul local message_len local length_weight
return add local starting_score local length_bonus
JINX;

    $programs = [
        'price' => CoalescedOracleCompiler::compileJinx($priceJinx),
        'message_length' => CoalescedOracleCompiler::compileJinx($messageLengthJinx),
        'score_math' => CoalescedOracleCompiler::compileJinx($scoreMathJinx),
    ];

    return $programs;
}

function oracle_calculate_answer(array $data): array
{
    $business = require_string($data, 'business');
    $message = require_string($data, 'message');

    $days = require_int($data, 'days');
    $radiusMiles = require_int($data, 'radius_miles');
    $images = require_int($data, 'images');

    if ($days <= 0) {
        throw new RuntimeException('days must be greater than zero');
    }

    if ($radiusMiles <= 0) {
        throw new RuntimeException('radius_miles must be greater than zero');
    }

    if ($images < 2) {
        $images = 2;
    }

    $programs = oracle_programs();

    $priceCents = CoalescedOracleCompiler::execute($programs['price'], [
        'LOCAL:base_cents' => 300,
        'LOCAL:days' => $days,
        'LOCAL:radius_miles' => $radiusMiles,
        'LOCAL:radius_fee_cents' => 10,
        'LOCAL:images' => $images,
        'LOCAL:included_images' => 2,
        'LOCAL:image_fee_cents' => 75,
    ]);

    $messageLength = CoalescedOracleCompiler::execute($programs['message_length'], [
        'LOCAL:message' => $message,
    ]);

    $score = CoalescedOracleCompiler::execute($programs['score_math'], [
        'LOCAL:message_len' => $messageLength,
        'LOCAL:length_weight' => 1,
        'LOCAL:starting_score' => 30,
    ]);

    $score = max(0, min(100, (int) $score));

    $status = 'approved';

    if ($score < 45) {
        $status = 'needs_review';
    }

    if ($score < 25) {
        $status = 'rejected';
    }

    $priceDollars = number_format(((int) $priceCents) / 100, 2, '.', '');

    return [
        'ok' => true,
        'engine' => 'coalesced-oracle',
        'business' => $business,
        'status' => $status,
        'oracle' => [
            'price_program' => $programs['price'],
            'message_length_program' => $programs['message_length'],
            'score_math_program' => $programs['score_math'],
        ],
        'inputs' => [
            'days' => $days,
            'radius_miles' => $radiusMiles,
            'images' => $images,
            'message_length' => $messageLength,
        ],
        'result' => [
            'score' => $score,
            'price_cents' => $priceCents,
            'price_usd' => $priceDollars,
            'headline' => "{$business}: {$message}",
            'dynamic_answer' => "{$business} can run this {$days}-day local ad for about \${$priceDollars}. Status: {$status}.",
        ],
        'server_time' => date(DATE_ATOM),
    ];
}

function handle_server_request(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method !== 'POST') {
        json_response([
            'ok' => false,
            'error' => 'Use POST with JSON',
            'example' => [
                'business' => 'North Flint Market',
                'message' => 'Help our stores help your family with fresh local deals today.',
                'days' => 7,
                'radius_miles' => 10,
                'images' => 5,
            ],
        ], 405);
        return;
    }

    try {
        $data = read_json_body();
        json_response(oracle_calculate_answer($data));
    } catch (Throwable $e) {
        json_response([
            'ok' => false,
            'error' => $e->getMessage(),
        ], 400);
    }
}

function post_json_with_curl(string $url, array $payload): array
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        throw new RuntimeException('Could not encode payload');
    }

    $curl = curl_init($url);

    if ($curl === false) {
        throw new RuntimeException('Could not initialize cURL');
    }

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Content-Length: ' . strlen($json),
        ],
        CURLOPT_TIMEOUT => 10,
    ]);

    $body = curl_exec($curl);

    if ($body === false) {
        $error = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException("cURL failed: {$error}");
    }

    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    return [
        'status' => $status,
        'body' => (string) $body,
        'json' => json_decode((string) $body, true),
    ];
}

function run_cli_client(array $argv): void
{
    $url = $argv[1] ?? 'http://127.0.0.1:8099/oracle-post-curl-dynamic.php';

    $payload = [
        'business' => 'North Flint Market',
        'message' => 'Help our stores help your family with fresh local deals today.',
        'days' => 7,
        'radius_miles' => 10,
        'images' => 5,
    ];

    echo "POST {$url}" . PHP_EOL;
    echo "Payload:" . PHP_EOL;
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    echo PHP_EOL;

    $response = post_json_with_curl($url, $payload);

    echo "HTTP status: {$response['status']}" . PHP_EOL;

    if (is_array($response['json'])) {
        echo json_encode($response['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        echo $response['body'] . PHP_EOL;
    }
}

if (PHP_SAPI === 'cli') {
    run_cli_client($argv);
    exit;
}

handle_server_request();
