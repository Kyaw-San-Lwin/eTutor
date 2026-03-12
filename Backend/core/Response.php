<?php

class Response{
    public static function json($data, $status = 200)
    {
        if (!is_array($data)) {
            $data = ["data" => $data];
        }

        if (!array_key_exists("request_id", $data)) {
            $data["request_id"] = $GLOBALS['request_id'] ?? null;
        }

        if (!array_key_exists("api_version", $data) && defined("API_VERSION")) {
            $data["api_version"] = API_VERSION;
        }

        if (!array_key_exists("success", $data)) {
            $data["success"] = $status < 400;
        }

        if (!array_key_exists("message", $data)) {
            $data["message"] = $status < 400 ? "OK" : "Request failed";
        }

        http_response_code($status);
        header("Content-Type: application/json");
        echo json_encode($data);
        exit();
    }
}
