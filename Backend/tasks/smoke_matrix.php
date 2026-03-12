<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/jwt.php';

function pickUsers(mysqli $conn): array
{
    $sql = "
        SELECT u.user_id, r.role_name, COALESCE(s.is_admin, 0) AS is_admin
        FROM users u
        JOIN roles r ON u.role_id = r.role_id
        LEFT JOIN staff s ON s.user_id = u.user_id
        ORDER BY u.user_id ASC
    ";
    $result = $conn->query($sql);
    $users = [
        'admin' => null,
        'staff' => null,
        'student' => null,
        'tutor' => null
    ];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $role = (string) ($row['role_name'] ?? '');
            $isAdmin = (int) ($row['is_admin'] ?? 0) === 1;
            $uid = (int) ($row['user_id'] ?? 0);

            if ($role === 'staff' && $isAdmin && $users['admin'] === null) {
                $users['admin'] = $uid;
            } elseif ($role === 'staff' && !$isAdmin && $users['staff'] === null) {
                $users['staff'] = $uid;
            } elseif ($role === 'student' && $users['student'] === null) {
                $users['student'] = $uid;
            } elseif ($role === 'tutor' && $users['tutor'] === null) {
                $users['tutor'] = $uid;
            }
        }
    }

    return $users;
}

function tokenFor(int $userId, string $role, int $isAdmin): string
{
    return generateAccessToken([
        'user_id' => $userId,
        'role_name' => $role,
        'is_admin' => $isAdmin
    ]);
}

function requestStatus(string $url, string $method, string $token, ?array $jsonBody = null): int
{
    $headers = [
        'Authorization: Bearer ' . $token
    ];

    $content = '';
    if ($jsonBody !== null) {
        $headers[] = 'Content-Type: application/json';
        $content = json_encode($jsonBody);
    }

    $options = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'ignore_errors' => true,
            'timeout' => 10
        ]
    ];

    $context = stream_context_create($options);
    @file_get_contents($url, false, $context);

    if (!isset($http_response_header[0])) {
        return 0;
    }

    $parts = explode(' ', $http_response_header[0]);
    return (int) ($parts[1] ?? 0);
}

function ok(array $allowed, int $actual): bool
{
    return in_array($actual, $allowed, true);
}

$users = pickUsers($conn);
$missing = [];
foreach ($users as $k => $v) {
    if ($v === null) {
        $missing[] = $k;
    }
}
if (count($missing) > 0) {
    echo "Missing users for roles: " . implode(',', $missing) . PHP_EOL;
    exit(1);
}

$tokens = [
    'admin' => tokenFor($users['admin'], 'staff', 1),
    'staff' => tokenFor($users['staff'], 'staff', 0),
    'student' => tokenFor($users['student'], 'student', 0),
    'tutor' => tokenFor($users['tutor'], 'tutor', 0)
];

$base = 'http://localhost:80/eTutor/Backend/api/index.php';

