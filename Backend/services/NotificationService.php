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
