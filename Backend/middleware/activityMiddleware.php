<?php

function logActivity($conn, $user_id, $page, $activity)
{
    if (!$conn) {
        return;
    }

    $browser = $_SERVER['HTTP_USER_AGENT'] ?? "Unknown";
    $ip = $_SERVER['REMOTE_ADDR'] ?? "Unknown";

    $sql = "INSERT INTO activity_logs
            (user_id, page_visited, activity_type, browser_used, ip_address)
            VALUES(?,?,?,?,?)";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return;
    }

    $stmt->bind_param(
        "issss",
        $user_id,
        $page,
        $activity,
        $browser,
        $ip
    );

    $stmt->execute();
}
