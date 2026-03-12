<?php

$token = $argv[1] ?? '';
if ($token === '') {
    fwrite(STDERR, "usage: php Backend/tasks/test_inactivity_api.php <jwt>\n");
    exit(1);
}

function callApi(string $url, string $token, string $method = 'GET'): array
{
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => [
                "Authorization: Bearer {$token}",
                "Content-Type: application/json"
            ],
            'timeout' => 20
        ]
    ]);

    $body = @file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? 'HTTP/1.1 000';
    preg_match('/\s(\d{3})\s/', $statusLine, $m);
    $status = isset($m[1]) ? (int) $m[1] : 0;

    return [
        'status' => $status,
        'body' => $body
    ];
}

$base = 'http://localhost:80/eTutor/Backend/api/index.php?controller=inactivity';
$list = callApi($base, $token, 'GET');
$warn = callApi($base . '&action=warn&days=28', $token, 'POST');

echo json_encode([
    'list' => $list,
    'warn' => $warn
], JSON_PRETTY_PRINT) . PHP_EOL;
