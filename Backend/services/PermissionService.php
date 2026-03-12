<?php

function checkPermission($conn, $user_id, $permission)
{

    // 1. Check if user is admin staff
    $adminQuery = "
        SELECT s.is_admin
        FROM users u
        JOIN staff s ON u.user_id = s.user_id
        WHERE u.user_id = ?
    ";

    $stmt = $conn->prepare($adminQuery);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        if ($row['is_admin'] == 1) {
            return true;
        }
    }

    // 2. Check role permission
    $sql = "
        SELECT p.permission_name
        FROM users u
        JOIN roles r ON u.role_id = r.role_id
        JOIN role_permissions rp ON r.role_id = rp.role_id
        JOIN permissions p ON rp.permission_id = p.permission_id
        WHERE u.user_id = ?
        AND p.permission_name = ?
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $user_id, $permission);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result->num_rows > 0;
}

function authUser(): array
{
    $user = $GLOBALS['auth_user'] ?? null;
    if (!is_array($user) || empty($user['user_id'])) {
        Response::json(["success" => false, "message" => "Unauthorized"], 401);
    }
    return $user;
}

function requireRoles(array $allowedRoles): array
{
    $user = authUser();
    $role = (string) ($user['role'] ?? '');
    if (!in_array($role, $allowedRoles, true)) {
        Response::json(["success" => false, "message" => "Access denied"], 403);
    }
    return $user;
}

function requireAdminStaff(): array
{
    $user = authUser();
    $isAdmin = !empty($user['is_admin']);
    if (!$isAdmin) {
        Response::json(["success" => false, "message" => "Admin only access"], 403);
    }
    return $user;
}
