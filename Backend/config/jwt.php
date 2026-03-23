<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;

function normalizeJwtSecret(string $envKey, string $fallback): string
{
    $value = getenv($envKey);
    if ($value === false || trim($value) === '') {
        return $fallback;
    }
    $value = (string) $value;
    if (strlen($value) < 32) {
        error_log("JWT secret too short for {$envKey}; using fallback.");
        return $fallback;
    }
    return $value;
}

$access_secret = normalizeJwtSecret(
    "ETUTOR_JWT_ACCESS_SECRET",
    "ACCESS_SECRET_SUPER_LONG_KEY_123456789"
);
$refresh_secret = normalizeJwtSecret(
    "ETUTOR_JWT_REFRESH_SECRET",
    "REFRESH_SECRET_SUPER_LONG_KEY_987654321"
);

function generateAccessToken($user)
{
    global $access_secret;

    $payload = [
        "iss" => "localhost",
        "iat" => time(),
        "exp" => time() + (60 * 15), // 10 minutes
        "type" => "access",
        "user_id" => $user['user_id'],
        "role" => $user['role_name'],
        "is_admin" => $user['is_admin'] ?? 0,
        "token_version" => (int) ($user['token_version'] ?? 0)

    ];

    return JWT::encode($payload, $access_secret, 'HS256');
}

function generateRefreshToken($user)
{
    global $refresh_secret;

    $payload = [
        "iss" => "localhost",
        "iat" => time(),
        "exp" => time() + (60 * 60 * 24), // 24 hours
        "type" => "refresh",
        "user_id" => $user['user_id'],
        "role" => $user['role_name'],
        "is_admin" => $user['is_admin'] ?? 0,
        "token_version" => (int) ($user['token_version'] ?? 0)

    ];


    return JWT::encode($payload, $refresh_secret, 'HS256');
}
