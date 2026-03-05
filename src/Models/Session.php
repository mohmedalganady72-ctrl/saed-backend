<?php
declare(strict_types=1);

namespace App\Models;

use DateTimeImmutable;
use PDO;

class Session
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function upsertForDevice(
        int $userId,
        string $deviceId,
        string $platform,
        ?string $deviceName,
        string $plainToken,
        ?string $ipAddress,
        ?string $userAgent,
        int $ttlSeconds = \JWT_REFRESH_TTL_SECONDS
    ): array {
        $expiresAt = self::nextExpiry($ttlSeconds);

        $stmt = $this->db->prepare(
            'INSERT INTO sessions (
                user_id,
                device_id,
                device_name,
                platform,
                token_hash,
                ip_address,
                user_agent,
                expires_at,
                revoked_at,
                last_used_at
            ) VALUES (
                :user_id,
                :device_id,
                :device_name,
                :platform,
                :token_hash,
                :ip_address,
                :user_agent,
                :expires_at,
                NULL,
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                device_name = VALUES(device_name),
                platform = VALUES(platform),
                token_hash = VALUES(token_hash),
                ip_address = VALUES(ip_address),
                user_agent = VALUES(user_agent),
                expires_at = VALUES(expires_at),
                revoked_at = NULL,
                last_used_at = NOW()'
        );

        $stmt->execute([
            'user_id' => $userId,
            'device_id' => $deviceId,
            'device_name' => $deviceName,
            'platform' => $platform,
            'token_hash' => self::hashToken($plainToken),
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'expires_at' => $expiresAt,
        ]);

        return [
            'id' => (int) $this->db->lastInsertId(),
            'expires_at' => $expiresAt,
        ];
    }

    public function findActiveByToken(string $plainToken): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, device_id, platform, expires_at
             FROM sessions
             WHERE token_hash = :token_hash
               AND revoked_at IS NULL
               AND expires_at > NOW()
             LIMIT 1'
        );

        $stmt->execute([
            'token_hash' => self::hashToken($plainToken),
        ]);

        $session = $stmt->fetch();
        if ($session === false) {
            return null;
        }

        return [
            'id' => (int) $session['id'],
            'user_id' => (int) $session['user_id'],
            'device_id' => (string) $session['device_id'],
            'platform' => (string) $session['platform'],
            'expires_at' => (string) $session['expires_at'],
        ];
    }

    public function rotateById(
        int $id,
        string $plainToken,
        ?string $ipAddress,
        ?string $userAgent,
        int $ttlSeconds = \JWT_REFRESH_TTL_SECONDS
    ): ?array {
        $expiresAt = self::nextExpiry($ttlSeconds);

        $stmt = $this->db->prepare(
            'UPDATE sessions
             SET token_hash = :token_hash,
                 ip_address = :ip_address,
                 user_agent = :user_agent,
                 expires_at = :expires_at,
                 revoked_at = NULL,
                 last_used_at = NOW()
             WHERE id = :id
               AND revoked_at IS NULL
               AND expires_at > NOW()'
        );

        $stmt->execute([
            'id' => $id,
            'token_hash' => self::hashToken($plainToken),
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'expires_at' => $expiresAt,
        ]);

        if ($stmt->rowCount() === 0) {
            return null;
        }

        return [
            'id' => $id,
            'expires_at' => $expiresAt,
        ];
    }

    public function revokeById(int $id): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE sessions
             SET revoked_at = NOW()
             WHERE id = :id
               AND revoked_at IS NULL'
        );

        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function touchLastUsed(int $id): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE sessions
             SET last_used_at = NOW()
             WHERE id = :id'
        );

        return $stmt->execute(['id' => $id]);
    }

    private static function nextExpiry(int $ttlSeconds): string
    {
        return (new DateTimeImmutable())
            ->modify('+' . $ttlSeconds . ' seconds')
            ->format('Y-m-d H:i:s');
    }

    private static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}
