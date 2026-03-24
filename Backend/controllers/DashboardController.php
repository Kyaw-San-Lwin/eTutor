<?php
require_once __DIR__ . '/../config/database.php';

class DashboardController
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
        $user = $GLOBALS['auth_user'] ?? null;
        if (!is_array($user) || empty($user['user_id'])) {
            Response::json(["success" => false, "message" => "Unauthorized"], 401);
        }
        return $user;
    }

    private function requireDashboardRole(array $user): void
    {
        $role = (string) ($user['role'] ?? '');
        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            Response::json(["success" => false, "message" => "Access denied"], 403);
        }
    }

    private function scalar(string $sql, string $types = "", ...$params): int
    {
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return 0;
        }

        if ($types !== "") {
            $stmt->bind_param($types, ...$params);
        }

        if (!$stmt->execute()) {
            return 0;
        }

        $result = $stmt->get_result();
        if (!$result) {
            return 0;
        }

        $row = $result->fetch_row();
        return (int) ($row[0] ?? 0);
    }

    private function getUserProfile(int $userId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT u.user_id, r.role_name, COALESCE(s.is_admin, 0) AS is_admin
            FROM users u
            JOIN roles r ON u.role_id = r.role_id
            LEFT JOIN staff s ON u.user_id = s.user_id
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

        $row = $result->fetch_assoc();
        return [
            "user_id" => (int) ($row['user_id'] ?? 0),
            "role" => (string) ($row['role_name'] ?? ''),
            "is_admin" => (int) ($row['is_admin'] ?? 0) === 1
        ];
    }

    private function buildDashboardData(int $userId, string $role, bool $isAdmin): array
    {
        $data = [
            "user" => [
                "user_id" => $userId,
                "role" => $role,
                "is_admin" => $isAdmin
            ],
            "metrics" => []
        ];

        $data["metrics"]["unread_messages"] = $this->scalar(
            "SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND status = 'sent'",
            "i",
            $userId
        );

        if ($isAdmin) {
            $data["metrics"]["total_users"] = $this->scalar("SELECT COUNT(*) FROM users");
            $data["metrics"]["total_students"] = $this->scalar("SELECT COUNT(*) FROM students");
            $data["metrics"]["total_tutors"] = $this->scalar("SELECT COUNT(*) FROM tutors");
            $data["metrics"]["messages_last_7_days"] = $this->scalar(
                "SELECT COUNT(*) FROM messages WHERE sent_at >= (NOW() - INTERVAL 7 DAY)"
            );
            $data["metrics"]["active_allocations"] = $this->scalar("SELECT COUNT(*) FROM allocations WHERE status = 'active'");
            $data["metrics"]["scheduled_meetings"] = $this->scalar("SELECT COUNT(*) FROM meetings WHERE status = 'scheduled'");
            $data["metrics"]["total_documents"] = $this->scalar("SELECT COUNT(*) FROM documents");
            $data["metrics"]["total_blog_posts"] = $this->scalar("SELECT COUNT(*) FROM blog_posts");
            $data["metrics"]["today_activity_logs"] = $this->scalar("SELECT COUNT(*) FROM activity_logs WHERE DATE(access_time) = CURDATE()");
        } elseif ($role === 'tutor') {
            $tutorId = $this->scalar("SELECT tutor_id FROM tutors WHERE user_id = ? LIMIT 1", "i", $userId);
            $data["metrics"]["tutor_id"] = $tutorId;
            $data["metrics"]["active_assigned_students"] = $this->scalar(
                "SELECT COUNT(DISTINCT student_id) FROM allocations WHERE tutor_id = ? AND status = 'active'",
                "i",
                $tutorId
            );
            $data["metrics"]["scheduled_meetings"] = $this->scalar(
                "SELECT COUNT(*) FROM meetings WHERE tutor_id = ? AND status = 'scheduled'",
                "i",
                $tutorId
            );
            $data["metrics"]["my_blog_posts"] = $this->scalar(
                "SELECT COUNT(*) FROM blog_posts WHERE user_id = ?",
                "i",
                $userId
            );
        } elseif ($role === 'student') {
            $studentId = $this->scalar("SELECT student_id FROM students WHERE user_id = ? LIMIT 1", "i", $userId);
            $data["metrics"]["student_id"] = $studentId;
            $data["metrics"]["active_tutor_allocations"] = $this->scalar(
                "SELECT COUNT(*) FROM allocations WHERE student_id = ? AND status = 'active'",
                "i",
                $studentId
            );
            $data["metrics"]["scheduled_meetings"] = $this->scalar(
                "SELECT COUNT(*) FROM meetings WHERE student_id = ? AND status = 'scheduled'",
                "i",
                $studentId
            );
            $data["metrics"]["my_documents"] = $this->scalar(
                "SELECT COUNT(*) FROM documents WHERE student_id = ?",
                "i",
                $studentId
            );
        } else {
            $data["metrics"]["managed_allocations"] = $this->scalar(
                "SELECT COUNT(*) FROM allocations WHERE staff_id = ?",
                "i",
                $userId
            );
            $data["metrics"]["active_allocations"] = $this->scalar(
                "SELECT COUNT(*) FROM allocations WHERE staff_id = ? AND status = 'active'",
                "i",
                $userId
            );
        }

        return $data;
    }

    public function list()
    {
        $user = $this->requireAuth();
        $this->requireDashboardRole($user);

        $userId = (int) $user['user_id'];
        $role = (string) ($user['role'] ?? '');
        $isAdmin = (int) ($user['is_admin'] ?? 0) === 1;

        $data = $this->buildDashboardData($userId, $role, $isAdmin);

        Response::json(["success" => true, "data" => $data]);
    }

    public function userDashboard()
    {
        $user = $this->requireAuth();
        $this->requireDashboardRole($user);

        $requesterRole = (string) ($user['role'] ?? '');
        if ($requesterRole !== 'staff') {
            Response::json(["success" => false, "message" => "Staff only access"], 403);
        }

        $targetUserId = filter_var($_GET['user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($targetUserId === false || $targetUserId <= 0) {
            Response::json(["success" => false, "message" => "Valid user_id is required"], 400);
        }

        $profile = $this->getUserProfile((int) $targetUserId);
        if ($profile === null) {
            Response::json(["success" => false, "message" => "Target user not found"], 404);
        }

        $targetRole = (string) ($profile['role'] ?? '');
        if (!in_array($targetRole, ['student', 'tutor'], true)) {
            Response::json(["success" => false, "message" => "Only tutor or student dashboards can be viewed"], 400);
        }

        $data = $this->buildDashboardData(
            (int) $profile['user_id'],
            $targetRole,
            (bool) ($profile['is_admin'] ?? false)
        );

        Response::json(["success" => true, "data" => $data]);
    }

    public function lastLogin()
    {
        $user = $this->requireAuth();
        $this->requireDashboardRole($user);

        $userId = (int) $user['user_id'];

        // Previous login time (not current session login):
        // second most recent "User Logged in" event for this user.
        $previousStmt = $this->conn->prepare("
            SELECT access_time
            FROM activity_logs
            WHERE user_id = ?
              AND activity_type = 'User Logged in'
            ORDER BY access_time DESC
            LIMIT 1 OFFSET 1
        ");
        if (!$previousStmt) {
            Response::json(["success" => false, "message" => "Failed to fetch last login"], 500);
        }

        $previousStmt->bind_param("i", $userId);
        if (!$previousStmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to fetch last login"], 500);
        }

        $previousResult = $previousStmt->get_result();
        $previousRow = $previousResult ? $previousResult->fetch_assoc() : null;
        $previousLogin = $previousRow['access_time'] ?? null;

        // Current login timestamp from users table.
        $currentStmt = $this->conn->prepare("
            SELECT last_login
            FROM users
            WHERE user_id = ?
            LIMIT 1
        ");
        if (!$currentStmt) {
            Response::json(["success" => false, "message" => "Failed to fetch current login"], 500);
        }
        $currentStmt->bind_param("i", $userId);
        if (!$currentStmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to fetch current login"], 500);
        }
        $currentResult = $currentStmt->get_result();
        $currentRow = $currentResult ? $currentResult->fetch_assoc() : null;
        $currentLogin = $currentRow['last_login'] ?? null;

        Response::json([
            "success" => true,
            "data" => [
                "user_id" => $userId,
                "last_login" => $previousLogin, // backward compatible alias
                "previous_login" => $previousLogin,
                "current_login" => $currentLogin
            ]
        ]);
    }
}
