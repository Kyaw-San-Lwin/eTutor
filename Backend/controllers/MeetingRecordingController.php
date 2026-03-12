<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/activityMiddleware.php';
require_once __DIR__ . '/../services/PermissionService.php';

class MeetingRecordingController
{
    private $conn;
    private const ALLOWED_ROLES = ['student', 'tutor', 'staff'];

    public function __construct()
    {
        global $conn;
        $this->conn = $conn;
    }

    private function requireAuth(): array
    {
        return authUser();
    }

    private function requireRole(array $user): void
    {
        requireRoles(self::ALLOWED_ROLES);
    }

    private function getRequestData()
    {
        $data = json_decode(file_get_contents("php://input"), true);
        return is_array($data) ? $data : null;
    }

    private function resolveUploadedFilePath(string $subDir): string
    {
        if (!isset($_FILES['file'])) {
            return '';
        }

        $file = $_FILES['file'];
        if (!isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
            Response::json(["success" => false, "message" => "File upload failed"], 400);
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        $name = (string) ($file['name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($tmpPath === '' || $name === '' || $size <= 0) {
            Response::json(["success" => false, "message" => "Invalid uploaded file"], 400);
        }
        if ($size > 300 * 1024 * 1024) {
            Response::json(["success" => false, "message" => "File too large (max 300MB)"], 400);
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = ['mp4', 'mov', 'mkv', 'webm'];
        if (!in_array($ext, $allowed, true)) {
            Response::json(["success" => false, "message" => "Unsupported recording file type"], 400);
        }

        $root = realpath(__DIR__ . '/..');
        if ($root === false) {
            Response::json(["success" => false, "message" => "Server storage path error"], 500);
        }
        $targetDir = $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $subDir;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            Response::json(["success" => false, "message" => "Failed to initialize upload directory"], 500);
        }

        $safeName = bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $safeName;
        if (!move_uploaded_file($tmpPath, $targetPath)) {
            Response::json(["success" => false, "message" => "Failed to store uploaded file"], 500);
        }

        return '/Backend/uploads/' . $subDir . '/' . $safeName;
    }

    private function safeLogActivity(int $userId, string $page, string $activity): void
    {
        if ($userId <= 0) {
            return;
        }
        logActivity($this->conn, $userId, $page, $activity);
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
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
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
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        return (int) ($row['tutor_id'] ?? 0);
    }

    private function meetingExists(int $meetingId): bool
    {
        $stmt = $this->conn->prepare("SELECT meeting_id FROM meetings WHERE meeting_id = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("i", $meetingId);
        if (!$stmt->execute()) {
            return false;
        }
        $result = $stmt->get_result();
        return $result && $result->num_rows > 0;
    }

    public function list()
    {
        $user = $this->requireAuth();
        $this->requireRole($user);

        $userId = (int) $user['user_id'];
        $role = (string) ($user['role'] ?? '');
        $meetingId = filter_var($_GET['meeting_id'] ?? null, FILTER_VALIDATE_INT);

        $baseSql = "
            SELECT mr.meetingrecording_id, mr.meeting_id, mr.file_path, mr.uploaded_at,
                   m.student_id, m.tutor_id, m.meeting_date, m.meeting_time, m.status
            FROM meeting_recordings mr
            JOIN meetings m ON mr.meeting_id = m.meeting_id
        ";

        if ($role === 'student') {
            $studentId = $this->getStudentIdByUserId($userId);
            if ($studentId <= 0) {
                Response::json(["success" => false, "message" => "Student profile not found"], 403);
            }

            if ($meetingId !== false && $meetingId > 0) {
                $sql = $baseSql . " WHERE m.student_id = ? AND mr.meeting_id = ? ORDER BY mr.uploaded_at DESC";
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) {
                    Response::json(["success" => false, "message" => "Failed to fetch meeting recordings"], 500);
                }
                $stmt->bind_param("ii", $studentId, $meetingId);
            } else {
                $sql = $baseSql . " WHERE m.student_id = ? ORDER BY mr.uploaded_at DESC";
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) {
                    Response::json(["success" => false, "message" => "Failed to fetch meeting recordings"], 500);
                }
                $stmt->bind_param("i", $studentId);
            }
        } elseif ($role === 'tutor') {
            $tutorId = $this->getTutorIdByUserId($userId);
            if ($tutorId <= 0) {
                Response::json(["success" => false, "message" => "Tutor profile not found"], 403);
            }

            if ($meetingId !== false && $meetingId > 0) {
                $sql = $baseSql . " WHERE m.tutor_id = ? AND mr.meeting_id = ? ORDER BY mr.uploaded_at DESC";
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) {
                    Response::json(["success" => false, "message" => "Failed to fetch meeting recordings"], 500);
                }
                $stmt->bind_param("ii", $tutorId, $meetingId);
            } else {
                $sql = $baseSql . " WHERE m.tutor_id = ? ORDER BY mr.uploaded_at DESC";
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) {
                    Response::json(["success" => false, "message" => "Failed to fetch meeting recordings"], 500);
                }
                $stmt->bind_param("i", $tutorId);
            }
        } else {
            if ($meetingId !== false && $meetingId > 0) {
                $sql = $baseSql . " WHERE mr.meeting_id = ? ORDER BY mr.uploaded_at DESC";
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) {
                    Response::json(["success" => false, "message" => "Failed to fetch meeting recordings"], 500);
                }
                $stmt->bind_param("i", $meetingId);
            } else {
                $sql = $baseSql . " ORDER BY mr.uploaded_at DESC";
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) {
                    Response::json(["success" => false, "message" => "Failed to fetch meeting recordings"], 500);
                }
            }
        }

        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to fetch meeting recordings"], 500);
        }

        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        $this->safeLogActivity($userId, "Meeting Recording List", "Viewed meeting recordings");
        Response::json(["success" => true, "data" => $rows]);
    }

    public function create()
    {
        $user = $this->requireAuth();
        $this->requireRole($user);

        $userId = (int) $user['user_id'];
        $role = (string) ($user['role'] ?? '');
        $isAdmin = (int) ($user['is_admin'] ?? 0) === 1;

        if ($role === 'student') {
            Response::json(["success" => false, "message" => "Students cannot upload meeting recordings"], 403);
        }

        $data = $this->getRequestData();
        if ($data === null) {
            $data = $_POST;
            if (!is_array($data)) {
                $data = [];
            }
        }

        $meetingId = filter_var($data['meeting_id'] ?? null, FILTER_VALIDATE_INT);
        $filePath = $this->resolveUploadedFilePath('meeting_recordings');
        if ($filePath === '') {
            $filePath = trim((string) ($data['file_path'] ?? ''));
        }

        if ($meetingId === false || $meetingId <= 0 || $filePath === '') {
            Response::json(["success" => false, "message" => "Valid meeting_id and file_path are required"], 400);
        }

        if (mb_strlen($filePath) > 255) {
            Response::json(["success" => false, "message" => "file_path is too long"], 400);
        }

        if (!$this->meetingExists((int) $meetingId)) {
            Response::json(["success" => false, "message" => "Meeting not found"], 404);
        }

        if ($role === 'tutor') {
            $tutorId = $this->getTutorIdByUserId($userId);
            if ($tutorId <= 0) {
                Response::json(["success" => false, "message" => "Tutor profile not found"], 403);
            }

            $stmtCheck = $this->conn->prepare("SELECT meeting_id FROM meetings WHERE meeting_id = ? AND tutor_id = ? LIMIT 1");
            if (!$stmtCheck) {
                Response::json(["success" => false, "message" => "Failed to validate meeting owner"], 500);
            }
            $mid = (int) $meetingId;
            $stmtCheck->bind_param("ii", $mid, $tutorId);
            if (!$stmtCheck->execute()) {
                Response::json(["success" => false, "message" => "Failed to validate meeting owner"], 500);
            }
            $resultCheck = $stmtCheck->get_result();
            if (!$resultCheck || $resultCheck->num_rows === 0) {
                Response::json(["success" => false, "message" => "You can upload only recordings for your own meetings"], 403);
            }
        } elseif ($role === 'staff' && !$isAdmin) {
            Response::json(["success" => false, "message" => "Only admin staff can upload meeting recordings"], 403);
        }

        $stmt = $this->conn->prepare("
            INSERT INTO meeting_recordings (meeting_id, file_path)
            VALUES (?, ?)
        ");
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to prepare meeting recording creation"], 500);
        }

        $mid = (int) $meetingId;
        $stmt->bind_param("is", $mid, $filePath);
        if (!$stmt->execute()) {
            if ((int) $stmt->errno === 1062) {
                Response::json(["success" => false, "message" => "Recording already exists for this meeting"], 409);
            }
            Response::json(["success" => false, "message" => "Failed to create meeting recording"], 500);
        }

        $this->safeLogActivity($userId, "Meeting Recording Create", "Uploaded recording for meeting ID: " . $mid);
        Response::json(["success" => true, "message" => "Meeting recording created"], 201);
    }

    public function update()
    {
        $user = $this->requireAuth();
        $this->requireRole($user);

        $userId = (int) $user['user_id'];
        $role = (string) ($user['role'] ?? '');
        $isAdmin = (int) ($user['is_admin'] ?? 0) === 1;

        if ($role === 'student' || ($role === 'staff' && !$isAdmin)) {
            Response::json(["success" => false, "message" => "Only tutors or admin staff can update meeting recordings"], 403);
        }

        $data = $this->getRequestData();
        if ($data === null) {
            Response::json(["success" => false, "message" => "Invalid JSON body"], 400);
        }

        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
        $meetingId = filter_var($data['meeting_id'] ?? null, FILTER_VALIDATE_INT);
        $filePath = trim((string) ($data['file_path'] ?? ''));

        if ($filePath === '') {
            Response::json(["success" => false, "message" => "file_path is required"], 400);
        }

        if (mb_strlen($filePath) > 255) {
            Response::json(["success" => false, "message" => "file_path is too long"], 400);
        }

        if ($id !== false && $id > 0) {
            if ($role === 'tutor') {
                $tutorId = $this->getTutorIdByUserId($userId);
                if ($tutorId <= 0) {
                    Response::json(["success" => false, "message" => "Tutor profile not found"], 403);
                }

                $stmt = $this->conn->prepare("
                    UPDATE meeting_recordings mr
                    JOIN meetings m ON mr.meeting_id = m.meeting_id
                    SET mr.file_path = ?
                    WHERE mr.meetingrecording_id = ? AND m.tutor_id = ?
                ");
                if (!$stmt) {
                    Response::json(["success" => false, "message" => "Failed to prepare meeting recording update"], 500);
                }
                $rid = (int) $id;
                $stmt->bind_param("sii", $filePath, $rid, $tutorId);
            } else {
                $stmt = $this->conn->prepare("UPDATE meeting_recordings SET file_path = ? WHERE meetingrecording_id = ?");
                if (!$stmt) {
                    Response::json(["success" => false, "message" => "Failed to prepare meeting recording update"], 500);
                }
                $rid = (int) $id;
                $stmt->bind_param("si", $filePath, $rid);
            }
        } elseif ($meetingId !== false && $meetingId > 0) {
            if ($role === 'tutor') {
                $tutorId = $this->getTutorIdByUserId($userId);
                if ($tutorId <= 0) {
                    Response::json(["success" => false, "message" => "Tutor profile not found"], 403);
                }

                $stmt = $this->conn->prepare("
                    UPDATE meeting_recordings mr
                    JOIN meetings m ON mr.meeting_id = m.meeting_id
                    SET mr.file_path = ?
                    WHERE mr.meeting_id = ? AND m.tutor_id = ?
                ");
                if (!$stmt) {
                    Response::json(["success" => false, "message" => "Failed to prepare meeting recording update"], 500);
                }
                $mid = (int) $meetingId;
                $stmt->bind_param("sii", $filePath, $mid, $tutorId);
            } else {
                $stmt = $this->conn->prepare("UPDATE meeting_recordings SET file_path = ? WHERE meeting_id = ?");
                if (!$stmt) {
                    Response::json(["success" => false, "message" => "Failed to prepare meeting recording update"], 500);
                }
                $mid = (int) $meetingId;
                $stmt->bind_param("si", $filePath, $mid);
            }
        } else {
            Response::json(["success" => false, "message" => "Valid id or meeting_id is required"], 400);
        }

        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to update meeting recording"], 500);
        }

        if ($stmt->affected_rows === 0) {
            Response::json(["success" => false, "message" => "Meeting recording not found or no changes applied"], 404);
        }

        $this->safeLogActivity($userId, "Meeting Recording Update", "Updated meeting recording");
        Response::json(["success" => true, "message" => "Meeting recording updated"]);
    }

    public function delete()
    {
        $user = $this->requireAuth();
        $this->requireRole($user);

        $userId = (int) $user['user_id'];
        $role = (string) ($user['role'] ?? '');
        $isAdmin = (int) ($user['is_admin'] ?? 0) === 1;

        if ($role === 'student' || ($role === 'staff' && !$isAdmin)) {
            Response::json(["success" => false, "message" => "Only tutors or admin staff can delete meeting recordings"], 403);
        }

        $data = $this->getRequestData();
        if ($data === null) {
            Response::json(["success" => false, "message" => "Invalid JSON body"], 400);
        }

        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
        $meetingId = filter_var($data['meeting_id'] ?? null, FILTER_VALIDATE_INT);

        if ($id !== false && $id > 0) {
            if ($role === 'tutor') {
                $tutorId = $this->getTutorIdByUserId($userId);
                if ($tutorId <= 0) {
                    Response::json(["success" => false, "message" => "Tutor profile not found"], 403);
                }

                $stmt = $this->conn->prepare("
                    DELETE mr
                    FROM meeting_recordings mr
                    JOIN meetings m ON mr.meeting_id = m.meeting_id
                    WHERE mr.meetingrecording_id = ? AND m.tutor_id = ?
                ");
                if (!$stmt) {
                    Response::json(["success" => false, "message" => "Failed to prepare meeting recording delete"], 500);
                }
                $rid = (int) $id;
                $stmt->bind_param("ii", $rid, $tutorId);
            } else {
                $stmt = $this->conn->prepare("DELETE FROM meeting_recordings WHERE meetingrecording_id = ?");
                if (!$stmt) {
                    Response::json(["success" => false, "message" => "Failed to prepare meeting recording delete"], 500);
                }
                $rid = (int) $id;
                $stmt->bind_param("i", $rid);
            }
        } elseif ($meetingId !== false && $meetingId > 0) {
            if ($role === 'tutor') {
                $tutorId = $this->getTutorIdByUserId($userId);
                if ($tutorId <= 0) {
                    Response::json(["success" => false, "message" => "Tutor profile not found"], 403);
                }

                $stmt = $this->conn->prepare("
                    DELETE mr
                    FROM meeting_recordings mr
                    JOIN meetings m ON mr.meeting_id = m.meeting_id
                    WHERE mr.meeting_id = ? AND m.tutor_id = ?
                ");
                if (!$stmt) {
                    Response::json(["success" => false, "message" => "Failed to prepare meeting recording delete"], 500);
                }
                $mid = (int) $meetingId;
                $stmt->bind_param("ii", $mid, $tutorId);
            } else {
                $stmt = $this->conn->prepare("DELETE FROM meeting_recordings WHERE meeting_id = ?");
                if (!$stmt) {
                    Response::json(["success" => false, "message" => "Failed to prepare meeting recording delete"], 500);
                }
                $mid = (int) $meetingId;
                $stmt->bind_param("i", $mid);
            }
        } else {
            Response::json(["success" => false, "message" => "Valid id or meeting_id is required"], 400);
        }

        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to delete meeting recording"], 500);
        }

        if ($stmt->affected_rows === 0) {
            Response::json(["success" => false, "message" => "Meeting recording not found"], 404);
        }

        $this->safeLogActivity($userId, "Meeting Recording Delete", "Deleted meeting recording");
        Response::json(["success" => true, "message" => "Meeting recording deleted"]);
    }
}
