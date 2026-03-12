<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/activityMiddleware.php';
require_once __DIR__ . '/../services/ValidationService.php';

class UserController
{
    private $conn;
    private const DUPLICATE_KEY_ERRNO = 1062;

    public function __construct()
    {
        global $conn;
        $this->conn = $conn;
    }

    private function requireAdmin(): bool
    {
        $user = $GLOBALS['auth_user'] ?? null;
        $isAdmin = is_array($user) && !empty($user['is_admin']);

        if (!$isAdmin) {
            Response::json(["success" => false, "message" => "Admin only access"], 403);
            return false;
        }

        return true;
    }

    private function getRequestData()
    {
        $data = json_decode(file_get_contents("php://input"), true);
        return is_array($data) ? $data : null;
    }

    private function safeLogActivity(string $page, string $activity): void
    {
        if (!isset($GLOBALS['auth_user']['user_id'])) {
            return;
        }

        logActivity($this->conn, (int) $GLOBALS['auth_user']['user_id'], $page, $activity);
    }

    private function isDuplicateKeyError(mysqli_stmt $stmt): bool
    {
        return (int) ($stmt->errno ?? 0) === self::DUPLICATE_KEY_ERRNO
            || (int) ($this->conn->errno ?? 0) === self::DUPLICATE_KEY_ERRNO;
    }

    private function resolveId(array $data): int
    {
        $queryId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        if ($queryId !== false && $queryId > 0) {
            return (int) $queryId;
        }
        return ValidationService::intField($data['id'] ?? null, 'id');
    }

    public function list()
    {
        if (!$this->requireAdmin()) {
            return;
        }

        $includeInactive = ((string) ($_GET['include_inactive'] ?? '0')) === '1';
        $pagination = ValidationService::paginationFromQuery(20, 100);
        $limit = $pagination['limit'];
        $offset = $pagination['offset'];

        $sql = "
            SELECT u.user_id, u.user_name, u.email, r.role_name, u.account_status
            FROM users u
            JOIN roles r ON u.role_id = r.role_id
        ";
        if (!$includeInactive) {
            $sql .= " WHERE u.account_status = 'active' ";
        }
        $sql .= " ORDER BY u.user_id DESC LIMIT ? OFFSET ? ";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to fetch users"], 500);
            return;
        }
        $stmt->bind_param("ii", $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        if (!$result) {
            Response::json(["success" => false, "message" => "Failed to fetch users"], 500);
            return;
        }

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }

        $this->safeLogActivity("User List", "Fetched all users");

        Response::json(["success" => true, "data" => $data, "meta" => $pagination]);
    }

    public function create()
    {
        if (!$this->requireAdmin()) {
            return;
        }

        $data = $this->getRequestData();
        if ($data === null) {
            Response::json(["success" => false, "message" => "Invalid JSON body"], 400);
            return;
        }

        ValidationService::requireFields($data, ['user_name', 'email', 'password', 'role_id']);
        $userName = ValidationService::sanitizeString($data['user_name'], 30);
        $email = ValidationService::sanitizeEmail($data['email']);
        $password = (string) ($data['password'] ?? '');
        $roleId = ValidationService::intField($data['role_id'], 'role_id');
        if (strlen($password) < 8) {
            Response::json(["success" => false, "message" => "password must be at least 8 characters"], 400);
            return;
        }

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        if ($hashedPassword == false) {
            Response::json(["success" => false, "message" => "Failed to hash password"], 500);
            return;
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO users (user_name, email, password, role_id) VALUES (?, ?, ?, ?)"
        );
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to prepare user creation"], 500);
            return;
        }

        $stmt->bind_param("sssi", $userName, $email, $hashedPassword, $roleId);
        if (!$stmt->execute()) {
            if ($this->isDuplicateKeyError($stmt)) {
                Response::json(["success" => false, "message" => "Username or email already exists"], 409);
                return;
            }
            Response::json(["success" => false, "message" => "Failed to create user"], 500);
            return;
        }

        $this->safeLogActivity("User Create", "Created user: " . $userName);

        Response::json(["success" => true, "message" => "User created"], 201);
    }

    public function update()
    {
        if (!$this->requireAdmin()) {
            return;
        }

        $data = $this->getRequestData();
        if ($data === null) {
            Response::json(["success" => false, "message" => "Invalid JSON body"], 400);
            return;
        }

        ValidationService::requireFields($data, ['user_name', 'email', 'role_id']);
        $id = $this->resolveId($data);
        $userName = ValidationService::sanitizeString($data['user_name'], 30);
        $email = ValidationService::sanitizeEmail($data['email']);
        $roleId = ValidationService::intField($data['role_id'], 'role_id');

        $stmt = $this->conn->prepare(
            "UPDATE users SET user_name=?, email=?, role_id=? WHERE user_id=?"
        );
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to prepare user update"], 500);
            return;
        }

        $stmt->bind_param("ssii", $userName, $email, $roleId, $id);
        if (!$stmt->execute()) {
            if ($this->isDuplicateKeyError($stmt)) {
                Response::json(["success" => false, "message" => "Username or email already exists"], 409);
                return;
            }
            Response::json(["success" => false, "message" => "Failed to update user"], 500);
            return;
        }

        if ($stmt->affected_rows === 0) {
            Response::json(["success" => false, "message" => "User not found or no changes applied"], 404);
            return;
        }

        $this->safeLogActivity("User Update", "Updated user ID: " . $id);

        Response::json(["success" => true, "message" => "User updated"]);
    }

    public function delete()
    {
        if (!$this->requireAdmin()) {
            return;
        }

        $data = $this->getRequestData();
        if ($data === null) {
            Response::json(["success" => false, "message" => "Invalid JSON body"], 400);
            return;
        }

        $id = $this->resolveId($data);

        $stmt = $this->conn->prepare("UPDATE users SET account_status='inactive' WHERE user_id=? AND account_status='active'");
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to prepare user deletion"], 500);
            return;
        }

        $stmt->bind_param("i", $id);
        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to deactivate user"], 500);
            return;
        }

        if ($stmt->affected_rows === 0) {
            Response::json(["success" => false, "message" => "User not found"], 404);
            return;
        }

        $this->safeLogActivity("User Delete", "Deactivated user ID: " . $id);

        Response::json(["success" => true, "message" => "User deactivated"]);
    }

    public function resetPassword()
    {
        if (!$this->requireAdmin()) {
            return;
        }

        $data = $this->getRequestData();
        if ($data === null) {
            Response::json(["success" => false, "message" => "Invalid JSON body"], 400);
            return;
        }

        $id = ValidationService::intField($data['id'] ?? null, 'id');
        $newPassword = (string) ($data['new_password'] ?? '');
        if (trim($newPassword) === '') {
            Response::json(["success" => false, "message" => "Valid id and new_password are required"], 400);
            return;
        }

        if (strlen($newPassword) < 8) {
            Response::json(["success" => false, "message" => "new_password must be at least 8 characters"], 400);
            return;
        }

        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        if ($hashedPassword === false) {
            Response::json(["success" => false, "message" => "Failed to hash password"], 500);
            return;
        }

        $stmt = $this->conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to prepare password reset"], 500);
            return;
        }
        $stmt->bind_param("si", $hashedPassword, $id);
        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to reset password"], 500);
            return;
        }

        if ($stmt->affected_rows === 0) {
            Response::json(["success" => false, "message" => "User not found"], 404);
            return;
        }

        $this->safeLogActivity("User Reset Password", "Reset password for user ID: " . $id);
        Response::json(["success" => true, "message" => "Password reset successfully"]);
    }
}
