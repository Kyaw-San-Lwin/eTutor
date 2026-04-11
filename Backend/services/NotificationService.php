<?php

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

if (!class_exists(PHPMailer::class)) {
    $phpMailerBase = __DIR__ . '/../vendor/phpmailer/phpmailer/src/';
    $phpMailerFile = $phpMailerBase . 'PHPMailer.php';
    $smtpFile = $phpMailerBase . 'SMTP.php';
    $exceptionFile = $phpMailerBase . 'Exception.php';

    if (file_exists($phpMailerFile)) {
        require_once $exceptionFile;
        require_once $smtpFile;
        require_once $phpMailerFile;
    }
}

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
    private $mailLogFile;

    private function envBool(string $key, bool $default = false): bool
    {
        if (array_key_exists($key, $_ENV)) {
            $envValue = $_ENV[$key];
            if (is_bool($envValue)) {
                return $envValue;
            }
            $raw = trim((string) $envValue);
            if ($raw !== '') {
                $value = strtolower($raw);
                return in_array($value, ['1', 'true', 'yes', 'on'], true);
            }
        }

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
        $logDir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        $this->mailLogFile = $logDir . DIRECTORY_SEPARATOR . 'mail_delivery.log';
    }

    private function mailLog(string $line): void
    {
        @file_put_contents(
            $this->mailLogFile,
            '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL,
            FILE_APPEND
        );
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

    private function getUserByUserId(int $userId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT
                u.user_id,
                u.user_name,
                u.email,
                COALESCE(
                    NULLIF(s.full_name, ''),
                    NULLIF(t.full_name, ''),
                    NULLIF(sf.full_name, ''),
                    u.user_name
                ) AS display_name
            FROM users u
            LEFT JOIN students s ON s.user_id = u.user_id
            LEFT JOIN tutors t ON t.user_id = u.user_id
            LEFT JOIN staff sf ON sf.user_id = u.user_id
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
        return $result && $result->num_rows > 0 ? $result->fetch_assoc() : null;
    }

    private function sendMail(string $to, string $subject, string $message): bool
    {
        $this->mailLog("send_attempt transport={$this->mailTransport} to={$to} subject={$subject}");

        if (!$this->mailEnabled) {
            // Skeleton mode: keep notifications observable in logs during development.
            error_log("NOTIFICATION_DISABLED to={$to} subject={$subject} message={$message}");
            $this->mailLog("send_skip reason=mail_disabled to={$to}");
            return true;
        }

        if ($this->mailTransport === 'smtp') {
            $sent = $this->sendViaSmtp($to, $subject, $message);
            $this->mailLog("send_result transport=smtp to={$to} success=" . ($sent ? 'true' : 'false'));
            return $sent;
        }

        $headers = "From: {$this->mailFrom}\r\nContent-Type: text/plain; charset=UTF-8";
        $sent = @mail($to, $subject, $message, $headers);
        if (!$sent) {
            error_log("NOTIFICATION_SEND_FAILED to={$to} subject={$subject}");
            $this->mailLog("send_result transport=mail to={$to} success=false");
        } else {
            $this->mailLog("send_result transport=mail to={$to} success=true");
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

            $fromAddress = $this->mailFrom;
            $fromIsValid = filter_var($fromAddress, FILTER_VALIDATE_EMAIL) !== false;
            $fromLooksLocal = str_ends_with(strtolower($fromAddress), '.local');
            if (!$fromIsValid || $fromLooksLocal) {
                $fromAddress = $this->smtpUsername;
            }

            $mail->setFrom($fromAddress, $this->mailFromName);
            if ($fromAddress !== $this->mailFrom && filter_var($this->mailFrom, FILTER_VALIDATE_EMAIL)) {
                $mail->addReplyTo($this->mailFrom, $this->mailFromName);
            }
            $mail->addAddress($to);
            $mail->isHTML(false);
            $mail->Subject = $subject;
            $mail->Body = $message;

            return $mail->send();
        } catch (PHPMailerException $e) {
            error_log("NOTIFICATION_SEND_FAILED to={$to} subject={$subject} error=" . $e->getMessage());
            $this->mailLog("send_error transport=smtp to={$to} error=" . $e->getMessage());
            return false;
        }
    }

    public function sendAllocationNotification(int $studentId, int $tutorId, string $event = 'allocated'): void
    {
        $student = $this->getUserByStudentId($studentId);
        $tutor = $this->getUserByTutorId($tutorId);

        if (!$student || !$tutor) {
            error_log("NOTIFICATION_SKIP allocation_missing_party student_id={$studentId} tutor_id={$tutorId}");
            $this->mailLog("allocation_skip student_id={$studentId} tutor_id={$tutorId} reason=missing_party");
            return;
        }

        $studentName = (string) ($student['display_name'] ?? $student['user_name'] ?? 'Student');
        $tutorName = (string) ($tutor['display_name'] ?? $tutor['user_name'] ?? 'Tutor');

        $subjectStudent = "Personal Tutor {$event}";
        $bodyStudent = "Hello {$studentName}, your personal tutor is {$tutorName}.";

        $subjectTutor = "Student {$event}";
        $bodyTutor = "Hello {$tutorName}, student {$studentName} is now assigned to you.";

        $this->mailLog("allocation_recipients student_id={$studentId} student_email={$student['email']} tutor_id={$tutorId} tutor_email={$tutor['email']} event={$event}");
        $this->sendMail($student['email'], $subjectStudent, $bodyStudent);
        $this->sendMail($tutor['email'], $subjectTutor, $bodyTutor);
    }

    public function sendInactivityWarning(string $studentEmail, string $tutorEmail, int $days): array
    {
        $subject = "Inactivity warning ({$days} days)";
        $message = "No interaction has been recorded for {$days} days.";
        $studentSent = $this->sendMail($studentEmail, $subject, $message);
        $tutorSent = $this->sendMail($tutorEmail, $subject, $message);

        return [
            "student_sent" => $studentSent,
            "tutor_sent" => $tutorSent,
            "all_sent" => ($studentSent && $tutorSent)
        ];
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

    public function sendMessageNotification(int $senderUserId, int $receiverUserId): void
    {
        $sender = $this->getUserByUserId($senderUserId);
        $receiver = $this->getUserByUserId($receiverUserId);
        if (!$sender || !$receiver || empty($receiver['email'])) {
            $this->mailLog("message_skip sender_user_id={$senderUserId} receiver_user_id={$receiverUserId} reason=missing_party");
            return;
        }

        $senderName = (string) ($sender['display_name'] ?? $sender['user_name'] ?? 'User');
        $receiverName = (string) ($receiver['display_name'] ?? $receiver['user_name'] ?? 'User');
        $subject = "New message notification";
        $message = "Hello {$receiverName}, you have a new message from {$senderName} in eTutor.";

        $this->sendMail((string) $receiver['email'], $subject, $message);
    }

    public function sendBlogCommentNotification(int $postId, int $commenterUserId): void
    {
        $stmt = $this->conn->prepare("
            SELECT p.blog_id, p.user_id, u.email
            FROM blog_posts p
            JOIN users u ON p.user_id = u.user_id
            WHERE p.blog_id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param("i", $postId);
        if (!$stmt->execute()) {
            return;
        }
        $result = $stmt->get_result();
        $post = $result && $result->num_rows > 0 ? $result->fetch_assoc() : null;
        if (!$post) {
            return;
        }

        $ownerUserId = (int) ($post['user_id'] ?? 0);
        $ownerEmail = (string) ($post['email'] ?? '');
        if ($ownerUserId <= 0 || $ownerEmail === '' || $ownerUserId === $commenterUserId) {
            return;
        }

        $commenter = $this->getUserByUserId($commenterUserId);
        $owner = $this->getUserByUserId($ownerUserId);
        if (!$commenter || !$owner) {
            return;
        }

        $commenterName = (string) ($commenter['display_name'] ?? $commenter['user_name'] ?? 'User');
        $ownerName = (string) ($owner['display_name'] ?? $owner['user_name'] ?? 'User');
        $subject = "New comment on your blog post";
        $message = "Hello {$ownerName}, {$commenterName} commented on your blog post in eTutor.";

        $this->sendMail($ownerEmail, $subject, $message);
    }

    public function sendDocumentCommentNotification(int $documentId, int $tutorId): void
    {
        $stmt = $this->conn->prepare("
            SELECT d.document_id, s.student_id, su.user_id AS student_user_id, su.email AS student_email
            FROM documents d
            JOIN students s ON d.student_id = s.student_id
            JOIN users su ON s.user_id = su.user_id
            WHERE d.document_id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param("i", $documentId);
        if (!$stmt->execute()) {
            return;
        }
        $result = $stmt->get_result();
        $doc = $result && $result->num_rows > 0 ? $result->fetch_assoc() : null;
        if (!$doc) {
            return;
        }

        $studentUserId = (int) ($doc['student_user_id'] ?? 0);
        $studentEmail = (string) ($doc['student_email'] ?? '');
        if ($studentUserId <= 0 || $studentEmail === '') {
            return;
        }

        $tutor = $this->getUserByTutorId($tutorId);
        $student = $this->getUserByUserId($studentUserId);
        if (!$tutor || !$student) {
            return;
        }

        $tutorName = (string) ($tutor['display_name'] ?? $tutor['user_name'] ?? 'Tutor');
        $studentName = (string) ($student['display_name'] ?? $student['user_name'] ?? 'Student');
        $subject = "New document feedback notification";
        $message = "Hello {$studentName}, your tutor {$tutorName} added feedback to one of your uploaded documents in eTutor.";

        $this->sendMail($studentEmail, $subject, $message);
    }

    public function sendMeetingRecordingNotification(int $meetingId, int $uploaderUserId): void
    {
        $stmt = $this->conn->prepare("
            SELECT
                m.meeting_id,
                su.user_id AS student_user_id,
                su.email AS student_email,
                tu.user_id AS tutor_user_id,
                tu.email AS tutor_email
            FROM meetings m
            JOIN students s ON m.student_id = s.student_id
            JOIN users su ON s.user_id = su.user_id
            JOIN tutors t ON m.tutor_id = t.tutor_id
            JOIN users tu ON t.user_id = tu.user_id
            WHERE m.meeting_id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param("i", $meetingId);
        if (!$stmt->execute()) {
            return;
        }
        $result = $stmt->get_result();
        $meeting = $result && $result->num_rows > 0 ? $result->fetch_assoc() : null;
        if (!$meeting) {
            return;
        }

        $uploader = $this->getUserByUserId($uploaderUserId);
        $student = $this->getUserByUserId((int) ($meeting['student_user_id'] ?? 0));
        $tutor = $this->getUserByUserId((int) ($meeting['tutor_user_id'] ?? 0));
        if (!$student || !$tutor) {
            return;
        }

        $uploaderName = (string) ($uploader['display_name'] ?? $uploader['user_name'] ?? 'Your tutor');
        $studentName = (string) ($student['display_name'] ?? $student['user_name'] ?? 'Student');
        $studentEmail = (string) ($meeting['student_email'] ?? '');
        if ($studentEmail !== '') {
            $this->sendMail($studentEmail, "Meeting recording uploaded", "Hello {$studentName}, {$uploaderName} uploaded a meeting recording in eTutor.");
        }

        $tutorUserId = (int) ($meeting['tutor_user_id'] ?? 0);
        $tutorEmail = (string) ($meeting['tutor_email'] ?? '');
        if ($tutorEmail !== '' && $tutorUserId > 0 && $tutorUserId !== $uploaderUserId) {
            $tutorName = (string) ($tutor['display_name'] ?? $tutor['user_name'] ?? 'Tutor');
            $this->sendMail($tutorEmail, "Meeting recording uploaded", "Hello {$tutorName}, a meeting recording was uploaded for one of your meetings in eTutor.");
        }
    }
}
