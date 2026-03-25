<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/activityMiddleware.php';

class BlogCommentController
{
    private $conn;
    private const WRITE_ROLES = ['student', 'tutor'];

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

    private function requireReadRole(array $user): void
    {
        $role = (string) ($user['role'] ?? '');
        $isAdmin = !empty($user['is_admin']);

        if (in_array($role, self::WRITE_ROLES, true)) {
            return;
        }

        if ($role === 'staff' && $isAdmin) {
            return;
        }

        Response::json(["success" => false, "message" => "Access denied"], 403);
    }

    private function requireWriteRole(array $user): void
    {
        $role = (string) ($user['role'] ?? '');
        if (!in_array($role, self::WRITE_ROLES, true)) {
            Response::json(["success" => false, "message" => "Access denied"], 403);
        }
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

    private function hasColumn(string $table, string $column): bool
    {
        $tableEscaped = $this->conn->real_escape_string($table);
        $columnEscaped = $this->conn->real_escape_string($column);
        $result = $this->conn->query("SHOW COLUMNS FROM `{$tableEscaped}` LIKE '{$columnEscaped}'");
        return $result && $result->num_rows > 0;
    }

    private function blogPostExists(int $postId): bool
    {
        $stmt = $this->conn->prepare("SELECT blog_id FROM blog_posts WHERE blog_id = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("i", $postId);
        if (!$stmt->execute()) {
            return false;
        }
        $result = $stmt->get_result();
        return $result && $result->num_rows > 0;
    }

    public function list()
    {
        $user = $this->requireAuth();
        $this->requireReadRole($user);
        $userId = (int) $user['user_id'];
        $profilePhotoSelect = $this->hasColumn('users', 'profile_photo')
            ? "u.profile_photo AS profile_photo"
            : "NULL AS profile_photo";

        $postId = filter_var($_GET['post_id'] ?? null, FILTER_VALIDATE_INT);
        if ($postId !== false && $postId > 0) {
            $stmt = $this->conn->prepare("
                SELECT
                    c.blogcomment_id,
                    c.post_id,
                    c.user_id,
                    u.user_name,
                    COALESCE(
                        NULLIF(s.full_name, ''),
                        NULLIF(t.full_name, ''),
                        NULLIF(sf.full_name, ''),
                        u.user_name
                    ) AS display_name,
                    {$profilePhotoSelect},
                    c.comment,
                    c.created_at
                FROM blog_comments c
                JOIN users u ON c.user_id = u.user_id
                LEFT JOIN students s ON s.user_id = u.user_id
                LEFT JOIN tutors t ON t.user_id = u.user_id
                LEFT JOIN staff sf ON sf.user_id = u.user_id
                WHERE c.post_id = ?
                ORDER BY c.created_at DESC
            ");
            if (!$stmt) {
                Response::json(["success" => false, "message" => "Failed to fetch comments"], 500);
            }
            $stmt->bind_param("i", $postId);
        } else {
            $stmt = $this->conn->prepare("
                SELECT
                    c.blogcomment_id,
                    c.post_id,
                    c.user_id,
                    u.user_name,
                    COALESCE(
                        NULLIF(s.full_name, ''),
                        NULLIF(t.full_name, ''),
                        NULLIF(sf.full_name, ''),
                        u.user_name
                    ) AS display_name,
                    {$profilePhotoSelect},
                    c.comment,
                    c.created_at
                FROM blog_comments c
                JOIN users u ON c.user_id = u.user_id
                LEFT JOIN students s ON s.user_id = u.user_id
                LEFT JOIN tutors t ON t.user_id = u.user_id
                LEFT JOIN staff sf ON sf.user_id = u.user_id
                ORDER BY c.created_at DESC
                LIMIT 200
            ");
            if (!$stmt) {
                Response::json(["success" => false, "message" => "Failed to fetch comments"], 500);
            }
        }

        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to fetch comments"], 500);
        }

        $result = $stmt->get_result();
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }

        $this->safeLogActivity($userId, "Blog Comment List", "Viewed blog comments");
        Response::json(["success" => true, "data" => $data]);
    }

    public function create()
    {
        $user = $this->requireAuth();
        $this->requireWriteRole($user);
        $userId = (int) $user['user_id'];

        $data = $this->getRequestData();
        if ($data === null) {
            Response::json(["success" => false, "message" => "Invalid JSON body"], 400);
        }

        $postId = filter_var($data['post_id'] ?? null, FILTER_VALIDATE_INT);
        $comment = trim((string) ($data['comment'] ?? ''));

        if ($postId === false || $postId <= 0 || $comment === '') {
            Response::json(["success" => false, "message" => "Valid post_id and comment are required"], 400);
        }

        if (!$this->blogPostExists((int) $postId)) {
            Response::json(["success" => false, "message" => "Blog post not found"], 404);
        }

        if (mb_strlen($comment) > 5000) {
            Response::json(["success" => false, "message" => "Comment is too long"], 400);
        }

        $stmt = $this->conn->prepare("
            INSERT INTO blog_comments (post_id, user_id, comment)
            VALUES (?, ?, ?)
        ");
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to prepare blog comment"], 500);
        }

        $pid = (int) $postId;
        $stmt->bind_param("iis", $pid, $userId, $comment);
        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to create blog comment"], 500);
        }

        $this->safeLogActivity($userId, "Blog Comment Create", "Added comment to blog post ID: " . $pid);
        Response::json(["success" => true, "message" => "Blog comment created"], 201);
    }

    public function update()
    {
        $user = $this->requireAuth();
        $this->requireWriteRole($user);
        $userId = (int) $user['user_id'];

        $data = $this->getRequestData();
        if ($data === null) {
            Response::json(["success" => false, "message" => "Invalid JSON body"], 400);
        }

        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
        $comment = trim((string) ($data['comment'] ?? ''));

        if ($id === false || $id <= 0 || $comment === '') {
            Response::json(["success" => false, "message" => "Valid id and comment are required"], 400);
        }

        $stmt = $this->conn->prepare("
            UPDATE blog_comments
            SET comment = ?
            WHERE blogcomment_id = ? AND user_id = ?
        ");
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to prepare blog comment update"], 500);
        }

        $cid = (int) $id;
        $stmt->bind_param("sii", $comment, $cid, $userId);
        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to update blog comment"], 500);
        }

        if ($stmt->affected_rows === 0) {
            Response::json(["success" => false, "message" => "Comment not found or no changes applied"], 404);
        }

        $this->safeLogActivity($userId, "Blog Comment Update", "Updated blog comment ID: " . $cid);
        Response::json(["success" => true, "message" => "Blog comment updated"]);
    }

    public function delete()
    {
        $user = $this->requireAuth();
        $this->requireWriteRole($user);
        $userId = (int) $user['user_id'];

        $data = $this->getRequestData();
        if ($data === null) {
            Response::json(["success" => false, "message" => "Invalid JSON body"], 400);
        }

        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            Response::json(["success" => false, "message" => "Valid ID required"], 400);
        }

        $stmt = $this->conn->prepare("
            DELETE FROM blog_comments
            WHERE blogcomment_id = ? AND user_id = ?
        ");
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to prepare blog comment delete"], 500);
        }

        $cid = (int) $id;
        $stmt->bind_param("ii", $cid, $userId);
        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to delete blog comment"], 500);
        }

        if ($stmt->affected_rows === 0) {
            Response::json(["success" => false, "message" => "Comment not found"], 404);
        }

        $this->safeLogActivity($userId, "Blog Comment Delete", "Deleted blog comment ID: " . $cid);
        Response::json(["success" => true, "message" => "Blog comment deleted"]);
    }
}
