<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/activityMiddleware.php';
require_once __DIR__ . '/../services/ValidationService.php';

class UserController
{
    private $conn;
    private const DUPLICATE_KEY_ERRNO = 1062;
    private const PROFILE_PHOTO_DIR = 'profile_photos';

    public function __construct()
    {
        global $conn;
        $this->conn = $conn;
    }

    private function requireAuth(): array
    {
        $user = $GLOBALS['auth_user'] ?? null;
        if (!is_array($user) || empty($user['user_id'])) {
            Response::json(["success" => false, "message" => "Unauthorized"], 401);
        }

        return $user;
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

    private function requireStaff(): bool
    {
        $user = $GLOBALS['auth_user'] ?? null;
        $role = is_array($user) ? (string) ($user['role'] ?? '') : '';

        if ($role !== 'staff') {
            Response::json(["success" => false, "message" => "Staff only access"], 403);
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

    private function getRoleNameById(int $roleId): ?string
    {
        $stmt = $this->conn->prepare("SELECT role_name FROM roles WHERE role_id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param("i", $roleId);
        if (!$stmt->execute()) {
            return null;
        }

        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        return $row ? strtolower((string) ($row['role_name'] ?? '')) : null;
    }

    private function getRoleIdByName(string $roleName): int
    {
        $stmt = $this->conn->prepare("SELECT role_id FROM roles WHERE LOWER(role_name) = ? LIMIT 1");
        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param("s", $roleName);
        if (!$stmt->execute()) {
            return 0;
        }

        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        return (int) ($row['role_id'] ?? 0);
    }

    private function resolveRoleInput(array $data): array
    {
        if (array_key_exists('role_id', $data) && $data['role_id'] !== null && $data['role_id'] !== '') {
            $roleId = ValidationService::intField($data['role_id'], 'role_id');
            $roleName = $this->getRoleNameById($roleId);
            if ($roleName === null || $roleName === '') {
                Response::json(["success" => false, "message" => "Invalid role_id"], 400);
            }
            return [$roleId, $roleName];
        }

        if (array_key_exists('role_name', $data) && trim((string) $data['role_name']) !== '') {
            $roleName = strtolower(ValidationService::sanitizeString($data['role_name'], 30));
            $roleId = $this->getRoleIdByName($roleName);
            if ($roleId <= 0) {
                Response::json(["success" => false, "message" => "Invalid role_name"], 400);
            }
            return [$roleId, $roleName];
        }

        Response::json(["success" => false, "message" => "role_id or role_name is required"], 400);
        return [0, ''];
    }

    private function insertRoleProfile(int $userId, string $roleName, array $data): void
    {
        if (!in_array($roleName, ['student', 'tutor', 'staff'], true)) {
            return;
        }

        $table = $roleName === 'student' ? 'students' : ($roleName === 'tutor' ? 'tutors' : 'staff');
        $columns = ['user_id'];
        $types = 'i';
        $values = [$userId];

        $fullNameRaw = $data['full_name'] ?? ($data['name'] ?? '');
        $fullName = trim((string) $fullNameRaw);
        if ($fullName !== '' && $this->hasColumn($table, 'full_name')) {
            $columns[] = 'full_name';
            $types .= 's';
            $values[] = ValidationService::sanitizeString($fullName, 100);
        }

        if ($this->hasColumn($table, 'contact_number')) {
            $contactNumber = trim((string) ($data['contact_number'] ?? ($data['phone_number'] ?? '')));
            if ($contactNumber !== '') {
                $columns[] = 'contact_number';
                $types .= 's';
                $values[] = ValidationService::sanitizeString($contactNumber, 30);
            }
        }

        if ($roleName === 'student' && $this->hasColumn($table, 'programme')) {
            $programme = trim((string) ($data['programme'] ?? ''));
            if ($programme !== '') {
                $columns[] = 'programme';
                $types .= 's';
                $values[] = ValidationService::sanitizeString($programme, 100);
            }
        }

        if (($roleName === 'tutor' || $roleName === 'staff') && $this->hasColumn($table, 'department')) {
            $department = trim((string) ($data['department'] ?? ''));
            if ($department !== '') {
                $columns[] = 'department';
                $types .= 's';
                $values[] = ValidationService::sanitizeString($department, 100);
            }
        }

        if ($roleName === 'staff' && $this->hasColumn($table, 'is_admin')) {
            $isAdminInput = $data['is_admin'] ?? 0;
            if (is_string($isAdminInput)) {
                $isAdminInput = strtolower(trim($isAdminInput));
                $isAdmin = in_array($isAdminInput, ['1', 'true', 'yes', 'authorized', 'authorised', 'admin'], true) ? 1 : 0;
            } else {
                $isAdmin = (int) ((bool) $isAdminInput);
            }
            $columns[] = 'is_admin';
            $types .= 'i';
            $values[] = $isAdmin;
        }

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $columnSql = implode(', ', $columns);
        $sql = "INSERT INTO {$table} ({$columnSql}) VALUES ({$placeholders})";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to prepare {$roleName} profile creation"], 500);
        }

        $stmt->bind_param($types, ...$values);
        if (!$stmt->execute()) {
            if ($this->isDuplicateKeyError($stmt)) {
                Response::json(["success" => false, "message" => ucfirst($roleName) . " profile already exists"], 409);
            }
            Response::json(["success" => false, "message" => "Failed to create {$roleName} profile"], 500);
        }
    }

    private function fetchProfileByUserId(int $userId): ?array
    {
        $profilePhotoSelect = $this->hasColumn('users', 'profile_photo')
            ? 'u.profile_photo'
            : 'NULL AS profile_photo';

        $stmt = $this->conn->prepare("
            SELECT
                u.user_id,
                u.user_name,
                u.email,
                u.account_status,
                {$profilePhotoSelect},
                r.role_name,
                COALESCE(s.full_name, t.full_name, sf.full_name) AS full_name,
                COALESCE(s.contact_number, t.contact_number) AS contact_number,
                s.programme,
                COALESCE(t.department, sf.department) AS department,
                s.student_id,
                t.tutor_id,
                sf.staff_id,
                COALESCE(st.is_admin, 0) AS is_admin
            FROM users u
            JOIN roles r ON u.role_id = r.role_id
            LEFT JOIN students s ON s.user_id = u.user_id
            LEFT JOIN tutors t ON t.user_id = u.user_id
            LEFT JOIN staff sf ON sf.user_id = u.user_id
            LEFT JOIN staff st ON st.user_id = u.user_id
            WHERE u.user_id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            return null;
        }

        $stmt->bind_param("i", $userId);
        if (!$stmt->execute()) {
            return null;
        }

        $result = $stmt->get_result();
        if (!$result || $result->num_rows === 0) {
            return null;
        }

        return $result->fetch_assoc();
    }

    private function hasColumn(string $table, string $column): bool
    {
        $tableEscaped = $this->conn->real_escape_string($table);
        $columnEscaped = $this->conn->real_escape_string($column);
        $result = $this->conn->query("SHOW COLUMNS FROM `{$tableEscaped}` LIKE '{$columnEscaped}'");
        return $result && $result->num_rows > 0;
    }

    private function ensureProfilePhotoColumn(): void
    {
        if ($this->hasColumn('users', 'profile_photo')) {
            return;
        }

        $sql = "ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) NULL AFTER email";
        if (!$this->conn->query($sql) && !$this->hasColumn('users', 'profile_photo')) {
            Response::json(["success" => false, "message" => "Failed to initialize profile photo storage"], 500);
        }
    }

    private function deleteStoredProfilePhoto(?string $webPath): void
    {
        $path = (string) $webPath;
        if ($path === '' || !str_starts_with($path, '/Backend/uploads/' . self::PROFILE_PHOTO_DIR . '/')) {
            return;
        }

        $root = realpath(__DIR__ . '/..');
        if ($root === false) {
            return;
        }

        $relative = substr($path, strlen('/Backend/'));
        $fullPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    private function resolveUploadedProfilePhotoPath(?string $oldPath = null): string
    {
        if (!isset($_FILES['file'])) {
            Response::json(["success" => false, "message" => "Photo file is required"], 400);
        }

        $file = $_FILES['file'];
        if (!isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
            Response::json(["success" => false, "message" => "Photo upload failed"], 400);
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        $name = (string) ($file['name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($tmpPath === '' || $name === '' || $size <= 0) {
            Response::json(["success" => false, "message" => "Invalid uploaded photo"], 400);
        }
        if ($size > 5 * 1024 * 1024) {
            Response::json(["success" => false, "message" => "Photo too large (max 5MB)"], 400);
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($ext, $allowedExtensions, true)) {
            Response::json(["success" => false, "message" => "Unsupported image type"], 400);
        }

        $mimeType = mime_content_type($tmpPath) ?: '';
        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mimeType, $allowedMimeTypes, true)) {
            Response::json(["success" => false, "message" => "Invalid image file"], 400);
        }

        $root = realpath(__DIR__ . '/..');
        if ($root === false) {
            Response::json(["success" => false, "message" => "Server storage path error"], 500);
        }

        $targetDir = $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . self::PROFILE_PHOTO_DIR;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            Response::json(["success" => false, "message" => "Failed to initialize profile photo directory"], 500);
        }

        $safeName = bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $safeName;
        if (!move_uploaded_file($tmpPath, $targetPath)) {
            Response::json(["success" => false, "message" => "Failed to store uploaded photo"], 500);
        }

        $this->deleteStoredProfilePhoto($oldPath);
        return '/Backend/uploads/' . self::PROFILE_PHOTO_DIR . '/' . $safeName;
    }

    private function requireSelfEditableRole(array $user): string
    {
        $role = (string) ($user['role'] ?? '');
        if (!in_array($role, ['student', 'tutor', 'staff'], true)) {
            Response::json(["success" => false, "message" => "Only student, tutor, or staff profiles can be edited here"], 403);
        }

        return $role;
    }

    public function me()
    {
        Request::requireMethod("GET");

        $authUser = $this->requireAuth();
        $userId = (int) $authUser['user_id'];
        $row = $this->fetchProfileByUserId($userId);

        if (!$row) {
            Response::json(["success" => false, "message" => "User profile not found"], 404);
            return;
        }

        $displayName = trim((string) ($row['full_name'] ?? ''));
        if ($displayName === '') {
            $displayName = (string) ($row['user_name'] ?? '');
        }

        $studentId = isset($row['student_id']) ? (int) $row['student_id'] : 0;
        $tutorId = isset($row['tutor_id']) ? (int) $row['tutor_id'] : 0;

        $data = [
            "user_id" => (int) ($row['user_id'] ?? 0),
            "user_name" => (string) ($row['user_name'] ?? ''),
            "email" => (string) ($row['email'] ?? ''),
            "role" => (string) ($row['role_name'] ?? ''),
            "account_status" => (string) ($row['account_status'] ?? ''),
            "is_admin" => (int) ($row['is_admin'] ?? 0) === 1,
            "profile" => [
                "display_name" => $displayName,
                "full_name" => $displayName,
                "contact_number" => (string) ($row['contact_number'] ?? ''),
                "profile_photo" => (string) ($row['profile_photo'] ?? ''),
                "programme" => $row['programme'] ?? null,
                "department" => $row['department'] ?? null,
                "student_id" => $studentId > 0 ? $studentId : null,
                "tutor_id" => $tutorId > 0 ? $tutorId : null,
                "staff_id" => (int) ($row['staff_id'] ?? 0) > 0 ? (int) $row['staff_id'] : null
            ]
        ];

        $this->safeLogActivity("User Me", "Viewed own profile");
        Response::json(["success" => true, "data" => $data]);
    }

    public function updateMe()
    {
        Request::requireMethod("PUT");

        $authUser = $this->requireAuth();
        $role = $this->requireSelfEditableRole($authUser);
        $data = $this->getRequestData();
        if ($data === null) {
            Response::json(["success" => false, "message" => "Invalid JSON body"], 400);
            return;
        }

        $userId = (int) $authUser['user_id'];

        if ($role === 'staff') {
            $fullName = trim((string) ($data['full_name'] ?? ''));
            $department = trim((string) ($data['department'] ?? ''));
            if ($fullName === '') {
                Response::json(["success" => false, "message" => "full_name is required"], 400);
                return;
            }

            if (mb_strlen($fullName) > 100) {
                Response::json(["success" => false, "message" => "full_name is too long"], 400);
                return;
            }
            if (mb_strlen($department) > 100) {
                Response::json(["success" => false, "message" => "department is too long"], 400);
                return;
            }

            $stmt = $this->conn->prepare("UPDATE staff SET full_name = ?, department = ? WHERE user_id = ?");
            if (!$stmt) {
                Response::json(["success" => false, "message" => "Failed to prepare profile update"], 500);
                return;
            }
            $stmt->bind_param("ssi", $fullName, $department, $userId);
        } else {
            if (!array_key_exists('contact_number', $data)) {
                Response::json(["success" => false, "message" => "contact_number is required"], 400);
                return;
            }

            $contactNumber = trim((string) $data['contact_number']);
            if ($contactNumber === '') {
                Response::json(["success" => false, "message" => "Phone number is required"], 400);
                return;
            }

            if (mb_strlen($contactNumber) > 30) {
                Response::json(["success" => false, "message" => "Phone number is too long"], 400);
                return;
            }

            $table = $role === 'student' ? 'students' : 'tutors';
            $stmt = $this->conn->prepare("UPDATE {$table} SET contact_number = ? WHERE user_id = ?");
            if (!$stmt) {
                Response::json(["success" => false, "message" => "Failed to prepare profile update"], 500);
                return;
            }
            $stmt->bind_param("si", $contactNumber, $userId);
        }

        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to prepare profile update"], 500);
            return;
        }

        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to update profile"], 500);
            return;
        }

        $profile = $this->fetchProfileByUserId($userId);
        if (!$profile) {
            Response::json(["success" => false, "message" => "User profile not found"], 404);
            return;
        }

        $this->safeLogActivity("User Update Me", "Updated own profile");
        if ($role === 'staff') {
            Response::json([
                "success" => true,
                "message" => "Profile updated successfully",
                "data" => [
                    "full_name" => (string) ($profile['full_name'] ?? ''),
                    "department" => (string) ($profile['department'] ?? '')
                ]
            ]);
            return;
        }

        Response::json([
            "success" => true,
            "message" => "Profile updated successfully",
            "data" => [
                "contact_number" => (string) ($profile['contact_number'] ?? $contactNumber)
            ]
        ]);
    }

    public function uploadMyPhoto()
    {
        Request::requireMethod("POST");

        $authUser = $this->requireAuth();
        $this->requireSelfEditableRole($authUser);
        $this->ensureProfilePhotoColumn();

        $userId = (int) $authUser['user_id'];
        $existingProfile = $this->fetchProfileByUserId($userId);
        if (!$existingProfile) {
            Response::json(["success" => false, "message" => "User profile not found"], 404);
            return;
        }

        $photoPath = $this->resolveUploadedProfilePhotoPath($existingProfile['profile_photo'] ?? null);
        $stmt = $this->conn->prepare("UPDATE users SET profile_photo = ? WHERE user_id = ?");
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to prepare profile photo update"], 500);
            return;
        }

        $stmt->bind_param("si", $photoPath, $userId);
        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to update profile photo"], 500);
            return;
        }

