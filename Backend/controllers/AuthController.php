<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/jwt.php';
require_once __DIR__ . '/../middleware/activityMiddleware.php';
require_once __DIR__ . '/../services/ValidationService.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthController
{
    private function ensureLoginLockoutTable(mysqli $conn): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS login_lockouts (
                lockout_id INT AUTO_INCREMENT PRIMARY KEY,
                client_key VARCHAR(191) NOT NULL UNIQUE,
                failed_attempts INT NOT NULL DEFAULT 0,
                locked_until DATETIME NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ";
        if (!$conn->query($sql)) {
            error_log("LOGIN_LOCKOUT_TABLE_INIT_FAILED");
        }
    }

    private function getClientKey(): string
    {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        $candidate = '';
        if (is_string($forwarded) && trim($forwarded) !== '') {
            $parts = explode(',', $forwarded);
            $candidate = trim((string) ($parts[0] ?? ''));
        }
        if ($candidate === '') {
            $candidate = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        }
        return substr($candidate, 0, 190);
    }

    private function secondsRemainingFromDateTime(?string $lockedUntil): int
    {
        if (!$lockedUntil) {
            return 0;
        }
        $lockedTs = strtotime($lockedUntil);
        if ($lockedTs === false) {
            return 0;
        }
        return max(0, $lockedTs - time());
    }

    private function getUserLockRemainingSeconds(mysqli $conn, int $userId): int
    {
        $stmt = $conn->prepare("
            SELECT GREATEST(TIMESTAMPDIFF(SECOND, NOW(), locked_until), 0) AS remaining
            FROM users
            WHERE user_id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param("i", $userId);
        if (!$stmt->execute()) {
            return 0;
        }

        $row = $stmt->get_result()->fetch_assoc();
        return (int) ($row['remaining'] ?? 0);
    }

    private function formatCountdown(int $seconds): string
    {
        $safe = max(0, $seconds);
        $minutes = intdiv($safe, 60);
        $remaining = $safe % 60;
        return sprintf('%02d:%02d', $minutes, $remaining);
    }

    private function getClientLockout(mysqli $conn, string $clientKey): ?array
    {
        $stmt = $conn->prepare("
            SELECT failed_attempts, locked_until
            FROM login_lockouts
            WHERE client_key = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param("s", $clientKey);
        if (!$stmt->execute()) {
            return null;
        }
        $row = $stmt->get_result()->fetch_assoc();
        return is_array($row) ? $row : null;
    }

    private function getClientLockRemainingSeconds(mysqli $conn, string $clientKey): int
    {
        $stmt = $conn->prepare("
            SELECT GREATEST(TIMESTAMPDIFF(SECOND, NOW(), locked_until), 0) AS remaining
            FROM login_lockouts
            WHERE client_key = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param("s", $clientKey);
        if (!$stmt->execute()) {
            return 0;
        }
        $row = $stmt->get_result()->fetch_assoc();
        return (int) ($row['remaining'] ?? 0);
    }

    private function normalizeClientLockState(mysqli $conn, string $clientKey): int
    {
        $remaining = $this->getClientLockRemainingSeconds($conn, $clientKey);
        if ($remaining > 60) {
            $this->clearClientLockout($conn, $clientKey);
            return 0;
        }
        return max(0, $remaining);
    }

    private function clearClientLockout(mysqli $conn, string $clientKey): void
    {
        $stmt = $conn->prepare("
            INSERT INTO login_lockouts (client_key, failed_attempts, locked_until)
            VALUES (?, 0, NULL)
            ON DUPLICATE KEY UPDATE failed_attempts = 0, locked_until = NULL
        ");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param("s", $clientKey);
        $stmt->execute();
    }

    private function registerClientFailedAttempt(mysqli $conn, string $clientKey): array
    {
        $row = $this->getClientLockout($conn, $clientKey);
        $failedAttempts = (int) ($row['failed_attempts'] ?? 0) + 1;
        $lockedUntil = null;
        $isLocked = false;
        $remaining = 0;

        if ($failedAttempts >= 3) {
            $isLocked = true;
            $remaining = 60;
            $lockedUntil = date('Y-m-d H:i:s', time() + 60);
        }

        if ($isLocked) {
            $stmt = $conn->prepare("
                INSERT INTO login_lockouts (client_key, failed_attempts, locked_until)
                VALUES (?, 0, DATE_ADD(NOW(), INTERVAL 1 MINUTE))
                ON DUPLICATE KEY UPDATE failed_attempts = 0, locked_until = DATE_ADD(NOW(), INTERVAL 1 MINUTE)
            ");
            if ($stmt) {
                $stmt->bind_param("s", $clientKey);
                $stmt->execute();
                $fresh = $this->getClientLockout($conn, $clientKey);
                $lockedUntil = (string) ($fresh['locked_until'] ?? $lockedUntil);
                $remaining = $this->getClientLockRemainingSeconds($conn, $clientKey);
            }
        } else {
            $stmt = $conn->prepare("
                INSERT INTO login_lockouts (client_key, failed_attempts, locked_until)
                VALUES (?, ?, NULL)
                ON DUPLICATE KEY UPDATE failed_attempts = VALUES(failed_attempts), locked_until = NULL
            ");
            if ($stmt) {
                $stmt->bind_param("si", $clientKey, $failedAttempts);
                $stmt->execute();
            }
        }

        return [
            'is_locked' => $isLocked,
            'remaining_seconds' => $remaining,
            'locked_until' => $lockedUntil
        ];
    }

    private function envBool(string $key, bool $default = false): bool
    {
        if (array_key_exists($key, $_ENV)) {
            $envValue = $_ENV[$key];
            if (is_bool($envValue)) {
                return $envValue;
            }
            $raw = trim((string) $envValue);
            if ($raw !== '') {
                return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
            }
        }

        $raw = getenv($key);
        if ($raw === false || $raw === null || trim((string) $raw) === '') {
            return $default;
        }

        return in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes', 'on'], true);
    }

    private function hasActiveTutorAllocationForStudentUser(mysqli $conn, int $userId): bool
    {
        $stmt = $conn->prepare("
            SELECT a.allocation_id
            FROM students s
            JOIN allocations a ON a.student_id = s.student_id
            WHERE s.user_id = ?
              AND a.status = 'active'
            LIMIT 1
        ");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("i", $userId);
        if (!$stmt->execute()) {
            return false;
        }

        $result = $stmt->get_result();
        return $result && $result->num_rows > 0;
    }

    private function isFirstLoginUser(mysqli $conn, int $userId, $lastLogin = null): bool
    {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM activity_logs
            WHERE user_id = ?
              AND activity_type = 'User Logged in'
        ");
        if (!$stmt) {
            return empty($lastLogin);
        }

        $stmt->bind_param("i", $userId);
        if (!$stmt->execute()) {
            return empty($lastLogin);
        }

        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $total = (int) ($row['total'] ?? 0);
        return $total === 0;
    }

    private function hasColumn(mysqli $conn, string $table, string $column): bool
    {
        $tableEscaped = $conn->real_escape_string($table);
        $columnEscaped = $conn->real_escape_string($column);
        $result = $conn->query("SHOW COLUMNS FROM `{$tableEscaped}` LIKE '{$columnEscaped}'");
        return $result && $result->num_rows > 0;
    }

    private function ensureTokenVersionColumn(mysqli $conn): void
    {
        if ($this->hasColumn($conn, 'users', 'token_version')) {
            return;
        }

        $sql = "ALTER TABLE users ADD COLUMN token_version INT NOT NULL DEFAULT 0 AFTER password";
        if (!$conn->query($sql) && !$this->hasColumn($conn, 'users', 'token_version')) {
            Response::json(["message" => "Failed to initialize token version"], 500);
        }
    }

    private function ensureMustChangePasswordColumn(mysqli $conn): void
    {
        if ($this->hasColumn($conn, 'users', 'must_change_password')) {
            return;
        }

        $sql = "ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER token_version";
        if (!$conn->query($sql) && !$this->hasColumn($conn, 'users', 'must_change_password')) {
            Response::json(["message" => "Failed to initialize password policy flag"], 500);
        }
    }

    private function ensureLoginAttemptColumns(mysqli $conn): bool
    {
        if (!$this->hasColumn($conn, 'users', 'failed_login_attempts')) {
            $sql = "ALTER TABLE users ADD COLUMN failed_login_attempts INT NOT NULL DEFAULT 0 AFTER must_change_password";
            if (!$conn->query($sql) && !$this->hasColumn($conn, 'users', 'failed_login_attempts')) {
                error_log("LOGIN_LOCK_INIT_FAILED: failed_login_attempts");
            }
        }

        if (!$this->hasColumn($conn, 'users', 'locked_until')) {
            $sql = "ALTER TABLE users ADD COLUMN locked_until DATETIME NULL AFTER failed_login_attempts";
            if (!$conn->query($sql) && !$this->hasColumn($conn, 'users', 'locked_until')) {
                error_log("LOGIN_LOCK_INIT_FAILED: locked_until");
            }
        }

        return $this->hasColumn($conn, 'users', 'failed_login_attempts')
            && $this->hasColumn($conn, 'users', 'locked_until');
    }

    private function getBearerTokenFromHeaders(): ?string
    {
        $headers = getallheaders();
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
        $this->ensureTokenVersionColumn($conn);
        $this->ensureMustChangePasswordColumn($conn);
        $lockFeatureReady = $this->ensureLoginAttemptColumns($conn);
        $this->ensureLoginLockoutTable($conn);
        $clientKey = $this->getClientKey();
        $failedAttemptsSelect = $lockFeatureReady ? "users.failed_login_attempts" : "0 AS failed_login_attempts";
        $lockedUntilSelect = $lockFeatureReady ? "users.locked_until" : "NULL AS locked_until";

        $clientLockRow = $this->getClientLockout($conn, $clientKey);
        $clientLockedUntil = (string) ($clientLockRow['locked_until'] ?? '');
        $clientRemaining = $this->normalizeClientLockState($conn, $clientKey);
        if ($clientRemaining > 0) {
            Response::json([
                "message" => "Too many failed attempts. Please wait " . $this->formatCountdown($clientRemaining) . " before trying again.",
                "data" => [
                    "lock_scope" => "client",
                    "retry_after_seconds" => $clientRemaining,
                    "locked_until" => $clientLockedUntil
                ]
            ], 429);
        }

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
        users.account_status,
        users.token_version,
        users.must_change_password,
        {$failedAttemptsSelect},
        {$lockedUntilSelect},
        users.profile_photo,
        users.last_login,
        roles.role_name,
        COALESCE(students.full_name, tutors.full_name, staff.full_name, users.user_name) AS full_name,
        COALESCE(staff.is_admin, 0) AS is_admin
    FROM users
    JOIN roles ON users.role_id = roles.role_id
    LEFT JOIN students ON users.user_id = students.user_id
    LEFT JOIN tutors ON users.user_id = tutors.user_id
    LEFT JOIN staff ON users.user_id = staff.user_id
    WHERE users.email = ? OR users.user_name = ?
");

        if (!$stmt) {
            Response::json(["message" => "Failed to process login"], 500);
        }
        $stmt->bind_param("ss", $login, $login);
        if (!$stmt->execute()) {
            Response::json(["message" => "Failed to process login"], 500);
        }
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {

            $user = $result->fetch_assoc();

            if ((string) ($user['account_status'] ?? '') !== 'active') {
                Response::json(["message" => "Account is inactive"], 403);
            }

            if (password_verify($password, $user['password'])) {
                $this->clearClientLockout($conn, $clientKey);
                if ($lockFeatureReady) {
                    $resetAttemptsStmt = $conn->prepare("
                        UPDATE users
                        SET failed_login_attempts = 0, locked_until = NULL
                        WHERE user_id = ?
                    ");
                    if ($resetAttemptsStmt) {
                        $resetAttemptsStmt->bind_param("i", $user['user_id']);
                        $resetAttemptsStmt->execute();
                    }
                }

                $roleName = strtolower((string) ($user['role_name'] ?? ''));
                $enforceActiveTutor = $this->envBool('ETUTOR_REQUIRE_ACTIVE_TUTOR_ON_STUDENT_LOGIN', false);
                if (
                    $enforceActiveTutor
                    && $roleName === 'student'
                    && !$this->hasActiveTutorAllocationForStudentUser($conn, (int) $user['user_id'])
                ) {
                    Response::json([
                        "message" => "No personal tutor is allocated to this student yet. Please contact staff."
                    ], 403);
                }

                $isFirstLogin = $this->isFirstLoginUser($conn, (int) $user['user_id'], $user['last_login'] ?? null);
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
                        "full_name" => $user['full_name'] ?? $user['user_name'],
                        "display_name" => $user['full_name'] ?? $user['user_name'],
                        "user_name" => $user['user_name'],
                        "email" => $user['email'],
                        "role" => $user['role_name'],
                        "is_admin" => $user['is_admin'],
                        "profile_photo" => $user['profile_photo'] ?? null,
                        "is_first_login" => $isFirstLogin,
                        "must_change_password" => (int) ($user['must_change_password'] ?? 0) === 1
                    ]
                ]);
            } else {
                $clientLock = $this->registerClientFailedAttempt($conn, $clientKey);

                if (!empty($clientLock['is_locked'])) {
                    $remaining = (int) ($clientLock['remaining_seconds'] ?? 60);
                    $lockedUntil = (string) ($clientLock['locked_until'] ?? '');
                    Response::json([
                        "message" => "Too many failed attempts. Please wait " . $this->formatCountdown($remaining) . " before trying again.",
                        "data" => [
                            "lock_scope" => "client",
                            "retry_after_seconds" => $remaining,
                            "locked_until" => $lockedUntil
                        ]
                    ], 429);
                }

                Response::json(["message" => "Invalid credentials"], 401);
            }
        } else {
            // Do not increment lock counter for unknown username/email.
            // Lock policy must apply only to password failures on existing accounts.
            Response::json(["message" => "Invalid credentials"], 401);
        }
    }
    public function refresh()
    {
        Request::requireMethod("POST");
        global $refresh_secret, $conn;
        $this->ensureTokenVersionColumn($conn);

        $data = $this->getRequestData();
        if ($data === null) {
            Response::json(["message" => "Invalid JSON body"], 400);
        }

        ValidationService::requireFields($data, ['refresh_token']);

        $token = $data['refresh_token'];

        try {
            $decoded = JWT::decode($token, new Key($refresh_secret, 'HS256'));

            if ($decoded->type !== "refresh") throw new Exception("Invalid token type");

            $user_id = (int) ($decoded->user_id ?? 0);
            $role = (string) ($decoded->role ?? '');
            $is_admin = (int) ($decoded->is_admin ?? 0);
            $tokenVersion = (int) ($decoded->token_version ?? 0);

            $stmt = $conn->prepare("
                SELECT account_status, token_version
                FROM users
                WHERE user_id = ?
                LIMIT 1
            ");
            if (!$stmt) {
                Response::json(["message" => "Failed to validate refresh token"], 500);
            }
            $stmt->bind_param("i", $user_id);
            if (!$stmt->execute()) {
                Response::json(["message" => "Failed to validate refresh token"], 500);
            }
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            if (!$row || (string) ($row['account_status'] ?? '') !== 'active') {
                Response::json(["message" => "Invalid or expired refresh token"], 401);
            }

            if ((int) ($row['token_version'] ?? 0) !== $tokenVersion) {
                Response::json(["message" => "Refresh token revoked"], 401);
            }

            // Generate new access token
            $new_access_token = generateAccessToken([
                "user_id" => $user_id,
                "role_name" => $role,
                "is_admin" => $is_admin,
                "token_version" => $tokenVersion
            ]);
            Response::json(data: ["access_token" => $new_access_token]);
        } catch (Exception $e) {
            Response::json(["message" => "Invalid or expired refresh token"], 401);
        }
    }

    public function logout()
    {
        Request::requireMethod("POST");
        global $conn;
        $this->ensureTokenVersionColumn($conn);

        AuthMiddleware::handle();
        $authUser = $GLOBALS['auth_user'] ?? null;
        $currentUserId = (int) ($authUser['user_id'] ?? 0);

        if ($currentUserId <= 0) {
            Response::json(["message" => "Invalid user"], 401);
        }

        $stmt = $conn->prepare("
            UPDATE users
            SET token_version = token_version + 1
            WHERE user_id = ? AND account_status = 'active'
        ");
        if (!$stmt) {
            Response::json(["message" => "Failed to logout"], 500);
        }
        $stmt->bind_param("i", $currentUserId);
        if (!$stmt->execute()) {
            Response::json(["message" => "Failed to logout"], 500);
        }

        logActivity($conn, $currentUserId, "Logout", "User Logged out");
        Response::json(["message" => "Logged out successfully"]);
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
            SELECT user_id, user_name, email
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
        $userName = (string) ($row['user_name'] ?? 'User');
        $email = (string) ($row['email'] ?? '');

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

        $exposeToken = strtolower((string) getenv('ETUTOR_MAIL_EXPOSE_RESET_TOKEN')) === 'true'
            || strtolower((string) getenv('ETUTOR_MAIL_ENABLED')) !== 'true';

        if ($exposeToken) {
            Response::json([
                "message" => "Reset token generated",
                "data" => [
                    "reset_token" => $rawToken,
                    "expires_in_minutes" => 30
                ]
            ]);
        }

        Response::json(["message" => "Reset token generated"]);
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
