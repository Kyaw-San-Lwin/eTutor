<?php
declare(strict_types=1);

/**
 * Mail debug utility for eTutor.
 *
 * Usage (browser):
 *   http://localhost/eTutor/Backend/tasks/mail_debug.php?to=you@example.com&send=1
 *
 * Usage (CLI):
 *   php Backend/tasks/mail_debug.php to=you@example.com send=1
 */

header('Content-Type: text/plain; charset=UTF-8');

function out(string $line): void
{
    echo $line . PHP_EOL;
}

function mask(string $value, int $keepStart = 2, int $keepEnd = 2): string
{
    $len = strlen($value);
    if ($len <= ($keepStart + $keepEnd)) {
        return str_repeat('*', $len);
    }
    return substr($value, 0, $keepStart) . str_repeat('*', $len - $keepStart - $keepEnd) . substr($value, -$keepEnd);
}

function boolString($value): string
{
    return $value ? 'true' : 'false';
}

function envBool(string $key, bool $default = false): bool
{
    if (array_key_exists($key, $_ENV)) {
        $v = $_ENV[$key];
        if (is_bool($v)) return $v;
        $raw = trim((string) $v);
        if ($raw !== '') {
            return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
        }
    }

    $raw = getenv($key);
    if ($raw === false || $raw === null || $raw === '') {
        return $default;
    }
    return in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes', 'on'], true);
}

function loadEnv(string $envPath): void
{
    if (!file_exists($envPath)) {
        return;
    }

    $env = parse_ini_file($envPath, false, INI_SCANNER_TYPED);
    if (!is_array($env)) {
        return;
    }

    foreach ($env as $key => $value) {
        $_ENV[$key] = $value;
        if (is_bool($value)) {
            putenv($key . '=' . ($value ? 'true' : 'false'));
        } else {
            putenv($key . '=' . $value);
        }
    }
}

function getInput(string $key, string $default = ''): string
{
    if (PHP_SAPI === 'cli') {
        global $argv;
        if (is_array($argv)) {
            foreach ($argv as $arg) {
                if (strpos($arg, $key . '=') === 0) {
                    return (string) substr($arg, strlen($key) + 1);
                }
            }
        }
    }
    return isset($_GET[$key]) ? trim((string) $_GET[$key]) : $default;
}

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    out('[ERROR] Unable to resolve project root.');
    exit(1);
}

loadEnv($root . DIRECTORY_SEPARATOR . '.env');

$autoload = $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    out('[WARN] vendor/autoload.php not found.');
}

if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
    $phpMailerBase = $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'phpmailer' . DIRECTORY_SEPARATOR . 'phpmailer' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;
    $phpMailerFile = $phpMailerBase . 'PHPMailer.php';
    $smtpFile = $phpMailerBase . 'SMTP.php';
    $exceptionFile = $phpMailerBase . 'Exception.php';
    if (file_exists($phpMailerFile) && file_exists($smtpFile) && file_exists($exceptionFile)) {
        require_once $exceptionFile;
        require_once $smtpFile;
        require_once $phpMailerFile;
    }
}

out('=== eTutor Mail Debug ===');
out('Date: ' . date('Y-m-d H:i:s'));
out('Mode: ' . PHP_SAPI);
out('');

$mailEnabled = envBool('ETUTOR_MAIL_ENABLED', false);
$allocMailEnabled = envBool('ETUTOR_ALLOCATION_MAIL_ENABLED', true);
$transport = strtolower((string) (getenv('ETUTOR_MAIL_TRANSPORT') ?: 'smtp'));
$from = (string) (getenv('ETUTOR_MAIL_FROM') ?: '');
$fromName = (string) (getenv('ETUTOR_MAIL_FROM_NAME') ?: '');
$smtpHost = (string) (getenv('ETUTOR_SMTP_HOST') ?: '');
$smtpPort = (int) (getenv('ETUTOR_SMTP_PORT') ?: 587);
$smtpSecure = (string) (getenv('ETUTOR_SMTP_SECURE') ?: 'tls');
$smtpUsername = (string) (getenv('ETUTOR_SMTP_USERNAME') ?: '');
$smtpPassword = (string) (getenv('ETUTOR_SMTP_PASSWORD') ?: '');

