<?php

$dbHost = getenv("ETUTOR_DB_HOST") ?: "localhost";
$dbUser = getenv("ETUTOR_DB_USER") ?: "root";
$dbPass = getenv("ETUTOR_DB_PASS");
if ($dbPass === false) {
    $dbPass = "";
}
$dbName = getenv("ETUTOR_DB_NAME") ?: "etutor";
$dbPort = (int) (getenv("ETUTOR_DB_PORT") ?: 3307);

$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);

if ($conn->connect_error) {
    http_response_code(500);
    error_log("Database connection failed: " . $conn->connect_error);
    die("Connection failed");
}
