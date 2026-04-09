<?php
declare(strict_types=1);

namespace App\Utils;

class Validator
{
    public static function body(): array
    {
        if (!empty($_POST)) {
            return $_POST;
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        parse_str($raw, $parsed);
        return is_array($parsed) ? $parsed : [];
    }

    public static function required(array $data, array $fields): array
    {
        $errors = [];

        //c

        foreach ($fields as $field) {
            if (!array_key_exists($field, $data) || self::isEmpty($data[$field])) {
                $errors[$field] = $field . ' is required';
            }
        }

        return $errors;
    }

    public static function isEmail(?string $email): bool
    {
        if ($email === null) {
            return false;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function isIn(mixed $value, array $allowed): bool
    {
        return in_array($value, $allowed, true);
    }

    public static function sanitizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    public static function toInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value) && (string) (int) $value === (string) $value) {
            return (int) $value;
        }

        return null;
    }

    public static function toBool(mixed $value, ?bool $default = null): ?bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $bool ?? $default;
    }

    private static function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value) && trim($value) === '') {
            return true;
        }

        return false;
    }
}
