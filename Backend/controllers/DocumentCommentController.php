<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/activityMiddleware.php';
require_once __DIR__ . '/../services/PermissionService.php';

class DocumentCommentController
{
    private $conn;
    private const VIEW_ROLES = ['student', 'tutor', 'staff'];
    private ?string $commentIdColumn = null;

    public function __construct()
    {
        global $conn;
        $this->conn = $conn;
    }

    private function requireAuth(): array
    {
        return authUser();
    }

    private function requireViewRole(array $user): void
    {
        requireRoles(self::VIEW_ROLES);
    }

    private function getRequestData()
    {
        $data = json_decode(file_get_contents("php://input"), true);
        return is_array($data) ? $data : null;
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

    private function documentExists(int $documentId): bool
    {
        $stmt = $this->conn->prepare("SELECT document_id FROM documents WHERE document_id = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("i", $documentId);
        if (!$stmt->execute()) {
            return false;
        }
        $result = $stmt->get_result();
        return $result && $result->num_rows > 0;
    }

    private function getCommentIdColumn(): string
    {
        if ($this->commentIdColumn !== null) {
            return $this->commentIdColumn;
        }

        $default = 'doccumentcomment_id';
        $fallback = 'documentcomment_id';
        $result = $this->conn->query("SHOW COLUMNS FROM document_comments");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $field = (string) ($row['Field'] ?? '');
                if ($field === $default || $field === $fallback) {
                    $this->commentIdColumn = $field;
                    return $this->commentIdColumn;
                }
            }
        }

        $this->commentIdColumn = $default;
        return $this->commentIdColumn;
    }

