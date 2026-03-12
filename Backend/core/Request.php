<?php

class Request
{
    public static function requireMethod(string $method): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
            Response::json(["message" => "Method not allowed"], 405);
        }
    }
}
