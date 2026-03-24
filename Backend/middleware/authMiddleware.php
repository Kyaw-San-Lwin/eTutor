<?php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/jwt.php';
require_once __DIR__ . '/activityMiddleware.php';

class AuthMiddleware
{
    private static function hasColumn(mysqli $conn, string $table, string $column): bool
    {
        $tableEscaped = $conn->real_escape_string($table);
        $columnEscaped = $conn->real_escape_string($column);
        $result = $conn->query("SHOW COLUMNS FROM `{$tableEscaped}` LIKE '{$columnEscaped}'");
        return $result && $result->num_rows > 0;
    }

    private static function ensureTokenVersionColumn(mysqli $conn): void
    {
        if (self::hasColumn($conn, 'users', 'token_version')) {
            return;
        }

        $sql = "ALTER TABLE users ADD COLUMN token_version INT NOT NULL DEFAULT 0 AFTER password";
        if (!$conn->query($sql) && !self::hasColumn($conn, 'users', 'token_version')) {
            Response::json(["message" => "Failed to initialize token version"], 500);
        }
    }

    private static function getBearerTokenFromHeaders(array $headers): ?string
    {
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
        if (!$authHeader) {
            return null;
        }

        $parts = explode(" ", trim($authHeader), 2);
        if (count($parts) !== 2 || strcasecmp($parts[0], "Bearer") !== 0) {
            return null;
        }

        return trim($parts[1]);
    }

    public static function handle()
    {
        global $access_secret, $conn;
        $headers = getallheaders();
        self::ensureTokenVersionColumn($conn);

        $bearerToken = self::getBearerTokenFromHeaders($headers);
        if (!$bearerToken) {
            Response::json(["message" => "No token provided"], 401);
        }

        try {
            $decoded = JWT::decode(
                $bearerToken,
                new Key($access_secret, 'HS256')
            );

            if ($decoded->type !== "access") {
                throw new Exception("Invalid token");
            }

            $userId = (int) ($decoded->user_id ?? 0);
            if ($userId <= 0) {
                Response::json(["message" => "TOKEN_EXPIRED_OR_INVALID"], 401);
            }

            $stmt = $conn->prepare("
                SELECT u.account_status, u.token_version, r.role_name, COALESCE(s.is_admin, 0) AS is_admin, s.staff_id
                FROM users u
                JOIN roles r ON r.role_id = u.role_id
                LEFT JOIN staff s ON s.user_id = u.user_id
                WHERE u.user_id = ?
                LIMIT 1
            ");
            if (!$stmt) {
                Response::json(["message" => "Authentication check failed"], 500);
            }
            $stmt->bind_param("i", $userId);
            if (!$stmt->execute()) {
                Response::json(["message" => "Authentication check failed"], 500);
            }
            $result = $stmt->get_result();
            $dbUser = $result ? $result->fetch_assoc() : null;
            if (!$dbUser || (string) ($dbUser['account_status'] ?? '') !== 'active') {
                Response::json(["message" => "TOKEN_EXPIRED_OR_INVALID"], 401);
            }

            $jwtTokenVersion = (int) ($decoded->token_version ?? 0);
            $dbTokenVersion = (int) ($dbUser['token_version'] ?? 0);
            if ($jwtTokenVersion !== $dbTokenVersion) {
                Response::json(["message" => "TOKEN_REVOKED"], 401);
            }

            $GLOBALS['auth_user'] = [
                "user_id" => $userId,
                "role" => $dbUser['role_name'] ?? ($decoded->role ?? null),
                "is_admin" => (int) ($dbUser['is_admin'] ?? ($decoded->is_admin ?? 0)),
                "staff_id" => (int) ($dbUser['staff_id'] ?? 0)
            ];

            $page = $_GET['controller'] ?? 'unknown';
            $action = $_GET['action'] ?? '';
            $pageWithAction = $action ? ($page . ':' . $action) : $page;
            logActivity($conn, $userId, $pageWithAction, 'API access');

        } catch (Exception $e) {
            Response::json(["message" => "TOKEN_EXPIRED_OR_INVALID"], 401);
        }
    }
}
