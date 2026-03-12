<?php

require_once __DIR__ . '/Response.php';

class BaseController
{
    protected function getRequestData()
    {
        return json_decode(file_get_contents("php://input"), true);
    }

    protected function user()
    {
        return $GLOBALS['auth_user'] ?? null;
    }

    protected function success($message, $data = [])
    {
        Response::json([
            "status" => "success",
            "message" => $message,
            "data" => $data
        ]);
    }

    protected function error($message, $status = 400)
    {
        Response::json([
            "status" => "error",
            "message" => $message
        ], $status);
    }
}