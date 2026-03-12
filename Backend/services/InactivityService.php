<?php

require_once __DIR__ . '/NotificationService.php';

class InactivityService
{
    private $conn;
    private $notifier;

    public function __construct($conn)
    {
        $this->conn = $conn;
        $this->notifier = new NotificationService($conn);
    }

    public function listInactiveAllocations(int $days): array
    {
        if ($days < 1) {
            return [];
        }

        $sql = "
            SELECT
                a.allocation_id,
                a.student_id,
                a.tutor_id,
                su.user_id AS student_user_id,
                su.user_name AS student_name,
                su.email AS student_email,
                tu.user_id AS tutor_user_id,
                tu.user_name AS tutor_name,
                tu.email AS tutor_email,
                GREATEST(
                    COALESCE(m.last_message_at, '1970-01-01 00:00:00'),
                    COALESCE(mt.last_meeting_at, '1970-01-01 00:00:00'),
                    COALESCE(d.last_document_at, '1970-01-01 00:00:00'),
                    COALESCE(a.allocated_date, '1970-01-01 00:00:00')
                ) AS last_interaction_at
            FROM allocations a
            JOIN students s ON a.student_id = s.student_id
            JOIN users su ON s.user_id = su.user_id
            JOIN tutors t ON a.tutor_id = t.tutor_id
            JOIN users tu ON t.user_id = tu.user_id
            LEFT JOIN (
                SELECT
                    LEAST(sender_id, receiver_id) AS u1,
                    GREATEST(sender_id, receiver_id) AS u2,
                    MAX(sent_at) AS last_message_at
                FROM messages
                GROUP BY LEAST(sender_id, receiver_id), GREATEST(sender_id, receiver_id)
            ) m ON LEAST(su.user_id, tu.user_id) = m.u1
                AND GREATEST(su.user_id, tu.user_id) = m.u2
            LEFT JOIN (
                SELECT
                    student_id,
                    tutor_id,
                    MAX(TIMESTAMP(meeting_date, COALESCE(meeting_time, '00:00:00'))) AS last_meeting_at
                FROM meetings
                GROUP BY student_id, tutor_id
            ) mt ON a.student_id = mt.student_id
                AND a.tutor_id = mt.tutor_id
            LEFT JOIN (
                SELECT
                    student_id,
                    MAX(uploaded_at) AS last_document_at
                FROM documents
                GROUP BY student_id
            ) d ON a.student_id = d.student_id
            WHERE a.status = 'active'
            HAVING DATEDIFF(NOW(), last_interaction_at) >= ?
            ORDER BY last_interaction_at ASC
        ";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param("i", $days);
        if (!$stmt->execute()) {
            return [];
        }

        $result = $stmt->get_result();
        if (!$result) {
            return [];
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function sendWarnings(int $days): array
    {
        $rows = $this->listInactiveAllocations($days);
        $sent = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $studentEmail = (string) ($row['student_email'] ?? '');
            $tutorEmail = (string) ($row['tutor_email'] ?? '');

            if ($studentEmail === '' || $tutorEmail === '') {
                $failed++;
                continue;
            }

            try {
                $this->notifier->sendInactivityWarning($studentEmail, $tutorEmail, $days);
                $sent++;
            } catch (Throwable $e) {
                $failed++;
                error_log("Inactivity warning failed: " . $e->getMessage());
            }
        }

        return [
            "days" => $days,
            "candidates" => count($rows),
            "sent" => $sent,
            "failed" => $failed
        ];
    }
}
