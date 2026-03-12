<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../services/InactivityService.php';

$s = new InactivityService($conn);

$t0 = microtime(true);
$a = $s->listInactiveAllocations(7);
$t1 = microtime(true);

$b = $s->listInactiveAllocations(28);
$t2 = microtime(true);

echo json_encode([
    "count_7" => count($a),
    "count_28" => count($b),
    "ms_7" => round(($t1 - $t0) * 1000, 2),
    "ms_28" => round(($t2 - $t1) * 1000, 2)
], JSON_PRETTY_PRINT) . PHP_EOL;
