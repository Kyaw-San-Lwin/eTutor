<?php

class ErrorHandler
{
    public static function handleException(Throwable $e): void
    {
        error_log("Unhandled exception: " . $e->getMessage());

        Response::json([
            "success" => false,
            "message" => "Server error"
        ], 500);
    }

    public static function handleError(
        int $severity,
        string $message,
        string $file,
        int $line
    ): bool {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
}
