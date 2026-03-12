<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../services/InactivityService.php';

$days = 28;
if (isset($argv[1]) && is_numeric($argv[1])) {
    $days = (int) $argv[1];
}

if ($days < 1) {
    fwrite(STDERR, "days must be positive\n");
    exit(1);
}

$service = new InactivityService($conn);
$summary = $service->sendWarnings($days);

echo json_encode([
    "success" => true,
    "task" => "run_inactivity_warning",
    "summary" => $summary
], JSON_PRETTY_PRINT) . PHP_EOL;
