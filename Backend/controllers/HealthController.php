<?php

class HealthController
{
    public function list()
    {
        Response::json([
            "status" => "OK",
            "service" => "eTutor API",
            "time" => date("Y-m-d H:i:s")
        ]);
    }
}
