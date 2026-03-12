<?php

require_once __DIR__ . '/../services/PermissionService.php';
require_once __DIR__ . '/../config/database.php';

class PermissionMiddleware
{
    public static function check($permission)
    {
        global $conn;

        $user = $GLOBALS['auth_user'] ?? null;

        if (!$user) {
            Response::json(["message" => "Unauthorized"], 401);
        }

        if (!checkPermission($conn, $user['user_id'], $permission)) {
            Response::json(["message" => "Access Denied"], 403);
        }
    }
}