<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Utils\Jwt;
use App\Utils\Response;
use RuntimeException;

class AuthMiddleware
{
    public static function authenticate(): array
    {
        $token = self::getBearerToken();
        if ($token === null) {
            Response::error('Unauthorized: missing token', 401);
        }

        try {
            $payload = Jwt::decode($token, \JWT_SECRET);
        } catch (RuntimeException $exception) {
            Response::error('Unauthorized: ' . $exception->getMessage(), 401);
        }

        if (!isset($payload['sub'])) {
            Response::error('Unauthorized: invalid token payload', 401);
        }

        return $payload;
    }

    public static function userId(): int
    {
        $payload = self::authenticate();
        return (int) $payload['sub'];
    }

    private static function getBearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (is_array($headers)) {
                $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
            }
        }

        if (!is_string($header) || $header === '') {
            return null;
        }

        if (!preg_match('/Bearer\s+(\S+)/i', $header, $matches)) {
            return null;
        }

        return $matches[1] ?? null;
    }
}
