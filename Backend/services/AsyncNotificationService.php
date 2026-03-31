<?php

class AsyncNotificationService
{
    private static function envBool(string $key, bool $default = false): bool
    {
        if (array_key_exists($key, $_ENV)) {
            $envValue = $_ENV[$key];
            if (is_bool($envValue)) {
                return $envValue;
            }
            $raw = trim((string) $envValue);
            if ($raw !== '') {
                $value = strtolower($raw);
                return in_array($value, ['1', 'true', 'yes', 'on'], true);
            }
        }

        $raw = getenv($key);
        if ($raw === false || $raw === null || $raw === '') {
            return $default;
        }
        $value = strtolower(trim((string) $raw));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    public static function dispatch(string $type, int $arg1, int $arg2): bool
    {
        if (!self::envBool('ETUTOR_MAIL_ENABLED', false)) {
            return false;
        }

        $asyncEnabled = self::envBool('ETUTOR_NOTIFICATION_ASYNC', self::envBool('ETUTOR_MAIL_ASYNC', true));
        if (!$asyncEnabled) {
            return false;
        }

        $script = realpath(__DIR__ . '/../tasks/send_notification.php');
        if ($script === false) {
            return false;
        }

        $configuredPhpCli = getenv('ETUTOR_PHP_CLI');
        $phpBinary = (is_string($configuredPhpCli) && trim($configuredPhpCli) !== '')
            ? trim($configuredPhpCli)
            : (PHP_BINARY ?: 'php');

        if (stripos(basename($phpBinary), 'php-cgi.exe') !== false) {
            $candidate = dirname($phpBinary) . DIRECTORY_SEPARATOR . 'php.exe';
            if (is_file($candidate)) {
                $phpBinary = $candidate;
            }
        }

        $safeType = preg_replace('/[^a-z_]/i', '', strtolower($type));
        if ($safeType === '') {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $phpCmd = str_replace('"', '""', $phpBinary);
            $scriptCmd = str_replace('"', '""', $script);
            $typeCmd = str_replace('"', '""', $safeType);
            $cmd = 'cmd /c start "" /B "' . $phpCmd . '" "' . $scriptCmd . '" "'
                . $typeCmd . '" ' . (int) $arg1 . ' ' . (int) $arg2;

            $process = @popen($cmd, 'r');
            if ($process === false) {
                return false;
            }
            @pclose($process);
            return true;
        }

        $command = escapeshellarg($phpBinary)
            . ' ' . escapeshellarg($script)
            . ' ' . escapeshellarg($safeType)
            . ' ' . (int) $arg1
            . ' ' . (int) $arg2;
        @exec($command . ' >/dev/null 2>&1 &');
        return true;
    }
}

