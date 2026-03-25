<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$studentId = isset($argv[1]) ? (int) $argv[1] : 0;
$tutorIdsCsv = isset($argv[2]) ? (string) $argv[2] : '';

if ($studentId <= 0 || $tutorIdsCsv === '') {
    echo "Usage: php Backend/tasks/check_reallocation_recipients.php <student_id> <tutor_ids_csv>\n";
    echo "Example: php Backend/tasks/check_reallocation_recipients.php 4 3,4\n";
    exit(1);
}

$tutorIds = array_values(array_filter(array_map('intval', explode(',', $tutorIdsCsv)), fn($v) => $v > 0));
if (count($tutorIds) === 0) {
    echo "No valid tutor ids.\n";
    exit(1);
}

$stmtStudent = $conn->prepare("
    SELECT s.student_id, u.user_id, u.user_name, u.email
    FROM students s
    JOIN users u ON s.user_id = u.user_id
    WHERE s.student_id = ?
");
$stmtStudent->bind_param("i", $studentId);
$stmtStudent->execute();
$student = $stmtStudent->get_result()->fetch_assoc();

echo "Student recipient:\n";
echo json_encode($student, JSON_PRETTY_PRINT) . PHP_EOL;

$placeholders = implode(',', array_fill(0, count($tutorIds), '?'));
$types = str_repeat('i', count($tutorIds));
$stmtTutor = $conn->prepare("
    SELECT t.tutor_id, u.user_id, u.user_name, u.email
    FROM tutors t
    JOIN users u ON t.user_id = u.user_id
    WHERE t.tutor_id IN ($placeholders)
");
$stmtTutor->bind_param($types, ...$tutorIds);
$stmtTutor->execute();
$result = $stmtTutor->get_result();

echo "Tutor recipients:\n";
while ($row = $result->fetch_assoc()) {
    echo json_encode($row, JSON_PRETTY_PRINT) . PHP_EOL;
}

