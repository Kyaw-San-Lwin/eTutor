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

    private function hasColumn(string $table, string $column): bool
    {
        $tableEscaped = $this->conn->real_escape_string($table);
        $columnEscaped = $this->conn->real_escape_string($column);
        $result = $this->conn->query("SHOW COLUMNS FROM `{$tableEscaped}` LIKE '{$columnEscaped}'");
        return $result && $result->num_rows > 0;
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

    private function getStaffIdByUserId(int $userId): int
    {
        return $this->scalar("SELECT staff_id FROM staff WHERE user_id = ? LIMIT 1", "i", $userId);
    }

    private function getTutorIdByUserId(int $userId): int
    {
        return $this->scalar("SELECT tutor_id FROM tutors WHERE user_id = ? LIMIT 1", "i", $userId);
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

            $photoSelect = $this->hasColumn('users', 'profile_photo')
                ? ", u.profile_photo"
                : ", NULL AS profile_photo";
            $tutorStmt = $this->conn->prepare("
                SELECT
                    COALESCE(NULLIF(t.full_name, ''), u.user_name) AS full_name
                    {$photoSelect}
                FROM tutors t
                JOIN users u ON u.user_id = t.user_id
                WHERE t.tutor_id = ?
                LIMIT 1
            ");
            if ($tutorStmt) {
                $tutorStmt->bind_param("i", $tutorId);
                if ($tutorStmt->execute()) {
                    $tutorRow = $tutorStmt->get_result()->fetch_assoc();
                    if ($tutorRow) {
                        $data["full_name"] = (string) ($tutorRow['full_name'] ?? '');
                        $data["profile_photo"] = (string) ($tutorRow['profile_photo'] ?? '');
                    }
                }
            }

            $upcomingMeetings = [];
            $meetingStmt = $this->conn->prepare("
                SELECT meeting_id, meeting_type, meeting_date, meeting_time, status, meeting_link, outcome
                FROM meetings
                WHERE tutor_id = ?
                  AND meeting_date >= CURDATE()
                ORDER BY meeting_date ASC, meeting_time ASC
                LIMIT 5
            ");
            if ($meetingStmt) {
                $meetingStmt->bind_param("i", $tutorId);
                if ($meetingStmt->execute()) {
                    $result = $meetingStmt->get_result();
                    while ($row = $result->fetch_assoc()) {
                        $upcomingMeetings[] = $row;
                    }
                }
            }
            $data["upcoming_meetings"] = $upcomingMeetings;

            $commenterPhotoSelect = $this->hasColumn('users', 'profile_photo')
                ? ", cu.profile_photo AS profile_photo"
                : ", NULL AS profile_photo";
            $recentComments = [];
            $commentStmt = $this->conn->prepare("
                SELECT
                    bc.blogcomment_id,
                    bc.post_id,
                    bc.comment,
                    bc.created_at,
                    COALESCE(
                        NULLIF(s.full_name, ''),
                        NULLIF(t.full_name, ''),
                        NULLIF(sf.full_name, ''),
                        cu.user_name
                    ) AS full_name
                    {$commenterPhotoSelect}
                FROM blog_comments bc
                JOIN blog_posts bp ON bp.blog_id = bc.post_id
                JOIN users cu ON cu.user_id = bc.user_id
                LEFT JOIN students s ON s.user_id = cu.user_id
                LEFT JOIN tutors t ON t.user_id = cu.user_id
                LEFT JOIN staff sf ON sf.user_id = cu.user_id
                WHERE bp.user_id = ?
                ORDER BY bc.created_at DESC
                LIMIT 5
            ");
            if ($commentStmt) {
                $commentStmt->bind_param("i", $userId);
                if ($commentStmt->execute()) {
                    $result = $commentStmt->get_result();
                    while ($row = $result->fetch_assoc()) {
                        $recentComments[] = $row;
                    }
                }
            }
            $data["recent_blog_comments"] = $recentComments;
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

            if ($studentId > 0) {
                $studentStmt = $this->conn->prepare("
                    SELECT s.student_id, COALESCE(NULLIF(s.full_name, ''), u.user_name) AS full_name
                    FROM students s
                    JOIN users u ON s.user_id = u.user_id
                    WHERE s.student_id = ?
                    LIMIT 1
                ");
                if ($studentStmt) {
                    $studentStmt->bind_param("i", $studentId);
                    if ($studentStmt->execute()) {
                        $student = $studentStmt->get_result()->fetch_assoc();
                        if ($student) {
                            $data["student"] = [
                                "student_id" => (int) ($student['student_id'] ?? 0),
                                "full_name" => (string) ($student['full_name'] ?? '')
                            ];
                        }
                    }
                }

                $photoSelect = $this->hasColumn('users', 'profile_photo')
                    ? ', tu.profile_photo'
                    : ', NULL AS profile_photo';
                $tutorStmt = $this->conn->prepare("
                    SELECT a.tutor_id,
                           tu.user_id AS tutor_user_id,
                           COALESCE(NULLIF(t.full_name, ''), tu.user_name) AS full_name,
                           tu.email,
                           t.department
                           {$photoSelect}
                    FROM allocations a
                    JOIN tutors t ON a.tutor_id = t.tutor_id
                    JOIN users tu ON t.user_id = tu.user_id
                    WHERE a.student_id = ? AND a.status = 'active'
                    ORDER BY a.allocated_date DESC
                    LIMIT 1
                ");
                $tutorUserId = 0;
                if ($tutorStmt) {
                    $tutorStmt->bind_param("i", $studentId);
                    if ($tutorStmt->execute()) {
                        $tutorRow = $tutorStmt->get_result()->fetch_assoc();
                        if ($tutorRow) {
                            $tutorUserId = (int) ($tutorRow['tutor_user_id'] ?? 0);
                            $data["personal_tutor"] = [
                                "tutor_id" => (int) ($tutorRow['tutor_id'] ?? 0),
                                "tutor_user_id" => $tutorUserId,
                                "full_name" => (string) ($tutorRow['full_name'] ?? ''),
                                "email" => (string) ($tutorRow['email'] ?? ''),
                                "department" => (string) ($tutorRow['department'] ?? ''),
                                "profile_photo" => (string) ($tutorRow['profile_photo'] ?? '')
                            ];
                        }
                    }
                }

                $upcomingMeetings = [];
                $meetingStmt = $this->conn->prepare("
                    SELECT meeting_id, meeting_type, meeting_date, meeting_time, status, meeting_link
                    FROM meetings
                    WHERE student_id = ?
                      AND meeting_date >= CURDATE()
                    ORDER BY meeting_date ASC, meeting_time ASC
                    LIMIT 5
                ");
                if ($meetingStmt) {
                    $meetingStmt->bind_param("i", $studentId);
                    if ($meetingStmt->execute()) {
                        $result = $meetingStmt->get_result();
                        while ($row = $result->fetch_assoc()) {
                            $upcomingMeetings[] = $row;
                        }
                    }
                }
                $data["upcoming_meetings"] = $upcomingMeetings;

                $feedbackPhotoSelect = $this->hasColumn('users', 'profile_photo')
                    ? ", tu.profile_photo AS tutor_profile_photo"
                    : ", NULL AS tutor_profile_photo";
                $recentFeedback = [];
                $commentStmt = $this->conn->prepare("
                    SELECT dc.document_id,
                           d.file_path AS document_name,
                           dc.comment,
                           dc.created_at AS commented_at,
                           COALESCE(NULLIF(t.full_name, ''), tu.user_name) AS tutor_full_name
                           {$feedbackPhotoSelect}
                    FROM document_comments dc
                    JOIN documents d ON dc.document_id = d.document_id
                    JOIN tutors t ON dc.tutor_id = t.tutor_id
                    JOIN users tu ON t.user_id = tu.user_id
                    WHERE d.student_id = ?
                    ORDER BY dc.created_at DESC
                    LIMIT 5
                ");
                if ($commentStmt) {
                    $commentStmt->bind_param("i", $studentId);
                    if ($commentStmt->execute()) {
                        $result = $commentStmt->get_result();
                        while ($row = $result->fetch_assoc()) {
                            $recentFeedback[] = $row;
                        }
                    }
                }
                $data["recent_document_feedback"] = $recentFeedback;

                $recentMessages = [];
                if ($tutorUserId > 0) {
                    $msgStmt = $this->conn->prepare("
                        SELECT message_id, sender_id, receiver_id, message, sent_at, status
                        FROM messages
                        WHERE (sender_id = ? AND receiver_id = ?)
                           OR (sender_id = ? AND receiver_id = ?)
                        ORDER BY sent_at DESC
                        LIMIT 10
                    ");
                    if ($msgStmt) {
                        $msgStmt->bind_param("iiii", $userId, $tutorUserId, $tutorUserId, $userId);
                        if ($msgStmt->execute()) {
                            $result = $msgStmt->get_result();
                            while ($row = $result->fetch_assoc()) {
                                $recentMessages[] = $row;
                            }
                        }
                    }
                }
                $data["recent_messages"] = $recentMessages;

                $totalMessagesWithTutor = 0;
                $unreadMessagesFromTutor = 0;
                if ($tutorUserId > 0) {
                    $totalMessagesWithTutor = $this->scalar(
                        "SELECT COUNT(*) FROM messages WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)",
                        "iiii",
                        $userId,
                        $tutorUserId,
                        $tutorUserId,
                        $userId
                    );
                    $unreadMessagesFromTutor = $this->scalar(
                        "SELECT COUNT(*) FROM messages WHERE sender_id = ? AND receiver_id = ? AND status = 'sent'",
                        "ii",
                        $tutorUserId,
                        $userId
                    );
                }

                $data["summary"] = [
                    "unread_messages" => $unreadMessagesFromTutor,
                    "total_messages_with_tutor" => $totalMessagesWithTutor,
                    "scheduled_meetings" => (int) ($data["metrics"]["scheduled_meetings"] ?? 0),
                    "documents_uploaded" => (int) ($data["metrics"]["my_documents"] ?? 0),
                    "blog_posts" => $this->scalar("SELECT COUNT(*) FROM blog_posts WHERE user_id = ?", "i", $userId),
                    "blog_comments" => $this->scalar("SELECT COUNT(*) FROM blog_comments WHERE user_id = ?", "i", $userId),
                    "last_interaction_at" => isset($recentMessages[0]['sent_at']) ? $recentMessages[0]['sent_at'] : null
                ];
            }
        } else {
            $staffId = $this->getStaffIdByUserId($userId);
            $data["metrics"]["staff_id"] = $staffId > 0 ? $staffId : null;
            $data["metrics"]["managed_allocations"] = $this->scalar(
                "SELECT COUNT(*) FROM allocations WHERE staff_id = ?",
                "i",
                $staffId
            );
            $data["metrics"]["active_allocations"] = $this->scalar(
                "SELECT COUNT(*) FROM allocations WHERE staff_id = ? AND status = 'active'",
                "i",
                $staffId
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
        if (!in_array($targetRole, ['student', 'tutor', 'staff'], true)) {
            Response::json(["success" => false, "message" => "Only staff, tutor or student dashboards can be viewed"], 400);
        }

        $data = $this->buildDashboardData(
            (int) $profile['user_id'],
            $targetRole,
            (bool) ($profile['is_admin'] ?? false)
        );

        Response::json(["success" => true, "data" => $data]);
    }

    public function studentDashboard()
    {
        $user = $this->requireAuth();
        $this->requireDashboardRole($user);

        $requesterRole = (string) ($user['role'] ?? '');
        if ($requesterRole !== 'staff') {
            Response::json(["success" => false, "message" => "Staff only access"], 403);
        }

        $targetUserId = filter_var($_GET['student_user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($targetUserId === false || $targetUserId <= 0) {
            Response::json(["success" => false, "message" => "Valid student_user_id is required"], 400);
        }

        $profile = $this->getUserProfile((int) $targetUserId);
        if ($profile === null || (string) ($profile['role'] ?? '') !== 'student') {
            Response::json(["success" => false, "message" => "Target student not found"], 404);
        }

        $data = $this->buildDashboardData(
            (int) $profile['user_id'],
            'student',
            false
        );

        Response::json(["success" => true, "data" => $data]);
    }

    public function staffDashboard()
    {
        $user = $this->requireAuth();
        $this->requireDashboardRole($user);

        $requesterRole = (string) ($user['role'] ?? '');
        if ($requesterRole !== 'staff') {
            Response::json(["success" => false, "message" => "Staff only access"], 403);
        }

        $targetUserId = filter_var($_GET['staff_user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($targetUserId === false || $targetUserId <= 0) {
            Response::json(["success" => false, "message" => "Valid staff_user_id is required"], 400);
        }

        $profile = $this->getUserProfile((int) $targetUserId);
        if ($profile === null || (string) ($profile['role'] ?? '') !== 'staff') {
            Response::json(["success" => false, "message" => "Target staff not found"], 404);
        }

        $data = $this->buildDashboardData(
            (int) $profile['user_id'],
            'staff',
            (bool) ($profile['is_admin'] ?? false)
        );

        Response::json(["success" => true, "data" => $data]);
    }

    public function tutorTutees()
    {
        $user = $this->requireAuth();
        $this->requireDashboardRole($user);

        $role = (string) ($user['role'] ?? '');
        if ($role !== 'tutor') {
            Response::json(["success" => false, "message" => "Tutor only access"], 403);
        }

        $tutorUserId = (int) ($user['user_id'] ?? 0);
        $tutorId = $this->getTutorIdByUserId($tutorUserId);
        if ($tutorId <= 0) {
            Response::json(["success" => false, "message" => "Tutor profile not found"], 404);
        }

        $q = trim((string) ($_GET['q'] ?? ''));
        $programme = trim((string) ($_GET['programme'] ?? ''));
        $sortBy = strtolower(trim((string) ($_GET['sort_by'] ?? 'last_interaction')));
        $sortDir = strtolower(trim((string) ($_GET['sort_dir'] ?? 'desc')));
        $limit = filter_var($_GET['limit'] ?? 100, FILTER_VALIDATE_INT);
        $offset = filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT);

        if ($limit === false || $limit <= 0 || $limit > 100) {
            Response::json(["success" => false, "message" => "limit must be between 1 and 100"], 400);
        }
        if ($offset === false || $offset < 0) {
            Response::json(["success" => false, "message" => "offset must be 0 or greater"], 400);
        }

        $allowedSortBy = [
            'name' => 'student_full_name',
            'programme' => 'student_programme',
            'last_interaction' => 'last_interaction_at',
            'unread_messages' => 'unread_messages',
            'documents' => 'documents_uploaded'
        ];
        $sortColumn = $allowedSortBy[$sortBy] ?? $allowedSortBy['last_interaction'];
        $sortDirection = $sortDir === 'asc' ? 'ASC' : 'DESC';

        $photoSelect = $this->hasColumn('users', 'profile_photo')
            ? ', su.profile_photo AS student_profile_photo'
            : ', NULL AS student_profile_photo';

        $where = " WHERE a.tutor_id = ? AND a.status = 'active' ";
        $types = "iiii";
        $params = [$tutorUserId, $tutorUserId, $tutorUserId, $tutorId];

        if ($q !== '') {
            $where .= " AND (COALESCE(NULLIF(s.full_name, ''), su.user_name) LIKE ? OR su.email LIKE ?) ";
            $types .= "ss";
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }

        if ($programme !== '' && strtolower($programme) !== 'all') {
            $where .= " AND s.programme = ? ";
            $types .= "s";
            $params[] = $programme;
        }

        $baseSelect = "
            SELECT
                s.student_id,
                su.user_id AS student_user_id,
                COALESCE(NULLIF(s.full_name, ''), su.user_name) AS student_full_name,
                su.user_name AS student_user_name,
                su.email AS student_email,
                s.contact_number AS student_contact_number,
                s.programme AS student_programme
                {$photoSelect},
                COUNT(DISTINCT d.document_id) AS documents_uploaded,
                SUM(CASE
                    WHEN m.sender_id = su.user_id
                     AND m.receiver_id = ?
                     AND m.status = 'sent' THEN 1
                    ELSE 0
                END) AS unread_messages,
                MAX(m.sent_at) AS last_interaction_at
            FROM allocations a
            JOIN students s ON s.student_id = a.student_id
            JOIN users su ON su.user_id = s.user_id
            LEFT JOIN documents d ON d.student_id = s.student_id
            LEFT JOIN messages m ON (
                (m.sender_id = su.user_id AND m.receiver_id = ?)
                OR
                (m.sender_id = ? AND m.receiver_id = su.user_id)
            )
            {$where}
            GROUP BY s.student_id, su.user_id, student_full_name, su.user_name, su.email, s.contact_number, s.programme, student_profile_photo
        ";

        $countSql = "SELECT COUNT(*) FROM ({$baseSelect}) t";
        $countStmt = $this->conn->prepare($countSql);
        if (!$countStmt) {
            Response::json(["success" => false, "message" => "Failed to prepare tutees count"], 500);
        }

        $countParams = $params;
        $countTypes = $types;
        $countStmt->bind_param($countTypes, ...$countParams);
        if (!$countStmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to fetch tutees count"], 500);
        }
        $total = (int) (($countStmt->get_result()->fetch_row()[0]) ?? 0);

        $sql = "
            SELECT *
            FROM ({$baseSelect}) x
            ORDER BY {$sortColumn} {$sortDirection}, student_full_name ASC
            LIMIT ? OFFSET ?
        ";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to prepare tutor tutees dashboard"], 500);
        }

        $typesWithPaging = $types . "ii";
        $paramsWithPaging = $params;
        $paramsWithPaging[] = (int) $limit;
        $paramsWithPaging[] = (int) $offset;
        $stmt->bind_param($typesWithPaging, ...$paramsWithPaging);

        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to fetch tutor tutees dashboard"], 500);
        }

        $rows = [];
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $lastInteraction = $row['last_interaction_at'] ?? null;
            $risk = 'normal';
            if ($lastInteraction) {
                $days = (int) floor((time() - strtotime((string) $lastInteraction)) / 86400);
                if ($days >= 28) {
                    $risk = 'inactive28';
                } elseif ($days >= 7) {
                    $risk = 'inactive7';
                }
            } else {
                $risk = 'inactive28';
            }

            $row['unread_messages'] = (int) ($row['unread_messages'] ?? 0);
            $row['documents_uploaded'] = (int) ($row['documents_uploaded'] ?? 0);
            $row['risk_level'] = $risk;
            $rows[] = $row;
        }

        Response::json([
            "success" => true,
            "data" => $rows,
            "meta" => [
                "total" => $total,
                "limit" => (int) $limit,
                "offset" => (int) $offset,
                "sort_by" => $sortBy,
                "sort_dir" => strtolower($sortDirection)
            ]
        ]);
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
