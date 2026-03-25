<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/activityMiddleware.php';

class AllocationController
{
    private $conn;
    private const ROLE_STUDENT = 'student';
    private const ROLE_TUTOR = 'tutor';
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

    private function requireAuth(): array
    {
        $user = $GLOBALS['auth_user'] ?? null;
        if (!is_array($user) || empty($user['user_id'])) {
            Response::json(["success" => false, "message" => "Unauthorized"], 401);
        }
        return $user;
    }

    private function getStudentIdByUserId(int $userId): int
    {
        $stmt = $this->conn->prepare("SELECT student_id FROM students WHERE user_id = ? LIMIT 1");
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param("i", $userId);
        if (!$stmt->execute()) {
            return 0;
        }
        $row = $stmt->get_result()->fetch_assoc();
        return (int) ($row['student_id'] ?? 0);
    }

    private function getTutorIdByUserId(int $userId): int
    {
        $stmt = $this->conn->prepare("SELECT tutor_id FROM tutors WHERE user_id = ? LIMIT 1");
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param("i", $userId);
        if (!$stmt->execute()) {
            return 0;
        }
        $row = $stmt->get_result()->fetch_assoc();
        return (int) ($row['tutor_id'] ?? 0);
    }

    private function getStaffIdByUserId(int $userId): int
    {
        $stmt = $this->conn->prepare("SELECT staff_id FROM staff WHERE user_id = ? LIMIT 1");
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param("i", $userId);
        if (!$stmt->execute()) {
            return 0;
        }
        $row = $stmt->get_result()->fetch_assoc();
        return (int) ($row['staff_id'] ?? 0);
    }

    private function resolveAuthenticatedStaffId(): int
    {
        $auth = $GLOBALS['auth_user'] ?? [];
        $staffId = (int) ($auth['staff_id'] ?? 0);
        if ($staffId > 0) {
            return $staffId;
        }

        $staffUserId = (int) ($auth['user_id'] ?? 0);
        if ($staffUserId <= 0) {
            return 0;
        }

        return $this->getStaffIdByUserId($staffUserId);
    }

    private function getRequestData()
    {
        $data = json_decode(file_get_contents("php://input"), true);
        return is_array($data) ? $data : null;
    }

