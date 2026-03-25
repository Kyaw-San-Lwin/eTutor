<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/activityMiddleware.php';
require_once __DIR__ . '/../services/ValidationService.php';

class BlogController
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

    private function requireBlogReadRole(array $user): void
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

    private function requireBlogWriteRole(array $user): void
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

    private function safeLogActivity(string $page, string $activity): void
    {
        if (!isset($GLOBALS['auth_user']['user_id'])) {
            return;
        }

        logActivity($this->conn, (int) $GLOBALS['auth_user']['user_id'], $page, $activity);
    }

    private function hasColumn(string $table, string $column): bool
    {
        $tableEscaped = $this->conn->real_escape_string($table);
        $columnEscaped = $this->conn->real_escape_string($column);
        $sql = "SHOW COLUMNS FROM `{$tableEscaped}` LIKE '{$columnEscaped}'";
        $result = $this->conn->query($sql);
        return $result && $result->num_rows > 0;
    }

    private function bindParams(mysqli_stmt $stmt, string $types, array &$params): void
    {
        $refs = [];
        $refs[] = &$types;
        foreach ($params as &$param) {
            $refs[] = &$param;
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }

    public function list()
    {
        $user = $this->requireAuth();
        $this->requireBlogReadRole($user);

        $pagination = ValidationService::paginationFromQuery(20, 100);
        $authorId = filter_var($_GET['author_id'] ?? null, FILTER_VALIDATE_INT);
        $dateFrom = trim((string) ($_GET['date_from'] ?? ''));
        $dateTo = trim((string) ($_GET['date_to'] ?? ''));
        $q = trim((string) ($_GET['q'] ?? ''));

        $where = [];
        $types = '';
        $params = [];

        if ($this->hasColumn('blog_posts', 'deleted_at')) {
            $where[] = "p.deleted_at IS NULL";
        }
        if ($authorId !== false && $authorId > 0) {
            $where[] = "p.user_id = ?";
            $types .= 'i';
            $params[] = (int) $authorId;
        }
        if ($dateFrom !== '') {
            $where[] = "DATE(p.created_at) >= ?";
            $types .= 's';
            $params[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where[] = "DATE(p.created_at) <= ?";
            $types .= 's';
            $params[] = $dateTo;
        }
        if ($q !== '') {
            $where[] = "(p.title LIKE ? OR p.content LIKE ?)";
            $types .= 'ss';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $whereSql = count($where) > 0 ? (' WHERE ' . implode(' AND ', $where)) : '';

        $sql = "
            SELECT
                p.blog_id,
                p.user_id,
                u.user_name,
                COALESCE(
                    NULLIF(s.full_name, ''),
                    NULLIF(t.full_name, ''),
                    NULLIF(sf.full_name, ''),
                    u.user_name
                ) AS display_name,
                u.email,
                p.title,
                p.content,
                p.created_at
            FROM blog_posts p
            JOIN users u ON p.user_id = u.user_id
            LEFT JOIN students s ON s.user_id = u.user_id
            LEFT JOIN tutors t ON t.user_id = u.user_id
            LEFT JOIN staff sf ON sf.user_id = u.user_id
            {$whereSql}
            ORDER BY p.created_at DESC
            LIMIT ? OFFSET ?
        ";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to fetch blogs"], 500);
        }

        $types .= 'ii';
        $params[] = $pagination['limit'];
        $params[] = $pagination['offset'];
        $this->bindParams($stmt, $types, $params);
        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to fetch blogs"], 500);
        }

        $result = $stmt->get_result();
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }

        $this->safeLogActivity("Blog List", "Fetched all blogs");
        Response::json([
            "success" => true,
            "data" => $data,
            "meta" => $pagination
        ]);
    }

    public function create()
    {
        $user = $this->requireAuth();
        $this->requireBlogWriteRole($user);

        $data = $this->getRequestData();
        if ($data === null) {
            Response::json(["success" => false, "message" => "Invalid JSON body"], 400);
            return;
        }

        ValidationService::requireFields($data, ['title', 'content']);
        $title = ValidationService::sanitizeString($data['title'], 255);
        $content = ValidationService::sanitizeString($data['content'], 10000);
        $userId = (int) ($GLOBALS['auth_user']['user_id'] ?? 0);
        if ($userId <= 0) {
            Response::json(["success" => false, "message" => "Invalid authenticated user"], 401);
            return;
        }

        $stmt = $this->conn->prepare("INSERT INTO blog_posts (user_id, title, content) VALUES (?, ?, ?)");
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to prepare blog creation"], 500);
            return;
        }

        $stmt->bind_param("iss", $userId, $title, $content);
        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to create blog"], 500);
            return;
        }

        $this->safeLogActivity("Blog Create", "Created blog: " . $title);
        Response::json(["success" => true, "message" => "Blog created"], 201);
    }

    public function update()
    {
        $user = $this->requireAuth();
        $this->requireBlogWriteRole($user);
        $authUserId = (int) ($user['user_id'] ?? 0);
        $isAdmin = false;

        $data = $this->getRequestData();
        if ($data === null) {
            Response::json(["success" => false, "message" => "Invalid JSON body"], 400);
            return;
        }

        ValidationService::requireFields($data, ['id', 'title', 'content']);
        $id = ValidationService::intField($data['id'], 'id');
        $title = ValidationService::sanitizeString($data['title'], 255);
        $content = ValidationService::sanitizeString($data['content'], 10000);

        if (!$isAdmin) {
            $ownerSql = "SELECT user_id FROM blog_posts WHERE blog_id = ?";
            if ($this->hasColumn('blog_posts', 'deleted_at')) {
                $ownerSql .= " AND deleted_at IS NULL";
            }
            $ownerSql .= " LIMIT 1";
            $ownerStmt = $this->conn->prepare($ownerSql);
            if (!$ownerStmt) {
                Response::json(["success" => false, "message" => "Failed to validate blog owner"], 500);
                return;
            }
            $ownerStmt->bind_param("i", $id);
            if (!$ownerStmt->execute()) {
                Response::json(["success" => false, "message" => "Failed to validate blog owner"], 500);
                return;
            }
            $ownerRow = $ownerStmt->get_result()->fetch_assoc();
            if (!$ownerRow) {
                Response::json(["success" => false, "message" => "Blog not found"], 404);
                return;
            }
            if ((int) $ownerRow['user_id'] !== $authUserId) {
                Response::json(["success" => false, "message" => "You can only update your own blog posts"], 403);
                return;
            }
        }

        $updateSql = "UPDATE blog_posts SET title=?, content=? WHERE blog_id=?";
        if ($this->hasColumn('blog_posts', 'deleted_at')) {
            $updateSql .= " AND deleted_at IS NULL";
        }
        $stmt = $this->conn->prepare($updateSql);
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to prepare blog update"], 500);
            return;
        }

        $stmt->bind_param("ssi", $title, $content, $id);
        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to update blog"], 500);
            return;
        }

        if ($stmt->affected_rows === 0) {
            Response::json(["success" => false, "message" => "Blog not found or no changes applied"], 404);
            return;
        }

        $this->safeLogActivity("Blog Update", "Updated blog ID: " . $id);
        Response::json(["success" => true, "message" => "Blog updated"]);
    }

    public function delete()
    {
        $user = $this->requireAuth();
        $this->requireBlogWriteRole($user);
        $authUserId = (int) ($user['user_id'] ?? 0);
        $isAdmin = false;

        $data = $this->getRequestData();
        if ($data === null) {
            Response::json(["success" => false, "message" => "Invalid JSON body"], 400);
            return;
        }

        ValidationService::requireFields($data, ['id']);
        $id = ValidationService::intField($data['id'], 'id');

        if (!$isAdmin) {
            $ownerSql = "SELECT user_id FROM blog_posts WHERE blog_id = ?";
            if ($this->hasColumn('blog_posts', 'deleted_at')) {
                $ownerSql .= " AND deleted_at IS NULL";
            }
            $ownerSql .= " LIMIT 1";
            $ownerStmt = $this->conn->prepare($ownerSql);
            if (!$ownerStmt) {
                Response::json(["success" => false, "message" => "Failed to validate blog owner"], 500);
                return;
            }
            $ownerStmt->bind_param("i", $id);
            if (!$ownerStmt->execute()) {
                Response::json(["success" => false, "message" => "Failed to validate blog owner"], 500);
                return;
            }
            $ownerRow = $ownerStmt->get_result()->fetch_assoc();
            if (!$ownerRow) {
                Response::json(["success" => false, "message" => "Blog not found"], 404);
                return;
            }
            if ((int) $ownerRow['user_id'] !== $authUserId) {
                Response::json(["success" => false, "message" => "You can only delete your own blog posts"], 403);
                return;
            }
        }

        if ($this->hasColumn('blog_posts', 'deleted_at')) {
            $stmt = $this->conn->prepare("UPDATE blog_posts SET deleted_at = NOW() WHERE blog_id = ? AND deleted_at IS NULL");
        } else {
            $stmt = $this->conn->prepare("DELETE FROM blog_posts WHERE blog_id=?");
        }
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to prepare blog deletion"], 500);
            return;
        }

        $stmt->bind_param("i", $id);
        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to delete blog"], 500);
            return;
        }

        if ($stmt->affected_rows === 0) {
            Response::json(["success" => false, "message" => "Blog not found"], 404);
            return;
        }

        $this->safeLogActivity("Blog Delete", "Deleted blog ID: " . $id);
        Response::json(["success" => true, "message" => "Blog deleted"]);
    }
}
