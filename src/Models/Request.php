<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

class Request
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function create(int $listingId, int $requesterId, int $publisherId): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO requests (listing_id, requester_id, publisher_id, status)
             VALUES (:listing_id, :requester_id, :publisher_id, :status)'
        );
        $stmt->execute([
            'listing_id' => $listingId,
            'requester_id' => $requesterId,
            'publisher_id' => $publisherId,
            'status' => 'pending',
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function hasActiveForListing(int $listingId, int $requesterId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM requests
             WHERE listing_id = :listing_id
               AND requester_id = :requester_id
               AND status IN ('pending', 'accepted')
             LIMIT 1"
        );
        $stmt->execute([
            'listing_id' => $listingId,
            'requester_id' => $requesterId,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM requests WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $request = $stmt->fetch();
        return $request ?: null;
    }

    public function findByIdWithListing(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT
                r.*,
                l.status AS listing_status,
                l.title AS listing_title
             FROM requests r
             INNER JOIN listings l ON l.id = r.listing_id
             WHERE r.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $request = $stmt->fetch();
        return $request ?: null;
    }

    public function incoming(int $publisherId): array
    {
        $stmt = $this->db->prepare(
            'SELECT
                r.*,
                l.title AS listing_title,
                u.full_name AS requester_name
             FROM requests r
             INNER JOIN listings l ON l.id = r.listing_id
             INNER JOIN users u ON u.id = r.requester_id
             WHERE r.publisher_id = :publisher_id
             ORDER BY r.created_at DESC'
        );
        $stmt->execute(['publisher_id' => $publisherId]);
        return $stmt->fetchAll();
    }

    public function outgoing(int $requesterId): array
    {
        $stmt = $this->db->prepare(
            'SELECT
                r.*,
                l.title AS listing_title,
                u.full_name AS publisher_name
             FROM requests r
             INNER JOIN listings l ON l.id = r.listing_id
             INNER JOIN users u ON u.id = r.publisher_id
             WHERE r.requester_id = :requester_id
             ORDER BY r.created_at DESC'
        );
        $stmt->execute(['requester_id' => $requesterId]);
        return $stmt->fetchAll();
    }

    public function updateStatus(int $id, string $status): bool
    {
        $stmt = $this->db->prepare('UPDATE requests SET status = :status WHERE id = :id');
        return $stmt->execute([
            'id' => $id,
            'status' => $status,
        ]);
    }

    public function rejectOtherPending(int $listingId, int $acceptedRequestId): array
    {
        $stmt = $this->db->prepare(
            "SELECT requester_id FROM requests
             WHERE listing_id = :listing_id
               AND status = 'pending'
               AND id != :accepted_id"
        );
        $stmt->execute([
            'listing_id' => $listingId,
            'accepted_id' => $acceptedRequestId,
        ]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $update = $this->db->prepare(
            "UPDATE requests
             SET status = 'rejected'
             WHERE listing_id = :listing_id
               AND status = 'pending'
               AND id != :accepted_id"
        );
        $update->execute([
            'listing_id' => $listingId,
            'accepted_id' => $acceptedRequestId,
        ]);

        return array_map(static fn($value): int => (int) $value, $ids);
    }

    public function isParticipant(int $requestId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM requests
             WHERE id = :id
               AND (requester_id = :user_id OR publisher_id = :user_id)
             LIMIT 1'
        );
        $stmt->execute([
            'id' => $requestId,
            'user_id' => $userId,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    public function getMessages(int $requestId): array
    {
        $stmt = $this->db->prepare(
            'SELECT
                m.*,
                u.full_name AS sender_name
             FROM messages m
             INNER JOIN users u ON u.id = m.sender_id
             WHERE m.request_id = :request_id
             ORDER BY m.created_at ASC'
        );
        $stmt->execute(['request_id' => $requestId]);
        return $stmt->fetchAll();
    }

    public function createMessage(int $requestId, int $senderId, int $receiverId, string $messageText): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO messages (request_id, sender_id, receiver_id, message_text)
             VALUES (:request_id, :sender_id, :receiver_id, :message_text)'
        );
        $stmt->execute([
            'request_id' => $requestId,
            'sender_id' => $senderId,
            'receiver_id' => $receiverId,
            'message_text' => $messageText,
        ]);

        return (int) $this->db->lastInsertId();
    }
}

