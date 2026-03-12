<?php
require_once __DIR__ . '/../config/jwt.php';

echo generateAccessToken([
    "user_id" => 1,
    "role_name" => "staff",
    "is_admin" => 1
]) . PHP_EOL;
