<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/activityMiddleware.php';
require_once __DIR__ . '/../services/InactivityService.php';

class ReportController
{
    private $conn;

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

    private function scalar(string $sql)
    {
        $result = $this->conn->query($sql);
        if (!$result) {
            return 0;
        }
        $row = $result->fetch_row();
        return (int) ($row[0] ?? 0);
    }

    private function bindParams(mysqli_stmt $stmt, string $types, array &$params): void
    {
        if ($types === '') {
            return;
        }

        $refs = [];
        $refs[] = &$types;
        foreach ($params as &$param) {
            $refs[] = &$param;
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }

    private function isValidDate(string $date): bool
    {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    public function list()
    {
        if (!$this->requireAdmin()) {
            return;
        }

        $result = $this->conn->query("
            SELECT activity_type, COUNT(*) AS total
            FROM activity_logs
            GROUP BY activity_type
            ORDER BY total DESC
        ");
        if (!$result) {
            Response::json(["success" => false, "message" => "Failed to fetch reports"], 500);
            return;
        }

        $data = [];

        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }

        $this->safeLogActivity("Report List", "Fetched activity summary report");

        Response::json(["success" => true, "data" => $data]);
    }

    public function statistics()
    {
        if (!$this->requireAdmin()) {
            return;
        }

        $messagesLast7Days = $this->scalar("
            SELECT COUNT(*)
            FROM messages
            WHERE sent_at >= (NOW() - INTERVAL 7 DAY)
        ");

        $averageMessagesPerTutor = 0;
        $avgResult = $this->conn->query("
            SELECT AVG(message_count) AS avg_messages
            FROM (
                SELECT t.tutor_id, COUNT(m.message_id) AS message_count
                FROM tutors t
                LEFT JOIN messages m
                    ON m.sender_id = t.user_id OR m.receiver_id = t.user_id
                GROUP BY t.tutor_id
            ) x
        ");
        if ($avgResult && ($row = $avgResult->fetch_assoc())) {
            $averageMessagesPerTutor = round((float) ($row['avg_messages'] ?? 0), 2);
        }

        $activeAllocations = $this->scalar("
            SELECT COUNT(*)
            FROM allocations
            WHERE status = 'active'
        ");

        $scheduledMeetings = $this->scalar("
            SELECT COUNT(*)
            FROM meetings
            WHERE status = 'scheduled'
        ");

        $this->safeLogActivity("Report Statistics", "Viewed statistics report");

        Response::json([
            "success" => true,
            "data" => [
                "messages_last_7_days" => $messagesLast7Days,
                "average_messages_per_tutor" => $averageMessagesPerTutor,
                "active_allocations" => $activeAllocations,
                "scheduled_meetings" => $scheduledMeetings
            ]
        ]);
    }

    public function exceptions()
    {
        if (!$this->requireAdmin()) {
            return;
        }

        $studentsWithoutTutor = [];
        $resultNoTutor = $this->conn->query("
            SELECT s.student_id, u.user_id, u.user_name, u.email
            FROM students s
            JOIN users u ON s.user_id = u.user_id
            LEFT JOIN allocations a
                ON a.student_id = s.student_id
                AND a.status = 'active'
            WHERE a.allocation_id IS NULL
            ORDER BY s.student_id ASC
        ");
        if ($resultNoTutor) {
            while ($row = $resultNoTutor->fetch_assoc()) {
                $studentsWithoutTutor[] = $row;
            }
        }

        $inactivityService = new InactivityService($this->conn);
        $inactive7Days = $inactivityService->listInactiveAllocations(7);
        $inactive28Days = $inactivityService->listInactiveAllocations(28);

        $this->safeLogActivity("Report Exceptions", "Viewed exception report");

        Response::json([
            "success" => true,
            "data" => [
                "students_without_personal_tutor" => $studentsWithoutTutor,
                "inactive_7_days" => $inactive7Days,
                "inactive_28_days" => $inactive28Days
            ]
        ]);
    }

    public function overview()
    {
        if (!$this->requireAdmin()) {
            return;
        }

        // 1) Activity report
        $activity = [];
        $resultActivity = $this->conn->query("
            SELECT activity_type, COUNT(*) AS total
            FROM activity_logs
            GROUP BY activity_type
            ORDER BY total DESC
        ");
        if ($resultActivity) {
            while ($row = $resultActivity->fetch_assoc()) {
                $activity[] = $row;
            }
        }

        // 2) Statistics report
        $messagesLast7Days = $this->scalar("
            SELECT COUNT(*)
            FROM messages
            WHERE sent_at >= (NOW() - INTERVAL 7 DAY)
        ");

        $averageMessagesPerTutor = 0;
        $avgResult = $this->conn->query("
            SELECT AVG(message_count) AS avg_messages
            FROM (
                SELECT t.tutor_id, COUNT(m.message_id) AS message_count
                FROM tutors t
                LEFT JOIN messages m
                    ON m.sender_id = t.user_id OR m.receiver_id = t.user_id
                GROUP BY t.tutor_id
            ) x
        ");
        if ($avgResult && ($row = $avgResult->fetch_assoc())) {
            $averageMessagesPerTutor = round((float) ($row['avg_messages'] ?? 0), 2);
        }

        $statistics = [
            "messages_last_7_days" => $messagesLast7Days,
            "average_messages_per_tutor" => $averageMessagesPerTutor,
            "active_allocations" => $this->scalar("SELECT COUNT(*) FROM allocations WHERE status = 'active'"),
            "scheduled_meetings" => $this->scalar("SELECT COUNT(*) FROM meetings WHERE status = 'scheduled'")
        ];

        // 3) Exception report
        $studentsWithoutTutor = [];
        $resultNoTutor = $this->conn->query("
            SELECT s.student_id, u.user_id, u.user_name, u.email
            FROM students s
            JOIN users u ON s.user_id = u.user_id
            LEFT JOIN allocations a
                ON a.student_id = s.student_id
                AND a.status = 'active'
            WHERE a.allocation_id IS NULL
            ORDER BY s.student_id ASC
        ");
        if ($resultNoTutor) {
            while ($row = $resultNoTutor->fetch_assoc()) {
                $studentsWithoutTutor[] = $row;
            }
        }

        $inactivityService = new InactivityService($this->conn);
        $exceptions = [
            "students_without_personal_tutor" => $studentsWithoutTutor,
            "inactive_7_days" => $inactivityService->listInactiveAllocations(7),
            "inactive_28_days" => $inactivityService->listInactiveAllocations(28)
        ];

        $this->safeLogActivity("Report Overview", "Viewed consolidated report overview");

        Response::json([
            "success" => true,
            "data" => [
                "activity" => $activity,
                "statistics" => $statistics,
                "exceptions" => $exceptions
            ]
        ]);
    }

    public function activityLogs()
    {
        if (!$this->requireAdmin()) {
            return;
        }

        $where = [];
        $types = '';
        $params = [];

        $userId = filter_var($_GET['user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($userId !== false && $userId > 0) {
            $where[] = "l.user_id = ?";
            $types .= 'i';
            $params[] = (int) $userId;
        }

        $activityType = trim((string) ($_GET['activity_type'] ?? ''));
        if ($activityType !== '') {
            $where[] = "l.activity_type = ?";
            $types .= 's';
            $params[] = $activityType;
        }

        $pageVisited = trim((string) ($_GET['page_visited'] ?? ''));
        if ($pageVisited !== '') {
            $where[] = "l.page_visited = ?";
            $types .= 's';
            $params[] = $pageVisited;
        }

        $fromDate = trim((string) ($_GET['from_date'] ?? ''));
        if ($fromDate !== '') {
            if (!$this->isValidDate($fromDate)) {
                Response::json(["success" => false, "message" => "from_date must be YYYY-MM-DD"], 400);
            }
            $where[] = "DATE(l.access_time) >= ?";
            $types .= 's';
            $params[] = $fromDate;
        }

        $toDate = trim((string) ($_GET['to_date'] ?? ''));
        if ($toDate !== '') {
            if (!$this->isValidDate($toDate)) {
                Response::json(["success" => false, "message" => "to_date must be YYYY-MM-DD"], 400);
            }
            $where[] = "DATE(l.access_time) <= ?";
            $types .= 's';
            $params[] = $toDate;
        }

        $limit = filter_var($_GET['limit'] ?? 100, FILTER_VALIDATE_INT);
        $offset = filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT);
        if ($limit === false || $limit <= 0 || $limit > 500) {
            Response::json(["success" => false, "message" => "limit must be between 1 and 500"], 400);
        }
        if ($offset === false || $offset < 0) {
            Response::json(["success" => false, "message" => "offset must be 0 or greater"], 400);
        }

        $whereSql = '';
        if (count($where) > 0) {
            $whereSql = ' WHERE ' . implode(' AND ', $where);
        }

        $countSql = "
            SELECT COUNT(*)
            FROM activity_logs l
            LEFT JOIN users u ON l.user_id = u.user_id
            {$whereSql}
        ";
        $countStmt = $this->conn->prepare($countSql);
        if (!$countStmt) {
            Response::json(["success" => false, "message" => "Failed to prepare activity logs count"], 500);
        }
        $countParams = $params;
        $countTypes = $types;
        $this->bindParams($countStmt, $countTypes, $countParams);
        if (!$countStmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to count activity logs"], 500);
        }
        $total = (int) (($countStmt->get_result()->fetch_row()[0]) ?? 0);

        $dataSql = "
            SELECT l.log_id, l.user_id, u.user_name,
                   COALESCE(s.full_name, t.full_name, sf.full_name, u.user_name) AS full_name,
                   l.page_visited, l.activity_type, l.browser_used, l.ip_address, l.access_time
            FROM activity_logs l
            LEFT JOIN users u ON l.user_id = u.user_id
            LEFT JOIN students s ON s.user_id = u.user_id
            LEFT JOIN tutors t ON t.user_id = u.user_id
            LEFT JOIN staff sf ON sf.user_id = u.user_id
            {$whereSql}
            ORDER BY l.access_time DESC
            LIMIT ? OFFSET ?
        ";
        $dataStmt = $this->conn->prepare($dataSql);
        if (!$dataStmt) {
            Response::json(["success" => false, "message" => "Failed to prepare activity logs query"], 500);
        }

        $dataParams = $params;
        $dataParams[] = (int) $limit;
        $dataParams[] = (int) $offset;
        $dataTypes = $types . 'ii';
        $this->bindParams($dataStmt, $dataTypes, $dataParams);

        if (!$dataStmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to fetch activity logs"], 500);
        }

        $rows = [];
        $result = $dataStmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        $this->safeLogActivity("Report ActivityLogs", "Viewed activity logs report");

        Response::json([
            "success" => true,
            "data" => [
                "total" => $total,
                "limit" => (int) $limit,
                "offset" => (int) $offset,
                "items" => $rows
            ]
        ]);
    }

    public function activityLogsCsv()
    {
        if (!$this->requireAdmin()) {
            return;
        }

        $where = [];
        $types = '';
        $params = [];

        $userId = filter_var($_GET['user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($userId !== false && $userId > 0) {
            $where[] = "l.user_id = ?";
            $types .= 'i';
            $params[] = (int) $userId;
        }

        $activityType = trim((string) ($_GET['activity_type'] ?? ''));
        if ($activityType !== '') {
            $where[] = "l.activity_type = ?";
            $types .= 's';
            $params[] = $activityType;
        }

        $fromDate = trim((string) ($_GET['from_date'] ?? ''));
        if ($fromDate !== '') {
            if (!$this->isValidDate($fromDate)) {
                Response::json(["success" => false, "message" => "from_date must be YYYY-MM-DD"], 400);
            }
            $where[] = "DATE(l.access_time) >= ?";
            $types .= 's';
            $params[] = $fromDate;
        }

        $toDate = trim((string) ($_GET['to_date'] ?? ''));
        if ($toDate !== '') {
            if (!$this->isValidDate($toDate)) {
                Response::json(["success" => false, "message" => "to_date must be YYYY-MM-DD"], 400);
            }
            $where[] = "DATE(l.access_time) <= ?";
            $types .= 's';
            $params[] = $toDate;
        }

        $limit = filter_var($_GET['limit'] ?? 1000, FILTER_VALIDATE_INT);
        if ($limit === false || $limit <= 0 || $limit > 5000) {
            Response::json(["success" => false, "message" => "limit must be between 1 and 5000"], 400);
        }

        $whereSql = count($where) > 0 ? (' WHERE ' . implode(' AND ', $where)) : '';
        $sql = "
            SELECT l.log_id, l.user_id, u.user_name,
                   COALESCE(s.full_name, t.full_name, sf.full_name, u.user_name) AS full_name,
                   l.page_visited, l.activity_type, l.browser_used, l.ip_address, l.access_time
            FROM activity_logs l
            LEFT JOIN users u ON l.user_id = u.user_id
            LEFT JOIN students s ON s.user_id = u.user_id
            LEFT JOIN tutors t ON t.user_id = u.user_id
            LEFT JOIN staff sf ON sf.user_id = u.user_id
            {$whereSql}
            ORDER BY l.access_time DESC
            LIMIT ?
        ";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to prepare activity log export"], 500);
        }

        $dataParams = $params;
        $dataParams[] = (int) $limit;
        $dataTypes = $types . 'i';
        $this->bindParams($stmt, $dataTypes, $dataParams);
        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to export activity logs"], 500);
        }

        $result = $stmt->get_result();
        if (!$result) {
            Response::json(["success" => false, "message" => "Failed to build activity log export result"], 500);
        }

        $this->safeLogActivity("Report ActivityLogsCsv", "Exported activity logs CSV");

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="activity_logs.csv"');

        $out = fopen('php://output', 'wb');
        if ($out === false) {
            Response::json(["success" => false, "message" => "Failed to open output stream for CSV export"], 500);
        }
        fputcsv($out, ['log_id', 'user_id', 'user_name', 'full_name', 'page_visited', 'activity_type', 'browser_used', 'ip_address', 'access_time']);
        while ($row = $result->fetch_assoc()) {
            fputcsv($out, [
                $row['log_id'] ?? '',
                $row['user_id'] ?? '',
                $row['user_name'] ?? '',
                $row['full_name'] ?? '',
                $row['page_visited'] ?? '',
                $row['activity_type'] ?? '',
                $row['browser_used'] ?? '',
                $row['ip_address'] ?? '',
                $row['access_time'] ?? ''
            ]);
        }
        fclose($out);
        exit();
    }

    public function activityTrend()
    {
        if (!$this->requireAdmin()) {
            return;
        }

        $days = filter_var($_GET['days'] ?? 7, FILTER_VALIDATE_INT);
        if ($days === false || $days <= 0 || $days > 365) {
            Response::json(["success" => false, "message" => "days must be between 1 and 365"], 400);
        }

        $intervalDays = $days - 1;
        $stmt = $this->conn->prepare("
            SELECT DATE(access_time) AS day, COUNT(*) AS total
            FROM activity_logs
            WHERE access_time >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            GROUP BY DATE(access_time)
            ORDER BY day ASC
        ");
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to prepare activity trend"], 500);
        }

        $stmt->bind_param("i", $intervalDays);
        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to fetch activity trend"], 500);
        }

        $result = $stmt->get_result();
        if (!$result) {
            Response::json(["success" => false, "message" => "Failed to fetch activity trend"], 500);
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                "day" => (string) ($row['day'] ?? ''),
                "total" => (int) ($row['total'] ?? 0)
            ];
        }

        $this->safeLogActivity("Report ActivityTrend", "Viewed " . $days . "-day activity trend");
        Response::json(["success" => true, "data" => $rows]);
    }

    public function activeUsers()
    {
        if (!$this->requireAdmin()) {
            return;
        }

        $days = filter_var($_GET['days'] ?? 30, FILTER_VALIDATE_INT);
        $limit = filter_var($_GET['limit'] ?? 5, FILTER_VALIDATE_INT);

        if ($days === false || $days <= 0 || $days > 365) {
            Response::json(["success" => false, "message" => "days must be between 1 and 365"], 400);
        }
        if ($limit === false || $limit <= 0 || $limit > 20) {
            Response::json(["success" => false, "message" => "limit must be between 1 and 20"], 400);
        }

        $sql = "
            SELECT l.user_id, u.user_name,
                   COALESCE(s.full_name, t.full_name, sf.full_name, u.user_name) AS full_name,
                   COUNT(*) AS actions
            FROM activity_logs l
            LEFT JOIN users u ON u.user_id = l.user_id
            LEFT JOIN students s ON s.user_id = u.user_id
            LEFT JOIN tutors t ON t.user_id = u.user_id
            LEFT JOIN staff sf ON sf.user_id = u.user_id
            WHERE l.access_time >= (NOW() - INTERVAL ? DAY)
            GROUP BY l.user_id, u.user_name, full_name
            ORDER BY actions DESC
            LIMIT ?
        ";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to prepare active users report"], 500);
        }

        $stmt->bind_param("ii", $days, $limit);
        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to fetch active users report"], 500);
        }

        $rows = [];
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $uid = (int) ($row['user_id'] ?? 0);
            $name = trim((string) ($row['full_name'] ?? $row['user_name'] ?? ''));
            if ($name === '') {
                $name = $uid > 0 ? ("User #" . $uid) : "Unknown";
            }

            $rows[] = [
                "user_id" => $uid,
                "user_name" => $name,
                "full_name" => $name,
                "actions" => (int) ($row['actions'] ?? 0)
            ];
        }

        $this->safeLogActivity("Report ActiveUsers", "Viewed most active users report");
        Response::json(["success" => true, "data" => $rows]);
    }

    public function create()
    {
        Response::json(["success" => false, "message" => "Report is read-only"], 405);
    }

    public function update()
    {
        Response::json(["success" => false, "message" => "Report is read-only"], 405);
    }

    public function delete()
    {
        Response::json(["success" => false, "message" => "Report is read-only"], 405);
    }
}
