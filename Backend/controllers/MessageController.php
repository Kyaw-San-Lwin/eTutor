<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/activityMiddleware.php';
require_once __DIR__ . '/../services/ValidationService.php';
require_once __DIR__ . '/../services/NotificationService.php';

class MessageController
{
    private $conn;
    private const ALLOWED_ROLES = ['student', 'tutor'];

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

    private function requireMessagingRole(array $user): void
    {
        $role = (string) ($user['role'] ?? '');
        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            Response::json(["success" => false, "message" => "Access denied"], 403);
        }
    }

    private function getUserRoleById(int $userId): ?string
    {
        $stmt = $this->conn->prepare("
            SELECT r.role_name
            FROM users u
            JOIN roles r ON u.role_id = r.role_id
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
        return $row['role_name'] ?? null;
    }

    private function getRequestData()
    {
        $data = json_decode(file_get_contents("php://input"), true);
        return is_array($data) ? $data : null;
    }

    private function safeLogActivity(string $page, string $activity): void
    {
        $user = $GLOBALS['auth_user'] ?? null;
        if (!is_array($user) || empty($user['user_id'])) {
            return;
        }

        logActivity($this->conn, (int) $user['user_id'], $page, $activity);
    }

    private function hasColumn(string $table, string $column): bool
    {
        $tableEscaped = $this->conn->real_escape_string($table);
        $columnEscaped = $this->conn->real_escape_string($column);
        $result = $this->conn->query("SHOW COLUMNS FROM `{$tableEscaped}` LIKE '{$columnEscaped}'");
        return $result && $result->num_rows > 0;
    }

    public function list()
    {
        $user = $this->requireAuth();
        $this->requireMessagingRole($user);

        $userId = (int) $user['user_id'];
        $withUserId = filter_var($_GET['with_user_id'] ?? null, FILTER_VALIDATE_INT);
        $pagination = ValidationService::paginationFromQuery(50, 200);
        $profilePhotoSelect = $this->hasColumn('users', 'profile_photo')
            ? ", su.profile_photo AS sender_profile_photo, ru.profile_photo AS receiver_profile_photo"
            : ", NULL AS sender_profile_photo, NULL AS receiver_profile_photo";

        if ($withUserId !== false && $withUserId > 0) {
            $stmt = $this->conn->prepare("
                SELECT m.message_id, m.sender_id, su.user_name AS sender_name, m.receiver_id, ru.user_name AS receiver_name,
                       m.message, m.sent_at, m.status
                       {$profilePhotoSelect}
                FROM messages m
                JOIN users su ON m.sender_id = su.user_id
                JOIN users ru ON m.receiver_id = ru.user_id
                WHERE (m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)
                ORDER BY m.sent_at DESC
                LIMIT ? OFFSET ?
            ");
            if (!$stmt) {
                Response::json(["success" => false, "message" => "Failed to fetch messages"], 500);
            }

            $limit = $pagination['limit'];
            $offset = $pagination['offset'];
            $stmt->bind_param("iiiiii", $userId, $withUserId, $withUserId, $userId, $limit, $offset);
        } else {
            $stmt = $this->conn->prepare("
                SELECT m.message_id, m.sender_id, su.user_name AS sender_name, m.receiver_id, ru.user_name AS receiver_name,
                       m.message, m.sent_at, m.status
                       {$profilePhotoSelect}
                FROM messages m
                JOIN users su ON m.sender_id = su.user_id
                JOIN users ru ON m.receiver_id = ru.user_id
                WHERE m.sender_id = ? OR m.receiver_id = ?
                ORDER BY m.sent_at DESC
                LIMIT ? OFFSET ?
            ");
            if (!$stmt) {
                Response::json(["success" => false, "message" => "Failed to fetch messages"], 500);
            }

            $limit = $pagination['limit'];
            $offset = $pagination['offset'];
            $stmt->bind_param("iiii", $userId, $userId, $limit, $offset);
        }

        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to fetch messages"], 500);
        }

        $result = $stmt->get_result();
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }

        $this->safeLogActivity("Message List", "Fetched messages");
        Response::json(["success" => true, "data" => $data, "meta" => $pagination]);
    }

    public function create()
    {
        $user = $this->requireAuth();
        $this->requireMessagingRole($user);

        $senderId = (int) $user['user_id'];

        $data = $this->getRequestData();
        if ($data === null) {
            Response::json(["success" => false, "message" => "Invalid JSON body"], 400);
        }

        $receiverId = filter_var($data['receiver_id'] ?? null, FILTER_VALIDATE_INT);
        $message = trim((string) ($data['message'] ?? ''));

        if ($receiverId === false || $receiverId <= 0 || $message === '') {
            Response::json(["success" => false, "message" => "Valid receiver_id and message are required"], 400);
        }

        if ($receiverId === $senderId) {
            Response::json(["success" => false, "message" => "Cannot send message to yourself"], 400);
        }

        $receiverRole = $this->getUserRoleById($receiverId);
        if ($receiverRole === null) {
            Response::json(["success" => false, "message" => "Receiver not found"], 404);
        }
        if (!in_array($receiverRole, self::ALLOWED_ROLES, true)) {
            Response::json(["success" => false, "message" => "Receiver role is not allowed for messaging"], 403);
        }

        if (mb_strlen($message) > 2000) {
            Response::json(["success" => false, "message" => "Message is too long"], 400);
        }

        $stmt = $this->conn->prepare("
            INSERT INTO messages (sender_id, receiver_id, message, status)
            VALUES (?, ?, ?, 'sent')
        ");
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to prepare message"], 500);
        }

        $stmt->bind_param("iis", $senderId, $receiverId, $message);
        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to send message"], 500);
        }

        try {
            $notifier = new NotificationService($this->conn);
            $notifier->sendMessageNotification($senderId, (int) $receiverId);
        } catch (Throwable $e) {
            // Non-blocking: message must still be delivered in-app even if email fails.
        }

        $this->safeLogActivity("Message Create", "Sent message to user ID: " . $receiverId);
        Response::json(["success" => true, "message" => "Message sent"], 201);
    }

    public function update()
    {
        $user = $this->requireAuth();
        $this->requireMessagingRole($user);

        $userId = (int) $user['user_id'];

        $data = $this->getRequestData();
        if ($data === null) {
            Response::json(["success" => false, "message" => "Invalid JSON body"], 400);
        }

        $messageId = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
        $status = trim((string) ($data['status'] ?? ''));

        if ($messageId === false || $messageId <= 0 || $status === '') {
            Response::json(["success" => false, "message" => "Valid id and status are required"], 400);
        }

        if (!in_array($status, ['read', 'sent'], true)) {
            Response::json(["success" => false, "message" => "Invalid status"], 400);
        }

        // Only receiver can update message status.
        $stmt = $this->conn->prepare("
            UPDATE messages
            SET status = ?
            WHERE message_id = ? AND receiver_id = ?
        ");
        if (!$stmt) {
            Response::json(["success" => false, "message" => "Failed to prepare message update"], 500);
        }

        $stmt->bind_param("sii", $status, $messageId, $userId);
        if (!$stmt->execute()) {
            Response::json(["success" => false, "message" => "Failed to update message"], 500);
        }

        if ($stmt->affected_rows === 0) {
            Response::json(["success" => false, "message" => "Message not found or no changes applied"], 404);
        }

        $this->safeLogActivity("Message Update", "Updated message ID: " . $messageId);
        Response::json(["success" => true, "message" => "Message updated"]);
    }
}
