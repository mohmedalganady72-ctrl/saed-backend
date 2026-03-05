<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

class Listing
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function categoryExists(int $categoryId): bool
    {
        $stmt = $this->db->prepare('SELECT id FROM categories WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $categoryId]);
        return (bool) $stmt->fetchColumn();
    }

    public function getCategories(): array
    {
        $stmt = $this->db->query('SELECT id, name, icon, created_at FROM categories ORDER BY name ASC');
        return $stmt->fetchAll();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO listings (
                publisher_id,
                category_id,
                title,
                description,
                images,
                condition_level,
                location,
                status,
                listing_type
            ) VALUES (
                :publisher_id,
                :category_id,
                :title,
                :description,
                :images,
                :condition_level,
                :location,
                :status,
                :listing_type
            )'
        );

        $stmt->execute([
            'publisher_id' => $data['publisher_id'],
            'category_id' => $data['category_id'],
            'title' => $data['title'],
            'description' => $data['description'],
            'images' => json_encode($data['images'] ?? [], JSON_UNESCAPED_UNICODE),
            'condition_level' => $data['condition_level'] ?? null,
            'location' => $data['location'] ?? null,
            'status' => $data['status'] ?? 'available',
            'listing_type' => $data['listing_type'] ?? 'إهداء',
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function all(array $filters = []): array
    {
        $sql = '
            SELECT
                l.*,
                u.full_name AS publisher_name,
                u.profile_image AS publisher_image,
                c.name AS category_name
            FROM listings l
            INNER JOIN users u ON u.id = l.publisher_id
            INNER JOIN categories c ON c.id = l.category_id
            WHERE 1=1
        ';

        $params = [];

        if (!empty($filters['category_id'])) {
            $sql .= ' AND l.category_id = :category_id';
            $params['category_id'] = (int) $filters['category_id'];
        }

        if (!empty($filters['status'])) {
            $sql .= ' AND l.status = :status';
            $params['status'] = (string) $filters['status'];
        }

        if (!empty($filters['publisher_id'])) {
            $sql .= ' AND l.publisher_id = :publisher_id';
            $params['publisher_id'] = (int) $filters['publisher_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= ' AND (l.title LIKE :search OR l.description LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $limit = isset($filters['limit']) ? max(1, min((int) $filters['limit'], 100)) : 50;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;

        if (!isset($filters['offset']) && isset($filters['page'])) {
            $page = max(1, (int) $filters['page']);
            $offset = ($page - 1) * $limit;
        }

        $sql .= ' ORDER BY l.created_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return $this->decodeImageColumns($rows);
    }

    public function mine(int $publisherId, array $filters = []): array
    {
        $filters['publisher_id'] = $publisherId;
        return $this->all($filters);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT
                l.*,
                u.full_name AS publisher_name,
                u.profile_image AS publisher_image,
                c.name AS category_name
             FROM listings l
             INNER JOIN users u ON u.id = l.publisher_id
             INNER JOIN categories c ON c.id = l.category_id
             WHERE l.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return $this->decodeImageColumns([$row])[0];
    }

    public function findRawById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM listings WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function updateStatus(int $id, string $status): bool
    {
        $stmt = $this->db->prepare('UPDATE listings SET status = :status WHERE id = :id');
        return $stmt->execute([
            'id' => $id,
            'status' => $status,
        ]);
    }

    public function deleteByPublisher(int $id, int $publisherId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM listings WHERE id = :id AND publisher_id = :publisher_id');
        $stmt->execute([
            'id' => $id,
            'publisher_id' => $publisherId,
        ]);

        return $stmt->rowCount() > 0;
    }

    private function decodeImageColumns(array $rows): array
    {
        foreach ($rows as &$row) {
            $decoded = json_decode((string) ($row['images'] ?? '[]'), true);
            $row['images'] = is_array($decoded) ? $decoded : [];
        }

        return $rows;
    }
}

