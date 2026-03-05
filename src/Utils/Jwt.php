<?php
declare(strict_types=1);

namespace App\Utils;

use RuntimeException;

class Jwt
{
    public static function encode(array $payload, string $secret, int $ttlSeconds = \JWT_ACCESS_TTL_SECONDS): string
    {
        $now = time();
        $payload['iat'] = $now;
        $payload['exp'] = $now + $ttlSeconds;

        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT',
        ];

        $encodedHeader = self::base64UrlEncode(json_encode($header, JSON_UNESCAPED_UNICODE) ?: '{}');
        $encodedPayload = self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}');

        $signature = hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, $secret, true);
        $encodedSignature = self::base64UrlEncode($signature);

        return $encodedHeader . '.' . $encodedPayload . '.' . $encodedSignature;
    }

    public static function decode(string $token, string $secret): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Invalid token format');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $header = json_decode(self::base64UrlDecode($encodedHeader), true);
        $payload = json_decode(self::base64UrlDecode($encodedPayload), true);

        if (!is_array($header) || !is_array($payload)) {
            throw new RuntimeException('Invalid token payload');
        }

        if (($header['alg'] ?? '') !== 'HS256') {
            throw new RuntimeException('Unsupported token algorithm');
        }

        $expectedSignature = hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, $secret, true);
        $actualSignature = self::base64UrlDecode($encodedSignature);

        if (!hash_equals($expectedSignature, $actualSignature)) {
            throw new RuntimeException('Invalid token signature');
        }

        $exp = isset($payload['exp']) ? (int) $payload['exp'] : null;
        if ($exp !== null && $exp < time()) {
            throw new RuntimeException('Token expired');
        }

        return $payload;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('Invalid base64 value');
        }

        return $decoded;
    }
}
