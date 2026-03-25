<?php

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

class NotificationService
{
    private $conn;
    private $mailEnabled;
    private $mailFrom;
    private $mailFromName;
    private $mailTransport;
    private $smtpHost;
    private $smtpPort;
    private $smtpUsername;
    private $smtpPassword;
    private $smtpSecure;

    private function envBool(string $key, bool $default = false): bool
    {
        $raw = getenv($key);
        if ($raw === false || $raw === null || $raw === '') {
            return $default;
        }
        $value = strtolower(trim((string) $raw));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    public function __construct($conn)
    {
        $this->conn = $conn;
        $this->mailEnabled = $this->envBool('ETUTOR_MAIL_ENABLED', false);
        $this->mailFrom = getenv('ETUTOR_MAIL_FROM') ?: 'no-reply@etutor.local';
        $this->mailFromName = getenv('ETUTOR_MAIL_FROM_NAME') ?: 'eTutor';
        $this->mailTransport = strtolower((string) (getenv('ETUTOR_MAIL_TRANSPORT') ?: 'smtp'));
        $this->smtpHost = (string) (getenv('ETUTOR_SMTP_HOST') ?: '');
        $this->smtpPort = (int) (getenv('ETUTOR_SMTP_PORT') ?: 587);
        $this->smtpUsername = (string) (getenv('ETUTOR_SMTP_USERNAME') ?: '');
        $this->smtpPassword = (string) (getenv('ETUTOR_SMTP_PASSWORD') ?: '');
        $this->smtpSecure = strtolower((string) (getenv('ETUTOR_SMTP_SECURE') ?: 'tls')); // tls | ssl | none
    }

    private function getUserByStudentId(int $studentId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT
                u.user_id,
                u.user_name,
                u.email,
                COALESCE(NULLIF(s.full_name, ''), u.user_name) AS display_name
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
            SELECT
                u.user_id,
                u.user_name,
                u.email,
                COALESCE(NULLIF(t.full_name, ''), u.user_name) AS display_name
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

        if ($this->mailTransport === 'smtp') {
            return $this->sendViaSmtp($to, $subject, $message);
        }

        $headers = "From: {$this->mailFrom}\r\nContent-Type: text/plain; charset=UTF-8";
        $sent = @mail($to, $subject, $message, $headers);
        if (!$sent) {
            error_log("NOTIFICATION_SEND_FAILED to={$to} subject={$subject}");
        }
        return $sent;
    }

    private function sendViaSmtp(string $to, string $subject, string $message): bool
    {
        if (!class_exists(PHPMailer::class)) {
            error_log("NOTIFICATION_SEND_FAILED reason=phpmailer_missing");
            return false;
        }

        if ($this->smtpHost === '' || $this->smtpUsername === '' || $this->smtpPassword === '') {
            error_log("NOTIFICATION_SEND_FAILED reason=smtp_config_missing");
            return false;
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $this->smtpHost;
            $mail->Port = $this->smtpPort > 0 ? $this->smtpPort : 587;
            $mail->SMTPAuth = true;
            $mail->Username = $this->smtpUsername;
            $mail->Password = $this->smtpPassword;
            $mail->CharSet = 'UTF-8';

            if ($this->smtpSecure === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($this->smtpSecure === 'none') {
                $mail->SMTPSecure = false;
                $mail->SMTPAutoTLS = false;
            } else {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mail->setFrom($this->mailFrom, $this->mailFromName);
            $mail->addAddress($to);
            $mail->isHTML(false);
            $mail->Subject = $subject;
            $mail->Body = $message;

            return $mail->send();
        } catch (PHPMailerException $e) {
            error_log("NOTIFICATION_SEND_FAILED to={$to} subject={$subject} error=" . $e->getMessage());
            return false;
        }
    }

    public function sendAllocationNotification(int $studentId, int $tutorId, string $event = 'allocated'): void
    {
        $student = $this->getUserByStudentId($studentId);
        $tutor = $this->getUserByTutorId($tutorId);

        if (!$student || !$tutor) {
            error_log("NOTIFICATION_SKIP allocation_missing_party student_id={$studentId} tutor_id={$tutorId}");
            return;
        }

        $studentName = (string) ($student['display_name'] ?? $student['user_name'] ?? 'Student');
        $tutorName = (string) ($tutor['display_name'] ?? $tutor['user_name'] ?? 'Tutor');

        $subjectStudent = "Personal Tutor {$event}";
        $bodyStudent = "Hello {$studentName}, your personal tutor is {$tutorName}.";

        $subjectTutor = "Student {$event}";
        $bodyTutor = "Hello {$tutorName}, student {$studentName} is now assigned to you.";

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

    public function sendPasswordResetToken(string $email, string $userName, string $token, int $expiresInMinutes): bool
    {
        $subject = "eTutor password reset";
        $message = "Hello {$userName},\n\n"
            . "Use this reset token to change your password:\n"
            . "{$token}\n\n"
            . "This token expires in {$expiresInMinutes} minutes.\n\n"
            . "If you did not request this, ignore this email.";

        return $this->sendMail($email, $subject, $message);
    }
}