    private function hasColumn(string $table, string $column): bool
    {
        $tableEscaped = $this->conn->real_escape_string($table);
        $columnEscaped = $this->conn->real_escape_string($column);
        $result = $this->conn->query("SHOW COLUMNS FROM `{$tableEscaped}` LIKE '{$columnEscaped}'");
        return $result && $result->num_rows > 0;
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

    private function envBool(string $key, bool $default = false): bool
    {
        if (array_key_exists($key, $_ENV)) {
            $envValue = $_ENV[$key];
            if (is_bool($envValue)) {
                return $envValue;
            }
            $raw = trim((string) $envValue);
            if ($raw !== '') {
                $value = strtolower($raw);
                return in_array($value, ['1', 'true', 'yes', 'on'], true);
            }
        }

        $raw = getenv($key);
        if ($raw === false || $raw === null || $raw === '') {
            return $default;
        }
        $value = strtolower(trim((string) $raw));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private function dispatchAllocationNotification(int $studentId, int $tutorId, string $event): void
    {
        // Dedicated switch: allow disabling allocation/reallocation emails without disabling all mail features.
        if (!$this->envBool('ETUTOR_ALLOCATION_MAIL_ENABLED', true)) {
            return;
        }

        if (!$this->envBool('ETUTOR_MAIL_ENABLED', false)) {
            return;
        }

        $runner = function () use ($studentId, $tutorId, $event) {
            try {
                $notifier = new NotificationService($this->conn);
                $notifier->sendAllocationNotification($studentId, $tutorId, $event);
            } catch (Throwable $e) {
                error_log("Allocation notification failed ({$event}): " . $e->getMessage());
            }
        };

        // Default to async dispatch to keep API response fast.
        $async = $this->envBool('ETUTOR_MAIL_ASYNC', true);
        if ($async) {
            if ($this->spawnAllocationNotificationProcess($studentId, $tutorId, $event)) {
                return;
            }

            // Fallback if process spawning is unavailable in current environment.
            register_shutdown_function(function () use ($runner) {
                if (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                }
                $runner();
            });
            return;
        }

        $runner();
    }

    private function spawnAllocationNotificationProcess(int $studentId, int $tutorId, string $event): bool
    {
        $script = realpath(__DIR__ . '/../tasks/send_allocation_notification.php');
        if ($script === false) {
            return false;
        }

        $configuredPhpCli = getenv('ETUTOR_PHP_CLI');
        $phpBinary = (is_string($configuredPhpCli) && trim($configuredPhpCli) !== '')
            ? trim($configuredPhpCli)
            : (PHP_BINARY ?: 'php');

        // Apache may expose php-cgi.exe as PHP_BINARY; prefer php.exe for CLI workers.
        if (stripos(basename($phpBinary), 'php-cgi.exe') !== false) {
            $candidate = dirname($phpBinary) . DIRECTORY_SEPARATOR . 'php.exe';
            if (is_file($candidate)) {
                $phpBinary = $candidate;
            }
        }

        if (!is_file($phpBinary) && stripos($phpBinary, 'php') === false) {
            error_log("Allocation notification spawn skipped: invalid php binary {$phpBinary}");
            return false;
        }

        $eventArg = preg_replace('/[^a-z_]/i', '', $event) ?: 'allocated';

        if (PHP_OS_FAMILY === 'Windows') {
            $phpCmd = str_replace('"', '""', $phpBinary);
            $scriptCmd = str_replace('"', '""', $script);
            $eventCmd = str_replace('"', '""', $eventArg);
            $cmd = 'cmd /c start "" /B "' . $phpCmd . '" "' . $scriptCmd . '" '
                . (int) $studentId . ' ' . (int) $tutorId . ' "' . $eventCmd . '"';

            $process = @popen($cmd, 'r');
            if ($process === false) {
                error_log("Allocation notification spawn failed: popen returned false");
                return false;
            }
            @pclose($process);
            return true;
        }

        $baseCommand = escapeshellarg($phpBinary)
            . ' ' . escapeshellarg($script)
            . ' ' . (int) $studentId
            . ' ' . (int) $tutorId
            . ' ' . escapeshellarg($eventArg);
        @exec($baseCommand . ' >/dev/null 2>&1 &');
        return true;
    }

    private function hasActiveAllocation(int $studentId): bool
    {
        $stmt = $this->conn->prepare("
            SELECT allocation_id
            FROM allocations
            WHERE student_id = ? AND status = 'active'
            LIMIT 1
        ");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("i", $studentId);
        if (!$stmt->execute()) {
            return false;
        }

        $result = $stmt->get_result();
        return $result && $result->num_rows > 0;
    }

    private function studentExists(int $studentId): bool
    {
        $stmt = $this->conn->prepare("SELECT student_id FROM students WHERE student_id = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("i", $studentId);
        if (!$stmt->execute()) {
            return false;
        }
        $result = $stmt->get_result();
        return $result && $result->num_rows > 0;
    }

    private function tutorExists(int $tutorId): bool
    {
        $stmt = $this->conn->prepare("SELECT tutor_id FROM tutors WHERE tutor_id = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("i", $tutorId);
        if (!$stmt->execute()) {
            return false;
        }
        $result = $stmt->get_result();
        return $result && $result->num_rows > 0;
    }

    public function list()
    {
        if (!$this->requireStaff()) {
            return;
        }

        $result = $this->conn->query("
            SELECT allocation_id, student_id, tutor_id, staff_id, allocated_date, status, end_at
            FROM allocations
            ORDER BY allocated_date DESC
        ");
        if (!$result) {
            Response::json(["success" => false, "message" => "Failed to fetch allocations"], 500);
            return;
        }

        $data = [];
        while ($row = $result->fetch_assoc()) $data[] = $row;

        $this->safeLogActivity("Allocation List", "Fetched all allocations");
        Response::json(["success" => true, "data" => $data]);
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

        $studentId = filter_var($data['student_id'] ?? null, FILTER_VALIDATE_INT);
        $tutorId = filter_var($data['tutor_id'] ?? null, FILTER_VALIDATE_INT);
        $staffId = $this->resolveAuthenticatedStaffId();
        $status = trim((string) ($data['status'] ?? 'active'));

        if ($studentId === false || $studentId <= 0 || $tutorId === false || $tutorId <= 0) {
            Response::json(["success" => false, "message" => "Valid student_id and tutor_id required"], 400);
            return;
        }

        if ($staffId <= 0) {
            Response::json(["success" => false, "message" => "Staff profile not found for current account"], 400);
            return;
        }

        if (!in_array($status, ['active', 'completed'], true)) {
            Response::json(["success" => false, "message" => "Invalid status"], 400);
            return;
        }

        $stmt = $this->conn->prepare("
            INSERT INTO allocations (student_id, tutor_id, staff_id, status)
            VALUES (?, ?, ?, ?)
        ");
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to prepare allocation creation"], 500);
            return;
        }

        $stmt->bind_param("iiis", $studentId, $tutorId, $staffId, $status);
        if (!$stmt->execute()) {
            if ($this->isDuplicateKeyError($stmt)) {
                Response::json(["success" => false, "message" => "Allocation conflicts with an existing record"], 409);
                return;
            }
            Response::json(["success" => false, "message" => "Failed to create allocation"], 500);
            return;
        }

        $this->dispatchAllocationNotification((int) $studentId, (int) $tutorId, 'allocated');

        $this->safeLogActivity("Allocation Create", "Created allocation for student ID: " . $studentId);
        Response::json(["success" => true, "message" => "Allocation created"], 201);
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

        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
        $studentId = filter_var($data['student_id'] ?? null, FILTER_VALIDATE_INT);
        $tutorId = filter_var($data['tutor_id'] ?? null, FILTER_VALIDATE_INT);
        $status = trim((string) ($data['status'] ?? ''));

        if ($id === false || $id <= 0 || $studentId === false || $studentId <= 0 || $tutorId === false || $tutorId <= 0 || $status === '') {
            Response::json(["success" => false, "message" => "Valid id, student_id, tutor_id, status required"], 400);
            return;
        }

        if (!in_array($status, ['active', 'completed'], true)) {
            Response::json(["success" => false, "message" => "Invalid status"], 400);
            return;
        }

        $stmt = $this->conn->prepare("
            UPDATE allocations
            SET student_id=?, tutor_id=?, status=?
            WHERE allocation_id=?
        ");
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to prepare allocation update"], 500);
            return;
        }

        $stmt->bind_param("iisi", $studentId, $tutorId, $status, $id);
        if (!$stmt->execute()) {
            if ($this->isDuplicateKeyError($stmt)) {
                Response::json(["success" => false, "message" => "Allocation conflicts with an existing record"], 409);
                return;
            }
            Response::json(["success" => false, "message" => "Failed to update allocation"], 500);
            return;
        }

        if ($stmt->affected_rows === 0) {
            Response::json(["success" => false, "message" => "Allocation not found or no changes applied"], 404);
            return;
        }

        $this->dispatchAllocationNotification((int) $studentId, (int) $tutorId, 'reallocated');

        $this->safeLogActivity("Allocation Update", "Updated allocation ID: " . $id);
        Response::json(["success" => true, "message" => "Allocation updated"]);
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

        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            Response::json(["success" => false, "message" => "Valid ID required"], 400);
            return;
        }

        $stmt = $this->conn->prepare("DELETE FROM allocations WHERE allocation_id=?");
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to prepare allocation deletion"], 500);
            return;
        }

        $stmt->bind_param("i", $id);
        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to delete allocation"], 500);
            return;
        }

        if ($stmt->affected_rows === 0) {
            Response::json(["success" => false, "message" => "Allocation not found"], 404);
            return;
        }

        $this->safeLogActivity("Allocation Delete", "Deleted allocation ID: " . $id);
        Response::json(["success" => true, "message" => "Allocation deleted"]);
    }

    public function bulk()
    {
        if (!$this->requireStaff()) {
            return;
        }

        $data = $this->getRequestData();
        if ($data === null) {
            Response::json(["success" => false, "message" => "Invalid JSON body"], 400);
            return;
        }

        $items = $data['allocations'] ?? null;
        if (!is_array($items) || count($items) === 0) {
            Response::json(["success" => false, "message" => "allocations array is required"], 400);
            return;
        }

        if (count($items) > 10) {
            Response::json(["success" => false, "message" => "Maximum 10 allocations per request"], 400);
            return;
        }

        $status = strtolower(trim((string) ($data['status'] ?? 'active')));
        if (!in_array($status, ['active', 'completed'], true)) {
            Response::json(["success" => false, "message" => "Invalid status"], 400);
            return;
        }

        $staffId = $this->resolveAuthenticatedStaffId();
        if ($staffId <= 0) {
            Response::json(["success" => false, "message" => "Staff profile not found for current account"], 400);
            return;
        }

        $insertStmt = $this->conn->prepare("
            INSERT INTO allocations (student_id, tutor_id, staff_id, status)
            VALUES (?, ?, ?, ?)
        ");
        if (!$insertStmt) {
            Response::json(["success" => false, "message" => "Failed to prepare bulk allocation"], 500);
            return;
        }

        $created = [];
        $skipped = [];
        $failed = [];

        foreach ($items as $index => $item) {
            $studentId = filter_var($item['student_id'] ?? null, FILTER_VALIDATE_INT);
            $tutorId = filter_var($item['tutor_id'] ?? null, FILTER_VALIDATE_INT);

            if ($studentId === false || $studentId <= 0 || $tutorId === false || $tutorId <= 0) {
                $failed[] = [
                    "index" => $index,
                    "reason" => "Invalid student_id or tutor_id",
                    "item" => $item
                ];
                continue;
            }

            if ($this->hasActiveAllocation((int) $studentId)) {
                $skipped[] = [
                    "index" => $index,
                    "student_id" => (int) $studentId,
                    "reason" => "Student already has an active allocation"
                ];
                continue;
            }

            if (!$this->studentExists((int) $studentId)) {
                $failed[] = [
                    "index" => $index,
                    "student_id" => (int) $studentId,
                    "reason" => "Student not found"
                ];
                continue;
            }

            if (!$this->tutorExists((int) $tutorId)) {
                $failed[] = [
                    "index" => $index,
                    "tutor_id" => (int) $tutorId,
                    "reason" => "Tutor not found"
                ];
                continue;
            }

            $sid = (int) $studentId;
            $tid = (int) $tutorId;
            try {
                $insertStmt->bind_param("iiis", $sid, $tid, $staffId, $status);
                if (!$insertStmt->execute()) {
                    $reason = $this->isDuplicateKeyError($insertStmt)
                        ? "Duplicate allocation"
                        : "Database insert failed";
                    $failed[] = [
                        "index" => $index,
                        "student_id" => $sid,
                        "tutor_id" => $tid,
                        "reason" => $reason
                    ];
                    continue;
                }
            } catch (Throwable $e) {
                $failed[] = [
                    "index" => $index,
                    "student_id" => $sid,
                    "tutor_id" => $tid,
                    "reason" => "Database insert exception"
                ];
                continue;
            }

            $this->dispatchAllocationNotification($sid, $tid, 'allocated');

            $created[] = [
                "index" => $index,
                "allocation_id" => $this->conn->insert_id,
                "student_id" => $sid,
                "tutor_id" => $tid
            ];
        }

        $this->safeLogActivity(
            "Allocation Bulk",
            "Bulk allocation processed. Created: " . count($created) . ", skipped: " . count($skipped) . ", failed: " . count($failed)
        );

        Response::json([
            "success" => true,
            "data" => [
                "total" => count($items),
                "created_count" => count($created),
                "skipped_count" => count($skipped),
                "failed_count" => count($failed),
                "created" => $created,
                "skipped" => $skipped,
                "failed" => $failed
            ]
        ], 207);
    }

    public function reallocate()
    {
        if (!$this->requireStaff()) {
            return;
        }

        $data = $this->getRequestData();
        if ($data === null) {
            Response::json(["success" => false, "message" => "Invalid JSON body"], 400);
            return;
        }

        $studentId = filter_var($data['student_id'] ?? null, FILTER_VALIDATE_INT);
        $newTutorId = filter_var($data['new_tutor_id'] ?? null, FILTER_VALIDATE_INT);
        $staffId = $this->resolveAuthenticatedStaffId();

        if ($studentId === false || $studentId <= 0 || $newTutorId === false || $newTutorId <= 0) {
            Response::json(["success" => false, "message" => "Valid student_id and new_tutor_id are required"], 400);
            return;
        }

        if ($staffId <= 0) {
            Response::json(["success" => false, "message" => "Staff profile not found for current account"], 400);
            return;
        }

        $studentId = (int) $studentId;
        $newTutorId = (int) $newTutorId;

        $this->conn->begin_transaction();
        try {
            $findStmt = $this->conn->prepare("
                SELECT allocation_id, tutor_id
                FROM allocations
                WHERE student_id = ? AND status = 'active'
                ORDER BY allocation_id DESC
                LIMIT 1
            ");
            if (!$findStmt) {
                throw new Exception("Failed to prepare active allocation lookup");
            }
            $findStmt->bind_param("i", $studentId);
            if (!$findStmt->execute()) {
                throw new Exception("Failed to lookup active allocation");
            }
            $active = $findStmt->get_result()->fetch_assoc();
            if (!$active) {
                throw new Exception("No active allocation found for student");
            }

            if ((int) $active['tutor_id'] === $newTutorId) {
                throw new Exception("Student is already allocated to this tutor");
            }

            $closeStmt = $this->conn->prepare("
                UPDATE allocations
                SET status = 'completed', end_at = NOW()
                WHERE allocation_id = ?
            ");
            if (!$closeStmt) {
                throw new Exception("Failed to prepare allocation close");
            }
            $activeId = (int) $active['allocation_id'];
            $closeStmt->bind_param("i", $activeId);
            if (!$closeStmt->execute()) {
                throw new Exception("Failed to close current allocation");
            }

            $createStmt = $this->conn->prepare("
                INSERT INTO allocations (student_id, tutor_id, staff_id, status)
                VALUES (?, ?, ?, 'active')
            ");
            if (!$createStmt) {
                throw new Exception("Failed to prepare new allocation");
            }
            $createStmt->bind_param("iii", $studentId, $newTutorId, $staffId);
            if (!$createStmt->execute()) {
                if ($this->isDuplicateKeyError($createStmt)) {
                    throw new Exception("Duplicate allocation conflict");
                }
                throw new Exception("Failed to create new allocation");
            }

            $newAllocationId = (int) $this->conn->insert_id;
            $oldTutorId = (int) $active['tutor_id'];

            $this->conn->commit();

            $this->dispatchAllocationNotification($studentId, $newTutorId, 'reallocated');

            $this->safeLogActivity(
                "Allocation Reallocate",
                "Reallocated student {$studentId} from tutor {$oldTutorId} to {$newTutorId}"
            );

            Response::json([
                "success" => true,
                "message" => "Student reallocated successfully",
                "data" => [
                    "student_id" => $studentId,
                    "old_tutor_id" => $oldTutorId,
                    "new_tutor_id" => $newTutorId,
                    "new_allocation_id" => $newAllocationId
                ]
            ]);
        } catch (Throwable $e) {
            $this->conn->rollback();
            Response::json(["success" => false, "message" => $e->getMessage()], 400);
        }
    }

    public function myTutor()
    {
        $user = $this->requireAuth();
        $role = (string) ($user['role'] ?? '');
        if ($role !== self::ROLE_STUDENT) {
            Response::json(["success" => false, "message" => "Student only access"], 403);
        }

        $userId = (int) $user['user_id'];
        $studentId = $this->getStudentIdByUserId($userId);
        if ($studentId <= 0) {
            Response::json(["success" => false, "message" => "Student profile not found"], 404);
        }

        $photoSelect = $this->hasColumn('users', 'profile_photo')
            ? ', u.profile_photo AS tutor_profile_photo'
            : ', NULL AS tutor_profile_photo';

        $stmt = $this->conn->prepare("
            SELECT a.allocation_id, a.status, a.allocated_date, a.end_at,
                   t.tutor_id, u.user_id AS tutor_user_id, u.user_name AS tutor_user_name, u.email AS tutor_email,
                   tt.full_name AS tutor_full_name, tt.department AS tutor_department, tt.contact_number AS tutor_contact_number
                   {$photoSelect}
            FROM allocations a
            JOIN tutors tt ON a.tutor_id = tt.tutor_id
            JOIN users u ON tt.user_id = u.user_id
            JOIN tutors t ON t.tutor_id = a.tutor_id
            WHERE a.student_id = ? AND a.status = 'active'
            ORDER BY a.allocated_date DESC
            LIMIT 1
        ");
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to fetch allocated tutor"], 500);
        }

        $stmt->bind_param("i", $studentId);
        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to fetch allocated tutor"], 500);
        }

        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            Response::json(["success" => true, "data" => null, "message" => "No active tutor allocation found"]);
        }

        $this->safeLogActivity("Allocation MyTutor", "Viewed allocated tutor");
        Response::json(["success" => true, "data" => $row]);
    }

    public function assignedStudents()
    {
        $user = $this->requireAuth();
        $role = (string) ($user['role'] ?? '');
        if ($role !== self::ROLE_TUTOR) {
            Response::json(["success" => false, "message" => "Tutor only access"], 403);
        }

        $userId = (int) $user['user_id'];
        $tutorId = $this->getTutorIdByUserId($userId);
        if ($tutorId <= 0) {
            Response::json(["success" => false, "message" => "Tutor profile not found"], 404);
        }

        $photoSelect = $this->hasColumn('users', 'profile_photo')
            ? ', u.profile_photo AS student_profile_photo'
            : ', NULL AS student_profile_photo';

        $stmt = $this->conn->prepare("
            SELECT a.allocation_id, a.student_id, a.status, a.allocated_date, a.end_at,
                   u.user_id AS student_user_id, u.user_name AS student_user_name, u.email AS student_email,
                   s.full_name AS student_full_name, s.programme AS student_programme, s.contact_number AS student_contact_number
                   {$photoSelect}
            FROM allocations a
            JOIN students s ON a.student_id = s.student_id
            JOIN users u ON s.user_id = u.user_id
            WHERE a.tutor_id = ? AND a.status = 'active'
            ORDER BY a.allocated_date DESC
        ");
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to fetch assigned students"], 500);
        }

        $stmt->bind_param("i", $tutorId);
        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to fetch assigned students"], 500);
        }

        $rows = [];
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        $this->safeLogActivity("Allocation AssignedStudents", "Viewed assigned students");
        Response::json(["success" => true, "data" => $rows]);
    }
}
