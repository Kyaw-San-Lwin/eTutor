<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/activityMiddleware.php';
require_once __DIR__ . '/../services/PermissionService.php';
require_once __DIR__ . '/../services/ValidationService.php';

class DocumentController
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

    private function requireDocumentRole(array $user): void
    {
        requireRoles(self::ALLOWED_ROLES);
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
        if ($size > 10 * 1024 * 1024) {
            Response::json(["success" => false, "message" => "File too large (max 10MB)"], 400);
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = ['pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png'];
        if (!in_array($ext, $allowed, true)) {
            Response::json(["success" => false, "message" => "Unsupported file type"], 400);
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

    public function list()
    {
        $user = $this->requireAuth();
        $this->requireDocumentRole($user);
        $role = (string) ($user['role'] ?? '');
        $isAdmin = !empty($user['is_admin']);
        $userId = (int) ($user['user_id'] ?? 0);
        $pagination = ValidationService::paginationFromQuery(20, 100);
        $dateFrom = trim((string) ($_GET['date_from'] ?? ''));
        $dateTo = trim((string) ($_GET['date_to'] ?? ''));
        $q = trim((string) ($_GET['q'] ?? ''));

        if ($role === 'student') {
            $studentId = $this->getStudentIdByUserId($userId);
            if ($studentId <= 0) {
                Response::json(["success" => false, "message" => "Student profile not found"], 404);
                return;
            }
            $where = " WHERE student_id = ? ";
            if ($this->hasColumn('documents', 'deleted_at')) {
                $where .= " AND deleted_at IS NULL ";
            }
            if ($dateFrom !== '') {
                $where .= " AND DATE(uploaded_at) >= ? ";
            }
            if ($dateTo !== '') {
                $where .= " AND DATE(uploaded_at) <= ? ";
            }
            if ($q !== '') {
                $where .= " AND file_path LIKE ? ";
            }

            $stmt = $this->conn->prepare("
                SELECT document_id, student_id, file_path, uploaded_at
                FROM documents
                {$where}
                ORDER BY uploaded_at DESC
                LIMIT ? OFFSET ?
            ");
            if (!$stmt) {
                Response::json(["success" => false, "message" => "Failed to fetch documents"], 500);
                return;
            }
            $types = "i";
            $params = [$studentId];
            if ($dateFrom !== '') {
                $types .= "s";
                $params[] = $dateFrom;
            }
            if ($dateTo !== '') {
                $types .= "s";
                $params[] = $dateTo;
            }
            if ($q !== '') {
                $types .= "s";
                $params[] = '%' . $q . '%';
            }
            $types .= "ii";
            $params[] = $pagination['limit'];
            $params[] = $pagination['offset'];
            $stmt->bind_param($types, ...$params);
            if (!$stmt->execute()) {
                Response::json(["success" => false, "message" => "Failed to fetch documents"], 500);
                return;
            }
            $result = $stmt->get_result();
        } elseif ($role === 'tutor') {
            $tutorId = $this->getTutorIdByUserId($userId);
            if ($tutorId <= 0) {
                Response::json(["success" => false, "message" => "Tutor profile not found"], 404);
                return;
            }
            $where = " WHERE a.tutor_id = ? AND a.status = 'active' ";
            if ($this->hasColumn('documents', 'deleted_at')) {
                $where .= " AND d.deleted_at IS NULL ";
            }
            if ($dateFrom !== '') {
                $where .= " AND DATE(d.uploaded_at) >= ? ";
            }
            if ($dateTo !== '') {
                $where .= " AND DATE(d.uploaded_at) <= ? ";
            }
            if ($q !== '') {
                $where .= " AND d.file_path LIKE ? ";
            }

            $stmt = $this->conn->prepare("
                SELECT d.document_id, d.student_id, d.file_path, d.uploaded_at
                FROM documents d
                JOIN allocations a ON a.student_id = d.student_id
                {$where}
                ORDER BY d.uploaded_at DESC
                LIMIT ? OFFSET ?
            ");
            if (!$stmt) {
                Response::json(["success" => false, "message" => "Failed to fetch documents"], 500);
                return;
            }
            $types = "i";
            $params = [$tutorId];
            if ($dateFrom !== '') {
                $types .= "s";
                $params[] = $dateFrom;
            }
            if ($dateTo !== '') {
                $types .= "s";
                $params[] = $dateTo;
            }
            if ($q !== '') {
                $types .= "s";
                $params[] = '%' . $q . '%';
            }
            $types .= "ii";
            $params[] = $pagination['limit'];
            $params[] = $pagination['offset'];
            $stmt->bind_param($types, ...$params);
            if (!$stmt->execute()) {
                Response::json(["success" => false, "message" => "Failed to fetch documents"], 500);
                return;
            }
            $result = $stmt->get_result();
        } else {
            if (!$isAdmin) {
                Response::json(["success" => false, "message" => "Admin only access"], 403);
                return;
            }
            $where = [];
            if ($this->hasColumn('documents', 'deleted_at')) {
                $where[] = "deleted_at IS NULL";
            }
            $studentFilter = filter_var($_GET['student_id'] ?? null, FILTER_VALIDATE_INT);
            $types = '';
            $params = [];
            if ($studentFilter !== false && $studentFilter > 0) {
                $where[] = "student_id = ?";
                $types .= "i";
                $params[] = (int) $studentFilter;
            }
            if ($dateFrom !== '') {
                $where[] = "DATE(uploaded_at) >= ?";
                $types .= "s";
                $params[] = $dateFrom;
            }
            if ($dateTo !== '') {
                $where[] = "DATE(uploaded_at) <= ?";
                $types .= "s";
                $params[] = $dateTo;
            }
            if ($q !== '') {
                $where[] = "file_path LIKE ?";
                $types .= "s";
                $params[] = '%' . $q . '%';
            }
            $whereSql = count($where) > 0 ? (' WHERE ' . implode(' AND ', $where)) : '';
            $stmt = $this->conn->prepare("
                SELECT document_id, student_id, file_path, uploaded_at
                FROM documents
                {$whereSql}
                ORDER BY uploaded_at DESC
                LIMIT ? OFFSET ?
            ");
            if (!$stmt) {
                Response::json(["success" => false, "message" => "Failed to fetch documents"], 500);
                return;
            }
            $types .= "ii";
            $params[] = $pagination['limit'];
            $params[] = $pagination['offset'];
            $stmt->bind_param($types, ...$params);
            if (!$stmt->execute()) {
                Response::json(["success" => false, "message" => "Failed to fetch documents"], 500);
                return;
            }
            $result = $stmt->get_result();
        }

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }

        $this->safeLogActivity("Document List", "Fetched all documents");

        Response::json(["success" => true, "data" => $data, "meta" => $pagination]);
    }

    public function create()
    {
        $user = $this->requireAuth();
        $this->requireDocumentRole($user);
        $role = (string) ($user['role'] ?? '');
        $isAdmin = !empty($user['is_admin']);
        $userId = (int) ($user['user_id'] ?? 0);

        $data = $this->getRequestData();
        if ($data === null) {
            $data = $_POST;
            if (!is_array($data)) {
                $data = [];
            }
        }

        $studentId = filter_var($data['student_id'] ?? null, FILTER_VALIDATE_INT);
        if ($role === 'student') {
            $studentId = $this->getStudentIdByUserId($userId);
        } elseif (!($role === 'staff' && $isAdmin)) {
            Response::json(["success" => false, "message" => "Only students or admin staff can upload documents"], 403);
            return;
        }

        $filePath = $this->resolveUploadedFilePath('documents');
        if ($filePath === '') {
            $filePath = trim((string) ($data['file_path'] ?? ''));
        }
        if ($studentId === false || $studentId <= 0 || $filePath === '') {
            Response::json(["success" => false, "message" => "Valid student_id and file_path (or uploaded file) required"], 400);
            return;
        }
        $filePath = ValidationService::sanitizeString($filePath, 255);

        $stmt = $this->conn->prepare(
            "INSERT INTO documents (student_id, file_path) VALUES (?, ?)"
        );
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to prepare document creation"], 500);
            return;
        }

        $stmt->bind_param("is", $studentId, $filePath);
        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to create document"], 500);
            return;
        }

        $this->safeLogActivity("Document Create", "Created document for student ID: " . $studentId);

        Response::json(["success" => true, "message" => "Document created"], 201);
    }

    public function update()
    {
        $user = $this->requireAuth();
        $isAdmin = !empty($user['is_admin']);
        if (!$isAdmin) {
            Response::json(["success" => false, "message" => "Admin only access"], 403);
            return;
        }

        $data = $this->getRequestData();
        if ($data === null) {
            Response::json(["success" => false, "message" => "Invalid JSON body"], 400);
            return;
        }

        ValidationService::requireFields($data, ['id', 'student_id', 'file_path']);
        $id = ValidationService::intField($data['id'], 'id');
        $studentId = ValidationService::intField($data['student_id'], 'student_id');
        $filePath = ValidationService::sanitizeString($data['file_path'], 255);

        $stmt = $this->conn->prepare(
            "UPDATE documents SET student_id=?, file_path=? WHERE document_id=?"
        );
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to prepare document update"], 500);
            return;
        }

        $stmt->bind_param("isi", $studentId, $filePath, $id);
        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to update document"], 500);
            return;
        }

        if ($stmt->affected_rows === 0) {
            Response::json(["success" => false, "message" => "Document not found or no changes applied"], 404);
            return;
        }

        $this->safeLogActivity("Document Update", "Updated document ID: " . $id);

        Response::json(["success" => true, "message" => "Document updated"]);
    }

    public function delete()
    {
        $user = $this->requireAuth();
        $isAdmin = !empty($user['is_admin']);
        if (!$isAdmin) {
            Response::json(["success" => false, "message" => "Admin only access"], 403);
            return;
        }

        $data = $this->getRequestData();
        if ($data === null) {
            Response::json(["success" => false, "message" => "Invalid JSON body"], 400);
            return;
        }

        ValidationService::requireFields($data, ['id']);
        $id = ValidationService::intField($data['id'], 'id');

        if ($this->hasColumn('documents', 'deleted_at')) {
            $stmt = $this->conn->prepare("UPDATE documents SET deleted_at = NOW() WHERE document_id=? AND deleted_at IS NULL");
        } else {
            $stmt = $this->conn->prepare("DELETE FROM documents WHERE document_id=?");
        }
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to prepare document deletion"], 500);
            return;
        }

        $stmt->bind_param("i", $id);
        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to delete document"], 500);
            return;
        }

        if ($stmt->affected_rows === 0) {
            Response::json(["success" => false, "message" => "Document not found"], 404);
            return;
        }

        $this->safeLogActivity("Document Delete", "Deleted document ID: " . $id);

        Response::json(["success" => true, "message" => "Document deleted"]);
    }
}
