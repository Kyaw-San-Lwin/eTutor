<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only";
    exit(1);
}

$studentId = isset($argv[1]) ? (int) $argv[1] : 0;
$tutorId = isset($argv[2]) ? (int) $argv[2] : 0;
$event = isset($argv[3]) ? preg_replace('/[^a-z_]/i', '', (string) $argv[3]) : 'allocated';

if ($studentId <= 0 || $tutorId <= 0) {
    fwrite(STDERR, "Invalid arguments.\n");
    exit(1);
}

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Unable to resolve project root.\n");
    exit(1);
}

$envPath = $root . DIRECTORY_SEPARATOR . '.env';
if (file_exists($envPath)) {
    $env = parse_ini_file($envPath, false, INI_SCANNER_TYPED);
    if (is_array($env)) {
        foreach ($env as $key => $value) {
            $_ENV[$key] = $value;
            if (is_bool($value)) {
                putenv($key . '=' . ($value ? 'true' : 'false'));
            } else {
                putenv($key . '=' . $value);
            }
        }
    }
}

require_once $root . '/config/database.php';
require_once $root . '/services/NotificationService.php';

$logDir = $root . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
}
$logFile = $logDir . '/allocation_mail_worker.log';
$stamp = date('Y-m-d H:i:s');
@file_put_contents(
    $logFile,
    "[{$stamp}] start student_id={$studentId} tutor_id={$tutorId} event={$event}" . PHP_EOL,
    FILE_APPEND
);

try {
    $notifier = new NotificationService($conn);
    $notifier->sendAllocationNotification($studentId, $tutorId, $event ?: 'allocated');
    @file_put_contents(
        $logFile,
        "[" . date('Y-m-d H:i:s') . "] done student_id={$studentId} tutor_id={$tutorId} event={$event}" . PHP_EOL,
        FILE_APPEND
    );
    exit(0);
} catch (Throwable $e) {
    error_log("Async allocation notification failed: " . $e->getMessage());
    @file_put_contents(
        $logFile,
        "[" . date('Y-m-d H:i:s') . "] error " . $e->getMessage() . PHP_EOL,
        FILE_APPEND
    );
    exit(1);
}
