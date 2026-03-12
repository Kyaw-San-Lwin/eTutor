<?php

require_once __DIR__ . '/Response.php';

class Router
{
    private $routes = [];

    private function isCallableAction(object $controller, string $method): bool
    {
        if (!method_exists($controller, $method)) {
            return false;
        }

        $refMethod = new ReflectionMethod($controller, $method);
        return $refMethod->isPublic();
    }

    public function register($controller, $class)
    {
        $this->routes[$controller] = $class;
    }

    public function dispatch()
{
    $controllerKey = $_GET['controller'] ?? '';

    if (!isset($this->routes[$controllerKey])) {
        Response::json(["message" => "Invalid controller"], 404);
    }

    $class = $this->routes[$controllerKey];
    $controller = new $class();

    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';

    // =========================
    // AUTH CONTROLLER SPECIAL
    // =========================
    if ($controllerKey === "auth") {
        if ($action) {
            if ($method === "POST" && $this->isCallableAction($controller, $action)) {
                $controller->$action();
                return;
            }
            Response::json(["message" => "Invalid auth route"], 405);
        }

        if ($method === "POST" && $this->isCallableAction($controller, "login")) {
            $controller->login();
            return;
        }

        Response::json(["message" => "Invalid auth route"], 405);
    }

    // =========================
    // NORMAL CONTROLLERS
    // =========================

    if ($action && method_exists($controller, $action)) {
        if (!$this->isCallableAction($controller, $action)) {
            Response::json(["message" => "Method not allowed"], 405);
        }

        $controller->$action();
        return;
    }

    $map = [
        "GET" => "list",
        "POST" => "create",
        "PUT" => "update",
        "DELETE" => "delete"
    ];

    if (!isset($map[$method])) {
        Response::json(["message" => "Method not allowed"], 405);
    }

    $default = $map[$method];

    if (!$this->isCallableAction($controller, $default)) {
        Response::json(["message" => "Method not implemented"], 405);
    }

    $controller->$default();
}
}
