<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit();
}

// ========================================
// REQUEST ID + API VERSION
// ========================================

$requestId = 'req_' . bin2hex(random_bytes(8));
header("X-Request-ID: {$requestId}");
$GLOBALS['request_id'] = $requestId;

define("API_VERSION", "v1");
header("X-API-Version: " . API_VERSION);

// ========================================
// GLOBAL AUTOLOADER
// ========================================

spl_autoload_register(function ($class) {

    $folders = [
        __DIR__ . '/../core/',
        __DIR__ . '/../controllers/',
        __DIR__ . '/../middleware/',
        __DIR__ . '/../services/'
    ];

    foreach ($folders as $folder) {
        $file = $folder . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

require_once __DIR__ . '/../core/ErrorHandler.php';
set_error_handler(['ErrorHandler', 'handleError']);
set_exception_handler(['ErrorHandler', 'handleException']);

// ========================================
// LOAD .ENV (OPTIONAL)
// ========================================

$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    $env = parse_ini_file($envPath, false, INI_SCANNER_TYPED);
    if (is_array($env)) {
        foreach ($env as $key => $value) {
            if (!isset($_ENV[$key])) {
                $_ENV[$key] = $value;
            }
            if (getenv($key) === false) {
                if (is_bool($value)) {
                    putenv($key . '=' . ($value ? 'true' : 'false'));
                } else {
                    putenv($key . '=' . $value);
                }
            }
        }
    }
}

// ========================================
// LOAD CONFIG FILES MANUALLY
// ========================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/jwt.php';
require_once __DIR__ . '/../vendor/autoload.php';

// ========================================
// ROUTER
// ========================================

$router = new Router();

$router->register("auth", "AuthController");
$router->register("user", "UserController");
$router->register("blog", "BlogController");
$router->register("allocation", "AllocationController");
$router->register("meeting", "MeetingController");
$router->register("document", "DocumentController");
$router->register("report", "ReportController");
$router->register("dashboard", "DashboardController");
$router->register("message", "MessageController");
$router->register("inactivity", "InactivityController");
$router->register("blog_comment", "BlogCommentController");
$router->register("document_comment", "DocumentCommentController");
$router->register("meeting_recording", "MeetingRecordingController");
$router->register("health", "HealthController");
$router->register("system", "SystemController");

$controllerName = $_GET['controller'] ?? '';
$publicControllers = ["auth", "health", "system"];
if (!in_array($controllerName, $publicControllers, true)) {
    AuthMiddleware::handle();
}

$router->dispatch();
