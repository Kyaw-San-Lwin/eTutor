<?php

class NotificationService
{
    private $conn;
    private $mailEnabled;
    private $mailFrom;

    public function __construct($conn)
    {
        $this->conn = $conn;
        $this->mailEnabled = strtolower((string) getenv('ETUTOR_MAIL_ENABLED')) === 'true';
        $this->mailFrom = getenv('ETUTOR_MAIL_FROM') ?: 'no-reply@etutor.local';
    }

    private function getUserByStudentId(int $studentId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT u.user_id, u.user_name, u.email
            FROM students s
            JOIN users u ON s.user_id = u.user_id
            WHERE s.student_id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param("i", $studentId);
        if (!$stmt->execute()) {
            return null;
        }

        $result = $stmt->get_result();
        return $result && $result->num_rows > 0 ? $result->fetch_assoc() : null;
    }

    private function getUserByTutorId(int $tutorId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT u.user_id, u.user_name, u.email
            FROM tutors t
            JOIN users u ON t.user_id = u.user_id
            WHERE t.tutor_id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param("i", $tutorId);
        if (!$stmt->execute()) {
            return null;
        }

        $result = $stmt->get_result();
        return $result && $result->num_rows > 0 ? $result->fetch_assoc() : null;
    }

    private function sendMail(string $to, string $subject, string $message): bool
    {
        if (!$this->mailEnabled) {
            // Skeleton mode: keep notifications observable in logs during development.
            error_log("NOTIFICATION_DISABLED to={$to} subject={$subject} message={$message}");
            return true;
        }

        $headers = "From: {$this->mailFrom}\r\nContent-Type: text/plain; charset=UTF-8";
        $sent = @mail($to, $subject, $message, $headers);
        if (!$sent) {
            error_log("NOTIFICATION_SEND_FAILED to={$to} subject={$subject}");
        }
        return $sent;
    }

    public function sendAllocationNotification(int $studentId, int $tutorId, string $event = 'allocated'): void
    {
        $student = $this->getUserByStudentId($studentId);
        $tutor = $this->getUserByTutorId($tutorId);

        if (!$student || !$tutor) {
            error_log("NOTIFICATION_SKIP allocation_missing_party student_id={$studentId} tutor_id={$tutorId}");
            return;
        }

        $subjectStudent = "Personal Tutor {$event}";
        $bodyStudent = "Hello {$student['user_name']}, your personal tutor is {$tutor['user_name']}.";

        $subjectTutor = "Student {$event}";
        $bodyTutor = "Hello {$tutor['user_name']}, student {$student['user_name']} is now assigned to you.";

        $this->sendMail($student['email'], $subjectStudent, $bodyStudent);
        $this->sendMail($tutor['email'], $subjectTutor, $bodyTutor);
    }

    public function sendInactivityWarning(string $studentEmail, string $tutorEmail, int $days): void
    {
        $subject = "Inactivity warning ({$days} days)";
        $message = "No interaction has been recorded for {$days} days.";
        $this->sendMail($studentEmail, $subject, $message);
        $this->sendMail($tutorEmail, $subject, $message);
    }
}
