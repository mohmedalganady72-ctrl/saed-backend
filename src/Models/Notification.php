<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

class Notification
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function create(int $userId, string $title, string $body): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO notifications (user_id, title, body, is_read)
             VALUES (:user_id, :title, :body, 0)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'title' => $title,
            'body' => $body,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function allForUser(int $userId, int $limit = 50): array
    {
        $limit = max(1, min($limit, 200));

        $stmt = $this->db->prepare(
            'SELECT id, user_id, title, body, is_read, created_at
             FROM notifications
             WHERE user_id = :user_id
             ORDER BY created_at DESC
             LIMIT ' . $limit
        );
        $stmt->execute(['user_id' => $userId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['is_read'] = (bool) $row['is_read'];
        }

        return $rows;
    }

    public function markAsRead(int $notificationId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE notifications
             SET is_read = 1
             WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute([
            'id' => $notificationId,
            'user_id' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function markAllAsRead(int $userId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE notifications
             SET is_read = 1
             WHERE user_id = :user_id AND is_read = 0'
        );
        return $stmt->execute(['user_id' => $userId]);
    }
}