        $this->safeLogActivity("User Upload Photo", "Updated own profile photo");
        Response::json([
            "success" => true,
            "message" => "Profile photo updated successfully",
            "data" => [
                "profile_photo" => $photoPath
            ]
        ]);
    }

    public function changeMyPassword()
    {
        Request::requireMethod("POST");

        $authUser = $this->requireAuth();
        $data = $this->getRequestData();
        if ($data === null) {
            Response::json(["success" => false, "message" => "Invalid JSON body"], 400);
            return;
        }

        ValidationService::requireFields($data, ['old_password', 'new_password']);
        $oldPassword = (string) $data['old_password'];
        $newPassword = (string) $data['new_password'];

        if (strlen($newPassword) < 8) {
            Response::json(["success" => false, "message" => "New password must be at least 8 characters"], 400);
            return;
        }

        if ($oldPassword === $newPassword) {
            Response::json(["success" => false, "message" => "New password must be different from old password"], 400);
            return;
        }

        $userId = (int) $authUser['user_id'];
        $stmt = $this->conn->prepare("SELECT password FROM users WHERE user_id = ? LIMIT 1");
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to prepare password lookup"], 500);
            return;
        }

        $stmt->bind_param("i", $userId);
        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to verify current password"], 500);
            return;
        }

        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        if (!$row || empty($row['password'])) {
            Response::json(["success" => false, "message" => "User account not found"], 404);
            return;
        }

        if (!password_verify($oldPassword, $row['password'])) {
            Response::json(["success" => false, "message" => "Current password is incorrect"], 400);
            return;
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        if ($newHash === false) {
            Response::json(["success" => false, "message" => "Failed to hash new password"], 500);
            return;
        }

        $updateStmt = $this->conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        if (!$updateStmt) {
            Response::json(["success" => false, "message" => "Failed to prepare password change"], 500);
            return;
        }

        $updateStmt->bind_param("si", $newHash, $userId);
        if (!$updateStmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to change password"], 500);
            return;
        }

        $this->safeLogActivity("User Change Password", "Changed own password");
        Response::json(["success" => true, "message" => "Password changed successfully"]);
    }

    public function list()
    {
        if (!$this->requireStaff()) {
            return;
        }

        $includeInactive = ((string) ($_GET['include_inactive'] ?? '0')) === '1';
        $pagination = ValidationService::paginationFromQuery(20, 100);
        $limit = $pagination['limit'];
        $offset = $pagination['offset'];

        $sql = "
            SELECT
                u.user_id,
                u.user_name,
                u.email,
                r.role_name,
                u.account_status,
                s2.staff_id,
                s.student_id,
                t.tutor_id,
                COALESCE(s.full_name, t.full_name, s2.full_name, u.user_name) AS full_name,
                COALESCE(s.contact_number, t.contact_number) AS contact_number,
                s.programme,
                COALESCE(t.department, s2.department) AS department
            FROM users u
            JOIN roles r ON u.role_id = r.role_id
            LEFT JOIN students s ON s.user_id = u.user_id
            LEFT JOIN tutors t ON t.user_id = u.user_id
            LEFT JOIN staff s2 ON s2.user_id = u.user_id
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
        if (!$this->requireStaff()) {
            return;
        }

        $data = $this->getRequestData();
        if ($data === null) {
            Response::json(["success" => false, "message" => "Invalid JSON body"], 400);
            return;
        }

        ValidationService::requireFields($data, ['user_name', 'email', 'password']);
        $userName = ValidationService::sanitizeString($data['user_name'], 30);
        $email = ValidationService::sanitizeEmail($data['email']);
        $password = (string) ($data['password'] ?? '');
        [$roleId, $roleName] = $this->resolveRoleInput($data);
        if (strlen($password) < 8) {
            Response::json(["success" => false, "message" => "password must be at least 8 characters"], 400);
            return;
        }

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        if ($hashedPassword == false) {
            Response::json(["success" => false, "message" => "Failed to hash password"], 500);
            return;
        }

        $stmt = $this->conn->prepare("INSERT INTO users (user_name, email, password, role_id) VALUES (?, ?, ?, ?)");
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to prepare user creation"], 500);
            return;
        }

        $this->conn->begin_transaction();
        try {
            $stmt->bind_param("sssi", $userName, $email, $hashedPassword, $roleId);
            if (!$stmt->execute()) {
                if ($this->isDuplicateKeyError($stmt)) {
                    $this->conn->rollback();
                    Response::json(["success" => false, "message" => "Username or email already exists"], 409);
                }
                $this->conn->rollback();
                Response::json(["success" => false, "message" => "Failed to create user"], 500);
            }

            $newUserId = (int) $this->conn->insert_id;
            $this->insertRoleProfile($newUserId, $roleName, $data);
            $this->conn->commit();
        } catch (Throwable $e) {
            $this->conn->rollback();
            Response::json(["success" => false, "message" => "Failed to create user"], 500);
        }

        $this->safeLogActivity("User Create", "Created user: " . $userName);
        Response::json([
            "success" => true,
            "message" => "User created",
            "data" => [
                "user_name" => $userName,
                "email" => $email,
                "role_name" => $roleName
            ]
        ], 201);
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

        $stmt = $this->conn->prepare("UPDATE users SET password = ?, token_version = token_version + 1 WHERE user_id = ?");
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

        $this->safeLogActivity("User Reset Password", "Reset password and revoked sessions for user ID: " . $id);
        Response::json(["success" => true, "message" => "Password reset successfully. Existing sessions have been invalidated."]);
    }
}
