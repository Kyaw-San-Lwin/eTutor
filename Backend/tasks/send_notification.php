<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only";
    exit(1);
}

$type = isset($argv[1]) ? preg_replace('/[^a-z_]/i', '', strtolower((string) $argv[1])) : '';
$arg1 = isset($argv[2]) ? (int) $argv[2] : 0;
$arg2 = isset($argv[3]) ? (int) $argv[3] : 0;

if ($type === '' || $arg1 <= 0 || $arg2 <= 0) {
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
$logFile = $logDir . '/notification_worker.log';
@file_put_contents(
    $logFile,
    '[' . date('Y-m-d H:i:s') . "] start type={$type} arg1={$arg1} arg2={$arg2}" . PHP_EOL,
    FILE_APPEND
);

try {
    $notifier = new NotificationService($conn);
    switch ($type) {
        case 'message':
            $notifier->sendMessageNotification($arg1, $arg2);
            break;
        case 'blog_comment':
            $notifier->sendBlogCommentNotification($arg1, $arg2);
            break;
        case 'document_comment':
            $notifier->sendDocumentCommentNotification($arg1, $arg2);
            break;
        case 'meeting_recording':
            $notifier->sendMeetingRecordingNotification($arg1, $arg2);
            break;
        default:
            throw new RuntimeException("Unsupported notification type: {$type}");
    }

    @file_put_contents(
        $logFile,
        '[' . date('Y-m-d H:i:s') . "] done type={$type} arg1={$arg1} arg2={$arg2}" . PHP_EOL,
        FILE_APPEND
    );
    exit(0);
} catch (Throwable $e) {
    error_log("Async notification failed ({$type}): " . $e->getMessage());
    @file_put_contents(
        $logFile,
        '[' . date('Y-m-d H:i:s') . '] error type=' . $type . ' message=' . $e->getMessage() . PHP_EOL,
        FILE_APPEND
    );
    exit(1);
}