    public function list()
    {
        $user = $this->requireAuth();
        $this->requireViewRole($user);

        $userId = (int) $user['user_id'];
        $role = (string) ($user['role'] ?? '');
        $isAdmin = (int) ($user['is_admin'] ?? 0) === 1;
        $documentId = filter_var($_GET['document_id'] ?? null, FILTER_VALIDATE_INT);
        $commentIdColumn = $this->getCommentIdColumn();

        $baseSql = "
            SELECT dc.{$commentIdColumn} AS document_comment_id, dc.document_id, dc.tutor_id, t.user_id AS tutor_user_id,
                   tu.user_name AS tutor_user_name, dc.comment, dc.created_at
            FROM document_comments dc
            JOIN tutors t ON dc.tutor_id = t.tutor_id
            JOIN users tu ON t.user_id = tu.user_id
            JOIN documents d ON dc.document_id = d.document_id
        ";

        if ($role === 'student') {
            $studentId = $this->getStudentIdByUserId($userId);
            if ($studentId <= 0) {
                Response::json(["success" => false, "message" => "Student profile not found"], 403);
            }

            if ($documentId !== false && $documentId > 0) {
                $sql = $baseSql . " WHERE d.student_id = ? AND dc.document_id = ? ORDER BY dc.created_at DESC";
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) {
                    Response::json(["success" => false, "message" => "Failed to fetch document comments"], 500);
                }
                $stmt->bind_param("ii", $studentId, $documentId);
            } else {
                $sql = $baseSql . " WHERE d.student_id = ? ORDER BY dc.created_at DESC";
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) {
                    Response::json(["success" => false, "message" => "Failed to fetch document comments"], 500);
                }
                $stmt->bind_param("i", $studentId);
            }
        } elseif ($role === 'tutor') {
            $tutorId = $this->getTutorIdByUserId($userId);
            if ($tutorId <= 0) {
                Response::json(["success" => false, "message" => "Tutor profile not found"], 403);
            }

            if ($documentId !== false && $documentId > 0) {
                $sql = $baseSql . " WHERE dc.tutor_id = ? AND dc.document_id = ? ORDER BY dc.created_at DESC";
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) {
                    Response::json(["success" => false, "message" => "Failed to fetch document comments"], 500);
                }
                $stmt->bind_param("ii", $tutorId, $documentId);
            } else {
                $sql = $baseSql . " WHERE dc.tutor_id = ? ORDER BY dc.created_at DESC";
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) {
                    Response::json(["success" => false, "message" => "Failed to fetch document comments"], 500);
                }
                $stmt->bind_param("i", $tutorId);
            }
        } else {
            if ($documentId !== false && $documentId > 0) {
                $sql = $baseSql . " WHERE dc.document_id = ? ORDER BY dc.created_at DESC";
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) {
                    Response::json(["success" => false, "message" => "Failed to fetch document comments"], 500);
                }
                $stmt->bind_param("i", $documentId);
            } else {
                $sql = $baseSql . " ORDER BY dc.created_at DESC";
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) {
                    Response::json(["success" => false, "message" => "Failed to fetch document comments"], 500);
                }
            }

            if (!$isAdmin && $role === 'staff') {
                // Non-admin staff can still view; no row-level restriction in current schema.
            }
        }

        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to fetch document comments"], 500);
        }

        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        $this->safeLogActivity($userId, "Document Comment List", "Viewed document comments");
        Response::json(["success" => true, "data" => $rows]);
    }

    public function create()
    {
        $user = $this->requireAuth();
        $role = (string) ($user['role'] ?? '');
        $userId = (int) $user['user_id'];

        if ($role !== 'tutor') {
            Response::json(["success" => false, "message" => "Only tutors can comment on documents"], 403);
        }

        $data = $this->getRequestData();
        if ($data === null) {
            Response::json(["success" => false, "message" => "Invalid JSON body"], 400);
        }

        $documentId = filter_var($data['document_id'] ?? null, FILTER_VALIDATE_INT);
        $comment = trim((string) ($data['comment'] ?? ''));
        if ($documentId === false || $documentId <= 0 || $comment === '') {
            Response::json(["success" => false, "message" => "Valid document_id and comment are required"], 400);
        }

        if (!$this->documentExists((int) $documentId)) {
            Response::json(["success" => false, "message" => "Document not found"], 404);
        }

        if (mb_strlen($comment) > 5000) {
            Response::json(["success" => false, "message" => "Comment is too long"], 400);
        }

        $tutorId = $this->getTutorIdByUserId($userId);

        if ($tutorId <= 0) {
            Response::json(["success" => false, "message" => "Valid tutor_id is required"], 400);
        }

        $stmt = $this->conn->prepare("
            INSERT INTO document_comments (document_id, tutor_id, comment)
            VALUES (?, ?, ?)
        ");
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to prepare document comment creation"], 500);
        }

        $did = (int) $documentId;
        $stmt->bind_param("iis", $did, $tutorId, $comment);
        if (!$stmt->execute()) {
            if ((int) $stmt->errno === 1062) {
                Response::json(["success" => false, "message" => "Comment already exists for this document"], 409);
            }
            Response::json(["success" => false, "message" => "Failed to create document comment"], 500);
        }

        $this->safeLogActivity($userId, "Document Comment Create", "Created comment for document ID: " . $did);
        Response::json(["success" => true, "message" => "Document comment created"], 201);
    }

    public function update()
    {
        $user = $this->requireAuth();
        $role = (string) ($user['role'] ?? '');
        $userId = (int) $user['user_id'];

        if ($role !== 'tutor') {
            Response::json(["success" => false, "message" => "Only tutors can update document comments"], 403);
        }

        $tutorId = $this->getTutorIdByUserId($userId);
        if ($tutorId <= 0) {
            Response::json(["success" => false, "message" => "Tutor profile not found"], 403);
        }

        $data = $this->getRequestData();
        if ($data === null) {
            Response::json(["success" => false, "message" => "Invalid JSON body"], 400);
        }

        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
        $documentId = filter_var($data['document_id'] ?? null, FILTER_VALIDATE_INT);
        $comment = trim((string) ($data['comment'] ?? ''));
        if ($comment === '') {
            Response::json(["success" => false, "message" => "comment is required"], 400);
        }

        if ($id !== false && $id > 0) {
            $commentIdColumn = $this->getCommentIdColumn();
            $stmt = $this->conn->prepare("
                UPDATE document_comments
                SET comment = ?
                WHERE {$commentIdColumn} = ? AND tutor_id = ?
            ");
            if (!$stmt) {
                Response::json(["success" => false, "message" => "Failed to prepare document comment update"], 500);
            }
            $cid = (int) $id;
            $stmt->bind_param("sii", $comment, $cid, $tutorId);
        } elseif ($documentId !== false && $documentId > 0) {
            $stmt = $this->conn->prepare("
                UPDATE document_comments
                SET comment = ?
                WHERE document_id = ? AND tutor_id = ?
            ");
            if (!$stmt) {
                Response::json(["success" => false, "message" => "Failed to prepare document comment update"], 500);
            }
            $did = (int) $documentId;
            $stmt->bind_param("sii", $comment, $did, $tutorId);
        } else {
            Response::json(["success" => false, "message" => "Valid id or document_id is required"], 400);
        }

        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to update document comment"], 500);
        }

        if ($stmt->affected_rows === 0) {
            Response::json(["success" => false, "message" => "Document comment not found or no changes applied"], 404);
        }

        $this->safeLogActivity($userId, "Document Comment Update", "Updated document comment");
        Response::json(["success" => true, "message" => "Document comment updated"]);
    }

    public function delete()
    {
        $user = $this->requireAuth();
        $role = (string) ($user['role'] ?? '');
        $userId = (int) $user['user_id'];

        if ($role !== 'tutor') {
            Response::json(["success" => false, "message" => "Only tutors can delete document comments"], 403);
        }

        $tutorId = $this->getTutorIdByUserId($userId);
        if ($tutorId <= 0) {
            Response::json(["success" => false, "message" => "Tutor profile not found"], 403);
        }

        $data = $this->getRequestData();
        if ($data === null) {
            Response::json(["success" => false, "message" => "Invalid JSON body"], 400);
        }

        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
        $documentId = filter_var($data['document_id'] ?? null, FILTER_VALIDATE_INT);

        if ($id !== false && $id > 0) {
            $commentIdColumn = $this->getCommentIdColumn();
            $stmt = $this->conn->prepare("
                DELETE FROM document_comments
                WHERE {$commentIdColumn} = ? AND tutor_id = ?
            ");
            if (!$stmt) {
                Response::json(["success" => false, "message" => "Failed to prepare document comment delete"], 500);
            }
            $cid = (int) $id;
            $stmt->bind_param("ii", $cid, $tutorId);
        } elseif ($documentId !== false && $documentId > 0) {
            $stmt = $this->conn->prepare("
                DELETE FROM document_comments
                WHERE document_id = ? AND tutor_id = ?
            ");
            if (!$stmt) {
                Response::json(["success" => false, "message" => "Failed to prepare document comment delete"], 500);
            }
            $did = (int) $documentId;
            $stmt->bind_param("ii", $did, $tutorId);
        } else {
            Response::json(["success" => false, "message" => "Valid id or document_id is required"], 400);
        }

        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to delete document comment"], 500);
        }

        if ($stmt->affected_rows === 0) {
            Response::json(["success" => false, "message" => "Document comment not found"], 404);
        }

        $this->safeLogActivity($userId, "Document Comment Delete", "Deleted document comment");
        Response::json(["success" => true, "message" => "Document comment deleted"]);
    }
}
