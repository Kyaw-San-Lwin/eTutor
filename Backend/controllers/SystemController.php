<?php

class SystemController
{
    public function info()
    {
        Response::json([
            "system" => "eTutor Backend",
            "version" => "1.0",
            "api_version" => defined("API_VERSION") ? API_VERSION : null,
            "modules" => [
                "auth",
                "user",
                "allocation",
                "message",
                "blog",
                "document",
                "meeting",
                "report",
                "dashboard",
                "inactivity"
            ]
        ]);
    }
}
