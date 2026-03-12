<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/jwt.php';
require_once __DIR__ . '/../middleware/activityMiddleware.php';
require_once __DIR__ . '/../services/ValidationService.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthController
{
    private function getRequestData(): ?array
    {
        $data = json_decode(file_get_contents("php://input"), true);
        return is_array($data) ? $data : null;
    }

    private function ensurePasswordResetTable(mysqli $conn): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS password_resets (
                reset_id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                INDEX idx_token_hash (token_hash),
                INDEX idx_expires_at (expires_at)
            )
        ";
        $conn->query($sql);
    }

    public function login()
    {
        Request::requireMethod("POST");
        global $conn;

        $data = $this->getRequestData();
        if ($data === null) {
            Response::json(["message" => "Invalid JSON body"], 400);
        }

        ValidationService::requireFields($data, ['login', 'password']);
        $login = ValidationService::sanitizeString($data['login'], 100);
        $password = (string) $data['password'];

        $stmt = $conn->prepare("
    SELECT 
        users.user_id,
        users.user_name,
        users.email,
        users.password,
        roles.role_name,
        staff.is_admin
    FROM users
    JOIN roles ON users.role_id = roles.role_id
    LEFT JOIN staff ON users.user_id = staff.user_id
    WHERE users.email = ? OR users.user_name = ?
");


        $stmt->bind_param("ss", $login, $login);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {

            $user = $result->fetch_assoc();

            if (password_verify($password, $user['password'])) {
                $updateLastLogin = $conn->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
                if ($updateLastLogin) {
                    $updateLastLogin->bind_param("i", $user['user_id']);
                    $updateLastLogin->execute();
                }

                $accessToken  = generateAccessToken($user);
                $refreshToken = generateRefreshToken($user);

                logActivity(
                    $conn,
                    $user['user_id'],
                    "Login",
                    "User Logged in"
                );

                Response::json([
                    "access_token" => $accessToken,
                    "refresh_token" => $refreshToken,
                    "user" => [
                        "id" => $user['user_id'],
                        "name" => $user['user_name'],
                        "email" => $user['email'],
                        "role" => $user['role_name'],
                        "is_admin" => $user['is_admin']
                    ]
                ]);
            } else {
                Response::json(["message" => "Invalid credentials"], 401);
            }
        } else {
            Response::json(["message" => "Invalid credentials"], 401);
        }
    }
    public function refresh()
    {
        Request::requireMethod("POST");
        global $refresh_secret;

        $data = $this->getRequestData();
        if ($data === null) {
            Response::json(["message" => "Invalid JSON body"], 400);
        }

        ValidationService::requireFields($data, ['refresh_token']);

        $token = $data['refresh_token'];

        try {
            $decoded = JWT::decode($token, new Key($refresh_secret, 'HS256'));

            if ($decoded->type !== "refresh") throw new Exception("Invalid token type");

            $user_id = $decoded->user_id;
            $role = $decoded->role;
            $is_admin = $decoded->is_admin;

            // Generate new access token
            $new_access_token = generateAccessToken([
                "user_id" => $user_id,
                "role_name" => $role,
                "is_admin" => $is_admin
            ]);
            Response::json(data: ["access_token" => $new_access_token]);
        } catch (Exception $e) {
            Response::json(["message" => "Invalid or expired refresh token"], 401);
        }
    }

    public function requestPasswordReset()
    {
        Request::requireMethod("POST");
        global $conn;

        $data = $this->getRequestData();
        if ($data === null) {
            Response::json(["message" => "Invalid JSON body"], 400);
        }

        ValidationService::requireFields($data, ['login']);
        $login = ValidationService::sanitizeString($data['login'], 100);

        $this->ensurePasswordResetTable($conn);

        $stmt = $conn->prepare("
            SELECT user_id
            FROM users
            WHERE (email = ? OR user_name = ?) AND account_status = 'active'
            LIMIT 1
        ");
        if (!$stmt) {
            Response::json(["message" => "Failed to process reset request"], 500);
        }

        $stmt->bind_param("ss", $login, $login);
        if (!$stmt->execute()) {
            Response::json(["message" => "Failed to process reset request"], 500);
        }

        $result = $stmt->get_result();
        if (!$result || $result->num_rows === 0) {
            Response::json([
                "message" => "If account exists, reset instructions have been generated"
            ]);
        }

        $row = $result->fetch_assoc();
        $userId = (int) ($row['user_id'] ?? 0);

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);

        $insertStmt = $conn->prepare("
            INSERT INTO password_resets (user_id, token_hash, expires_at)
            VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))
        ");
        if (!$insertStmt) {
            Response::json(["message" => "Failed to process reset request"], 500);
        }
        $insertStmt->bind_param("is", $userId, $tokenHash);
        if (!$insertStmt->execute()) {
            Response::json(["message" => "Failed to process reset request"], 500);
        }

        Response::json([
            "message" => "Reset token generated",
            "data" => [
                "reset_token" => $rawToken,
                "expires_in_minutes" => 30
            ]
        ]);
    }

    public function resetPasswordByToken()
    {
        Request::requireMethod("POST");
        global $conn;

        $data = $this->getRequestData();
        if ($data === null) {
            Response::json(["message" => "Invalid JSON body"], 400);
        }

        ValidationService::requireFields($data, ['token', 'new_password']);
        $token = ValidationService::sanitizeString($data['token'], 200);
        $newPassword = (string) $data['new_password'];

        if (strlen($newPassword) < 8) {
            Response::json(["message" => "new_password must be at least 8 characters"], 400);
        }

        $this->ensurePasswordResetTable($conn);
        $tokenHash = hash('sha256', $token);

        $stmt = $conn->prepare("
            SELECT reset_id, user_id
            FROM password_resets
            WHERE token_hash = ?
              AND used_at IS NULL
              AND expires_at > NOW()
            ORDER BY created_at DESC
            LIMIT 1
        ");
        if (!$stmt) {
            Response::json(["message" => "Failed to reset password"], 500);
        }

        $stmt->bind_param("s", $tokenHash);
        if (!$stmt->execute()) {
            Response::json(["message" => "Failed to reset password"], 500);
        }

        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        if (!$row) {
            Response::json(["message" => "Invalid or expired reset token"], 400);
        }

        $resetId = (int) $row['reset_id'];
        $userId = (int) $row['user_id'];
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            Response::json(["message" => "Failed to hash password"], 500);
        }

        $conn->begin_transaction();
        try {
            $updateUser = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
            if (!$updateUser) {
                throw new Exception("Failed to update user password");
            }
            $updateUser->bind_param("si", $passwordHash, $userId);
            if (!$updateUser->execute()) {
                throw new Exception("Failed to update user password");
            }

            $useToken = $conn->prepare("UPDATE password_resets SET used_at = NOW() WHERE reset_id = ?");
            if (!$useToken) {
                throw new Exception("Failed to update reset token");
            }
            $useToken->bind_param("i", $resetId);
            if (!$useToken->execute()) {
                throw new Exception("Failed to update reset token");
            }

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            Response::json(["message" => "Failed to reset password"], 500);
        }

        Response::json(["message" => "Password reset successful"]);
    }
}