$tests = [
    ['name' => 'dashboard_get_student', 'role' => 'student', 'method' => 'GET', 'url' => $base . '?controller=dashboard', 'expect' => [200]],
    ['name' => 'dashboard_get_tutor', 'role' => 'tutor', 'method' => 'GET', 'url' => $base . '?controller=dashboard', 'expect' => [200]],
    ['name' => 'dashboard_get_staff', 'role' => 'staff', 'method' => 'GET', 'url' => $base . '?controller=dashboard', 'expect' => [200]],
    ['name' => 'dashboard_userDashboard_admin', 'role' => 'admin', 'method' => 'GET', 'url' => $base . '?controller=dashboard&action=userDashboard&user_id=' . $users['student'], 'expect' => [200]],
    ['name' => 'dashboard_userDashboard_staff', 'role' => 'staff', 'method' => 'GET', 'url' => $base . '?controller=dashboard&action=userDashboard&user_id=' . $users['student'], 'expect' => [200]],
    ['name' => 'report_statistics_admin', 'role' => 'admin', 'method' => 'GET', 'url' => $base . '?controller=report&action=statistics', 'expect' => [200]],
    ['name' => 'report_statistics_staff', 'role' => 'staff', 'method' => 'GET', 'url' => $base . '?controller=report&action=statistics', 'expect' => [403]],
    ['name' => 'report_activityLogs_admin', 'role' => 'admin', 'method' => 'GET', 'url' => $base . '?controller=report&action=activityLogs&limit=5', 'expect' => [200]],
    ['name' => 'report_activityLogs_staff', 'role' => 'staff', 'method' => 'GET', 'url' => $base . '?controller=report&action=activityLogs&limit=5', 'expect' => [403]],
    ['name' => 'report_activityLogsCsv_admin', 'role' => 'admin', 'method' => 'GET', 'url' => $base . '?controller=report&action=activityLogsCsv&limit=5', 'expect' => [200]],
    ['name' => 'allocation_myTutor_student', 'role' => 'student', 'method' => 'GET', 'url' => $base . '?controller=allocation&action=myTutor', 'expect' => [200]],
    ['name' => 'allocation_myTutor_tutor', 'role' => 'tutor', 'method' => 'GET', 'url' => $base . '?controller=allocation&action=myTutor', 'expect' => [403]],
    ['name' => 'allocation_assignedStudents_tutor', 'role' => 'tutor', 'method' => 'GET', 'url' => $base . '?controller=allocation&action=assignedStudents', 'expect' => [200]],
    ['name' => 'allocation_assignedStudents_student', 'role' => 'student', 'method' => 'GET', 'url' => $base . '?controller=allocation&action=assignedStudents', 'expect' => [403]],
    ['name' => 'blog_list_student', 'role' => 'student', 'method' => 'GET', 'url' => $base . '?controller=blog', 'expect' => [200]],
    ['name' => 'document_list_student', 'role' => 'student', 'method' => 'GET', 'url' => $base . '?controller=document', 'expect' => [200]],
    ['name' => 'document_list_tutor', 'role' => 'tutor', 'method' => 'GET', 'url' => $base . '?controller=document', 'expect' => [200]],
    ['name' => 'meeting_list_student', 'role' => 'student', 'method' => 'GET', 'url' => $base . '?controller=meeting', 'expect' => [200]],
    ['name' => 'meeting_list_tutor', 'role' => 'tutor', 'method' => 'GET', 'url' => $base . '?controller=meeting', 'expect' => [200]],
    ['name' => 'meeting_list_admin', 'role' => 'admin', 'method' => 'GET', 'url' => $base . '?controller=meeting', 'expect' => [200]],
    ['name' => 'doc_comment_list_student', 'role' => 'student', 'method' => 'GET', 'url' => $base . '?controller=document_comment', 'expect' => [200]],
    ['name' => 'meeting_recording_list_tutor', 'role' => 'tutor', 'method' => 'GET', 'url' => $base . '?controller=meeting_recording', 'expect' => [200]],
    ['name' => 'user_resetPassword_admin', 'role' => 'admin', 'method' => 'POST', 'url' => $base . '?controller=user&action=resetPassword', 'expect' => [404], 'body' => ['id' => 999999, 'new_password' => 'Test12345!']],
    ['name' => 'user_resetPassword_staff', 'role' => 'staff', 'method' => 'POST', 'url' => $base . '?controller=user&action=resetPassword', 'expect' => [403], 'body' => ['id' => 999999, 'new_password' => 'Test12345!']]
];

$passed = 0;
$total = count($tests);

echo "name,role,method,expected,status,result" . PHP_EOL;
foreach ($tests as $test) {
    $role = $test['role'];
    $status = requestStatus(
        $test['url'],
        $test['method'],
        $tokens[$role],
        $test['body'] ?? null
    );
    $expected = implode('|', $test['expect']);
    $result = ok($test['expect'], $status) ? 'PASS' : 'FAIL';
    if ($result === 'PASS') {
        $passed++;
    }
    echo $test['name'] . ',' . $role . ',' . $test['method'] . ',' . $expected . ',' . $status . ',' . $result . PHP_EOL;
}

echo "SUMMARY,passed={$passed},total={$total}" . PHP_EOL;
