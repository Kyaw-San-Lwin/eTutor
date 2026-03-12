<?php

class ValidationService
{
    public static function requireFields(array $data, array $fields): void
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $data)) {
                Response::json(["message" => "Missing required field: {$field}"], 400);
            }
            if (is_string($data[$field]) && trim($data[$field]) === '') {
                Response::json(["message" => "Field cannot be empty: {$field}"], 400);
            }
        }
    }

    public static function sanitizeString($value, int $maxLength = 5000): string
    {
        $str = trim((string) $value);
        $str = strip_tags($str);
        if ($maxLength > 0 && mb_strlen($str) > $maxLength) {
            Response::json(["message" => "Text too long"], 400);
        }
        return $str;
    }

    public static function sanitizeEmail($value): string
    {
        $email = trim((string) $value);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::json(["message" => "Invalid email format"], 400);
        }
        return $email;
    }

    public static function intField($value, string $fieldName, int $min = 1): int
    {
        $v = filter_var($value, FILTER_VALIDATE_INT);
        if ($v === false || $v < $min) {
            Response::json(["message" => "Invalid {$fieldName}"], 400);
        }
        return (int) $v;
    }

    public static function paginationFromQuery(int $defaultLimit = 20, int $maxLimit = 100): array
    {
        $limit = filter_var($_GET['limit'] ?? $defaultLimit, FILTER_VALIDATE_INT);
        $offset = filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT);

        if ($limit === false || $limit <= 0 || $limit > $maxLimit) {
            Response::json(["message" => "limit must be between 1 and {$maxLimit}"], 400);
        }
        if ($offset === false || $offset < 0) {
            Response::json(["message" => "offset must be 0 or greater"], 400);
        }

        return ["limit" => (int) $limit, "offset" => (int) $offset];
    }
}
