<?php
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
if ($basePath === '' || $basePath === '.') {
    $basePath = '';
}

header('Location: ' . $basePath . '/Frontend/Pages/Auth/Login.html', true, 302);
exit;