out('ETUTOR_MAIL_ENABLED: ' . boolString($mailEnabled));
out('ETUTOR_ALLOCATION_MAIL_ENABLED: ' . boolString($allocMailEnabled));
out('ETUTOR_MAIL_TRANSPORT: ' . $transport);
out('ETUTOR_MAIL_FROM: ' . $from);
out('ETUTOR_MAIL_FROM_NAME: ' . $fromName);
out('ETUTOR_SMTP_HOST: ' . $smtpHost);
out('ETUTOR_SMTP_PORT: ' . (string) $smtpPort);
out('ETUTOR_SMTP_SECURE: ' . $smtpSecure);
out('ETUTOR_SMTP_USERNAME: ' . ($smtpUsername !== '' ? mask($smtpUsername, 3, 8) : '(empty)'));
out('ETUTOR_SMTP_PASSWORD: ' . ($smtpPassword !== '' ? mask($smtpPassword, 1, 1) : '(empty)'));
out('');

$hasPhpMailer = class_exists(\PHPMailer\PHPMailer\PHPMailer::class);
out('PHPMailer class available: ' . boolString($hasPhpMailer));

if ($transport === 'smtp' && !$hasPhpMailer) {
    out('[ERROR] SMTP transport selected but PHPMailer is missing.');
}

$to = getInput('to', $smtpUsername);
$send = getInput('send', '0') === '1';

out('Test recipient (to): ' . ($to !== '' ? $to : '(empty)'));
out('Send test email now: ' . boolString($send));
out('');

if (!$send) {
    out('Tip: add ?send=1&to=you@example.com to perform a live send test.');
    exit(0);
}

if (!$mailEnabled) {
    out('[ERROR] ETUTOR_MAIL_ENABLED is false. Turn it on first.');
    exit(1);
}

if ($to === '') {
    out('[ERROR] No recipient provided. Use ?to=you@example.com');
    exit(1);
}

if ($transport === 'smtp') {
    if (!$hasPhpMailer) {
        out('[ERROR] Cannot send via SMTP without PHPMailer.');
        exit(1);
    }
    if ($smtpHost === '' || $smtpUsername === '' || $smtpPassword === '') {
        out('[ERROR] SMTP config incomplete (host/username/password).');
        exit(1);
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->Port = $smtpPort > 0 ? $smtpPort : 587;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUsername;
        $mail->Password = $smtpPassword;
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 8;
        $mail->SMTPDebug = 0;

        if (strtolower($smtpSecure) === 'ssl') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif (strtolower($smtpSecure) === 'none') {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        } else {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->setFrom($from !== '' ? $from : $smtpUsername, $fromName !== '' ? $fromName : 'eTutor');
        $mail->addAddress($to);
        $mail->isHTML(false);
        $mail->Subject = 'eTutor SMTP debug test';
        $mail->Body = 'This is a test email from Backend/tasks/mail_debug.php at ' . date('Y-m-d H:i:s');

        $ok = $mail->send();
        out($ok ? '[OK] Test email sent successfully.' : '[ERROR] send() returned false.');
        exit($ok ? 0 : 1);
    } catch (\Throwable $e) {
        out('[ERROR] SMTP send failed: ' . $e->getMessage());
        exit(1);
    }
}

// mail() transport path
$headers = "From: " . ($from !== '' ? $from : 'no-reply@etutor.local') . "\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8";
$ok = @mail($to, 'eTutor mail() debug test', 'mail() transport test at ' . date('Y-m-d H:i:s'), $headers);
out($ok ? '[OK] mail() accepted by PHP.' : '[ERROR] mail() failed (sendmail not configured or blocked).');
exit($ok ? 0 : 1);
