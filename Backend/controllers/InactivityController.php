<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/InactivityService.php';
require_once __DIR__ . '/../middleware/activityMiddleware.php';

class InactivityController
{
    private $conn;
    private $service;

    public function __construct()
    {
        global $conn;
        $this->conn = $conn;
        $this->service = new InactivityService($conn);
    }

    private function requireAdmin(): array
    {
        $user = $GLOBALS['auth_user'] ?? null;
        if (!is_array($user) || empty($user['user_id'])) {
            Response::json(["success" => false, "message" => "Unauthorized"], 401);
        }

        $role = (string) ($user['role'] ?? '');
        $isAdmin = (int) ($user['is_admin'] ?? 0) === 1;

        if ($role !== 'staff' || !$isAdmin) {
            Response::json(["success" => false, "message" => "Admin only access"], 403);
        }

        return $user;
    }

    private function safeLogActivity(int $userId, string $page, string $activity): void
    {
        if ($userId <= 0) {
            return;
        }
        logActivity($this->conn, $userId, $page, $activity);
    }

    public function list()
    {
        $user = $this->requireAdmin();
        $userId = (int) $user['user_id'];

        $inactive7 = $this->service->listInactiveAllocations(7);
        $inactive28 = $this->service->listInactiveAllocations(28);

        $this->safeLogActivity($userId, "Inactivity Report", "Viewed inactivity reports");

        Response::json([
            "success" => true,
            "data" => [
                "inactive_7_days" => $inactive7,
                "inactive_28_days" => $inactive28
            ]
        ]);
    }

    public function warn()
    {
        $user = $this->requireAdmin();
        $userId = (int) $user['user_id'];

        $days = filter_var($_GET['days'] ?? 28, FILTER_VALIDATE_INT);
        if ($days === false || $days < 1) {
            Response::json(["success" => false, "message" => "days must be a positive integer"], 400);
        }

        $summary = $this->service->sendWarnings($days);

        $this->safeLogActivity(
            $userId,
            "Inactivity Warning",
            "Triggered inactivity warnings for {$days} days"
        );

        Response::json(["success" => true, "data" => $summary]);
    }
}
