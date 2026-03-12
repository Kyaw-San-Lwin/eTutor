<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/activityMiddleware.php';

class MeetingController
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

    private function requireMeetingRole(array $user): void
    {
        $role = (string) ($user['role'] ?? '');
        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            Response::json(["success" => false, "message" => "Access denied"], 403);
        }
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

    private function isValidDate(string $date): bool
    {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    private function isValidTime(string $time): bool
    {
        $formats = ['H:i:s', 'H:i'];
        foreach ($formats as $format) {
            $t = DateTime::createFromFormat($format, $time);
            if ($t && $t->format($format) === $time) {
                return true;
            }
        }
        return false;
    }

    private function normalizeMeetingType(string $meetingType): string
    {
        $type = strtolower(trim($meetingType));
        if ($type === 'online') {
            return 'virtual';
        }
        if ($type === 'real') {
            return 'physical';
        }
        return $type;
    }

    private function validateMeetingPayload(array $data, bool $requireId = false): array
    {
        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
        $studentId = filter_var($data['student_id'] ?? null, FILTER_VALIDATE_INT);
        $tutorId = filter_var($data['tutor_id'] ?? null, FILTER_VALIDATE_INT);
        $meetingDate = trim((string) ($data['meeting_date'] ?? ''));
        $meetingTime = trim((string) ($data['meeting_time'] ?? ''));
        $meetingType = $this->normalizeMeetingType((string) ($data['meeting_type'] ?? 'virtual'));
        $meetingLink = trim((string) ($data['meeting_link'] ?? ''));
        $meetingPlatform = trim((string) ($data['meeting_platform'] ?? ''));
        $meetingLocation = trim((string) ($data['meeting_location'] ?? ''));
        $outcome = trim((string) ($data['outcome'] ?? ''));
        $status = strtolower(trim((string) ($data['status'] ?? 'scheduled')));

        if ($requireId && ($id === false || $id <= 0)) {
            Response::json(["success" => false, "message" => "Valid id is required"], 400);
        }

        if ($studentId === false || $studentId <= 0 || $tutorId === false || $tutorId <= 0) {
            Response::json(["success" => false, "message" => "Valid student_id and tutor_id are required"], 400);
        }

        if (!$this->isValidDate($meetingDate) || !$this->isValidTime($meetingTime)) {
            Response::json(["success" => false, "message" => "meeting_date must be YYYY-MM-DD and meeting_time must be HH:MM[:SS]"], 400);
        }

        if (!in_array($meetingType, ['physical', 'virtual'], true)) {
            Response::json(["success" => false, "message" => "meeting_type must be physical or virtual"], 400);
        }

        if (!in_array($status, ['scheduled', 'completed', 'cancelled'], true)) {
            Response::json(["success" => false, "message" => "Invalid status"], 400);
        }

        if (strlen($meetingLink) > 255) {
            Response::json(["success" => false, "message" => "meeting_link is too long"], 400);
        }

        if (mb_strlen($outcome) > 5000) {
            Response::json(["success" => false, "message" => "outcome is too long"], 400);
        }

        if ($status === 'scheduled') {
            $meetingDateTime = strtotime($meetingDate . ' ' . $meetingTime);
            if ($meetingDateTime !== false && $meetingDateTime < (time() - 60)) {
                Response::json(["success" => false, "message" => "Scheduled meeting time cannot be in the past"], 400);
            }
        }

        if ($meetingType === 'physical') {
            if ($meetingLocation === '') {
                Response::json(["success" => false, "message" => "meeting_location is required for physical meetings"], 400);
            }
            $meetingLink = '';
            // Keep location visible without DB migration by prefixing outcome metadata.
            $outcome = "[location:$meetingLocation]" . ($outcome !== '' ? " $outcome" : '');
        }

        if ($meetingType === 'virtual') {
            if ($meetingPlatform === '' || $meetingLink === '') {
                Response::json(["success" => false, "message" => "meeting_platform and meeting_link are required for virtual meetings"], 400);
            }
            if (!filter_var($meetingLink, FILTER_VALIDATE_URL)) {
                Response::json(["success" => false, "message" => "meeting_link must be a valid URL for virtual meetings"], 400);
            }
            // Keep platform visible without DB migration by prefixing outcome metadata.
            $outcome = "[platform:$meetingPlatform]" . ($outcome !== '' ? " $outcome" : '');
        }

        return [
            'id' => $id,
            'student_id' => (int) $studentId,
            'tutor_id' => (int) $tutorId,
            'meeting_date' => $meetingDate,
            'meeting_time' => $meetingTime,
            'meeting_type' => $meetingType,
            'meeting_link' => $meetingLink,
            'outcome' => $outcome,
            'status' => $status
        ];
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
        $this->requireMeetingRole($user);
        $role = (string) ($user['role'] ?? '');
        $userId = (int) ($user['user_id'] ?? 0);
        $isAdmin = !empty($user['is_admin']);

        if ($role === 'student') {
            $studentId = $this->getStudentIdByUserId($userId);
            if ($studentId <= 0) {
                Response::json(["success" => false, "message" => "Student profile not found"], 404);
                return;
            }
            $stmt = $this->conn->prepare("
                SELECT meeting_id, student_id, tutor_id, meeting_date, meeting_time, meeting_type, meeting_link, outcome, status
                FROM meetings
                WHERE student_id = ?
                ORDER BY meeting_date DESC, meeting_time DESC
            ");
            if (!$stmt) {
                Response::json(["success" => false, "message" => "Failed to fetch meetings"], 500);
                return;
            }
            $stmt->bind_param("i", $studentId);
            if (!$stmt->execute()) {
                Response::json(["success" => false, "message" => "Failed to fetch meetings"], 500);
                return;
            }
            $result = $stmt->get_result();
        } elseif ($role === 'tutor') {
            $tutorId = $this->getTutorIdByUserId($userId);
            if ($tutorId <= 0) {
                Response::json(["success" => false, "message" => "Tutor profile not found"], 404);
                return;
            }
            $stmt = $this->conn->prepare("
                SELECT meeting_id, student_id, tutor_id, meeting_date, meeting_time, meeting_type, meeting_link, outcome, status
                FROM meetings
                WHERE tutor_id = ?
                ORDER BY meeting_date DESC, meeting_time DESC
            ");
            if (!$stmt) {
                Response::json(["success" => false, "message" => "Failed to fetch meetings"], 500);
                return;
            }
            $stmt->bind_param("i", $tutorId);
            if (!$stmt->execute()) {
                Response::json(["success" => false, "message" => "Failed to fetch meetings"], 500);
                return;
            }
            $result = $stmt->get_result();
        } else {
            if (!$isAdmin) {
                Response::json(["success" => false, "message" => "Admin only access"], 403);
                return;
            }
            $result = $this->conn->query("
                SELECT meeting_id, student_id, tutor_id, meeting_date, meeting_time, meeting_type, meeting_link, outcome, status
                FROM meetings
                ORDER BY meeting_date DESC, meeting_time DESC
            ");
            if (!$result) {
                Response::json(["success" => false, "message" => "Failed to fetch meetings"], 500);
                return;
            }
        }

        $data = [];
        while ($row = $result->fetch_assoc()) $data[] = $row;

        $this->safeLogActivity("Meeting List", "Fetched all meetings");
        Response::json(["success" => true, "data" => $data]);
    }

    public function create()
    {
        $user = $this->requireAuth();
        $this->requireMeetingRole($user);
        $role = (string) ($user['role'] ?? '');
        $isAdmin = !empty($user['is_admin']);
        if (!($role === 'tutor' || ($role === 'staff' && $isAdmin))) {
            Response::json(["success" => false, "message" => "Only tutor or admin staff can create meetings"], 403);
            return;
        }

        $data = $this->getRequestData();
        if ($data === null) {
            Response::json(["success" => false, "message" => "Invalid JSON body"], 400);
            return;
        }

        if ($role === 'tutor') {
            $tutorId = $this->getTutorIdByUserId((int) $user['user_id']);
            if ($tutorId <= 0) {
                Response::json(["success" => false, "message" => "Tutor profile not found"], 404);
                return;
            }
            $data['tutor_id'] = $tutorId;
        }

        $validated = $this->validateMeetingPayload($data, false);
        $studentId = $validated['student_id'];
        $tutorId = $validated['tutor_id'];
        $meetingDate = $validated['meeting_date'];
        $meetingTime = $validated['meeting_time'];
        $meetingType = $validated['meeting_type'];
        $meetingLink = $validated['meeting_link'];
        $outcome = $validated['outcome'];
        $status = $validated['status'];

        $stmt = $this->conn->prepare("
            INSERT INTO meetings (student_id, tutor_id, meeting_date, meeting_time, meeting_type, meeting_link, outcome, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to prepare meeting creation"], 500);
            return;
        }

        $stmt->bind_param("iissssss", $studentId, $tutorId, $meetingDate, $meetingTime, $meetingType, $meetingLink, $outcome, $status);
        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to create meeting"], 500);
            return;
        }

        $this->safeLogActivity("Meeting Create", "Created meeting for student ID: " . $studentId);
        Response::json(["success" => true, "message" => "Meeting created"], 201);
    }

    public function update()
    {
        $user = $this->requireAuth();
        $this->requireMeetingRole($user);
        $role = (string) ($user['role'] ?? '');
        $isAdmin = !empty($user['is_admin']);
        if ($role === 'student' || ($role === 'staff' && !$isAdmin)) {
            Response::json(["success" => false, "message" => "Only tutor or admin staff can update meetings"], 403);
            return;
        }

        $data = $this->getRequestData();
        if ($data === null) {
            Response::json(["success" => false, "message" => "Invalid JSON body"], 400);
            return;
        }

        $meetingId = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
        if ($meetingId === false || $meetingId <= 0) {
            Response::json(["success" => false, "message" => "Valid id is required"], 400);
            return;
        }

        if ($role === 'tutor') {
            $tutorId = $this->getTutorIdByUserId((int) $user['user_id']);
            if ($tutorId <= 0) {
                Response::json(["success" => false, "message" => "Tutor profile not found"], 404);
                return;
            }

            $ownStmt = $this->conn->prepare("SELECT tutor_id, student_id, meeting_date, meeting_time, meeting_type, meeting_link, status FROM meetings WHERE meeting_id = ? LIMIT 1");
            if (!$ownStmt) {
                Response::json(["success" => false, "message" => "Failed to validate meeting owner"], 500);
                return;
            }
            $ownStmt->bind_param("i", $meetingId);
            if (!$ownStmt->execute()) {
                Response::json(["success" => false, "message" => "Failed to validate meeting owner"], 500);
                return;
            }
            $existing = $ownStmt->get_result()->fetch_assoc();
            if (!$existing) {
                Response::json(["success" => false, "message" => "Meeting not found"], 404);
                return;
            }
            if ((int) $existing['tutor_id'] !== $tutorId) {
                Response::json(["success" => false, "message" => "You can only update your own meetings"], 403);
                return;
            }

            $data['tutor_id'] = $tutorId;
            $data['student_id'] = (int) $existing['student_id'];
            if (!isset($data['meeting_date'])) {
                $data['meeting_date'] = $existing['meeting_date'];
            }
            if (!isset($data['meeting_time'])) {
                $data['meeting_time'] = $existing['meeting_time'];
            }
            if (!isset($data['meeting_type'])) {
                $data['meeting_type'] = $existing['meeting_type'];
            }
            if (!isset($data['meeting_link'])) {
                $data['meeting_link'] = $existing['meeting_link'];
            }
            if (!isset($data['status'])) {
                $data['status'] = $existing['status'];
            }
        }

        $validated = $this->validateMeetingPayload($data, true);
        $id = $validated['id'];
        $studentId = $validated['student_id'];
        $tutorId = $validated['tutor_id'];
        $meetingDate = $validated['meeting_date'];
        $meetingTime = $validated['meeting_time'];
        $meetingType = $validated['meeting_type'];
        $meetingLink = $validated['meeting_link'];
        $outcome = $validated['outcome'];
        $status = $validated['status'];

        $stmt = $this->conn->prepare("
            UPDATE meetings
            SET student_id=?, tutor_id=?, meeting_date=?, meeting_time=?, meeting_type=?, meeting_link=?, outcome=?, status=?
            WHERE meeting_id=?
        ");
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to prepare meeting update"], 500);
            return;
        }

        $stmt->bind_param("iissssssi", $studentId, $tutorId, $meetingDate, $meetingTime, $meetingType, $meetingLink, $outcome, $status, $id);
        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to update meeting"], 500);
            return;
        }

        if ($stmt->affected_rows === 0) {
            Response::json(["success" => false, "message" => "Meeting not found or no changes applied"], 404);
            return;
        }

        $this->safeLogActivity("Meeting Update", "Updated meeting ID: " . $id);
        Response::json(["success" => true, "message" => "Meeting updated"]);
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

        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            Response::json(["success" => false, "message" => "Valid ID required"], 400);
            return;
        }

        $stmt = $this->conn->prepare("DELETE FROM meetings WHERE meeting_id=?");
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to prepare meeting deletion"], 500);
            return;
        }

        $stmt->bind_param("i", $id);
        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to delete meeting"], 500);
            return;
        }

        if ($stmt->affected_rows === 0) {
            Response::json(["success" => false, "message" => "Meeting not found"], 404);
            return;
        }

        $this->safeLogActivity("Meeting Delete", "Deleted meeting ID: " . $id);
        Response::json(["success" => true, "message" => "Meeting deleted"]);
    }
}
