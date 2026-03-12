<?php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/jwt.php';
require_once __DIR__ . '/activityMiddleware.php';

class AuthMiddleware
{
    public static function handle()
    {
        global $access_secret;
        $headers = getallheaders();

        if (!isset($headers['Authorization'])) {
            Response::json(["message" => "No token provided"], 401);
        }

        $parts = explode(" ", $headers['Authorization']);

        if (count($parts) !== 2) {
            Response::json(["message" => "Invalid token format"], 401);
        }

        try {
            $decoded = JWT::decode(
                $parts[1],
                new Key($access_secret, 'HS256')
            );

            if ($decoded->type !== "access") {
                throw new Exception("Invalid token");
            }

            $GLOBALS['auth_user'] = [
                "user_id" => $decoded->user_id,
                "role" => $decoded->role ?? null,
                "is_admin" => $decoded->is_admin ?? 0
            ];

            global $conn;
            $page = $_GET['controller'] ?? 'unknown';
            $action = $_GET['action'] ?? '';
            $pageWithAction = $action ? ($page . ':' . $action) : $page;
            logActivity($conn, (int) $decoded->user_id, $pageWithAction, 'API access');

        } catch (Exception $e) {
            Response::json(["message" => "TOKEN_EXPIRED_OR_INVALID"], 401);
        }
    }
}
