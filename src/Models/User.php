<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

class User
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findByAcademicNumber(string $academicNumber): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE academic_number = :academic_number LIMIT 1');
        $stmt->execute(['academic_number' => $academicNumber]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, academic_number, full_name, profile_image, bio, department, academic_level, is_notifications_enabled, created_at
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();

        if (!$user) {
            return null;
        }

        $user['is_notifications_enabled'] = (bool) $user['is_notifications_enabled'];
        return $user;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO users (
                academic_number,
                password_hash,
                full_name,
                profile_image,
                bio,
                department,
                academic_level,
                is_notifications_enabled
            ) VALUES (
                :academic_number,
                :password_hash,
                :full_name,
                :profile_image,
                :bio,
                :department,
                :academic_level,
                :is_notifications_enabled
            )'
        );

        $stmt->execute([
            'academic_number' => $data['academic_number'],
            'password_hash' => $data['password_hash'],
            'full_name' => $data['full_name'],
            'profile_image' => $data['profile_image'] ?? null,
            'bio' => $data['bio'] ?? null,
            'department' => $data['department'] ?? null,
            'academic_level' => $data['academic_level'] ?? null,
            'is_notifications_enabled' => !empty($data['is_notifications_enabled']) ? 1 : 0,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updateProfile(int $id, array $data): bool
    {
        $allowedFields = [
            'full_name',
            'profile_image',
            'bio',
            'department',
            'academic_level',
            'is_notifications_enabled',
        ];

        $updates = [];
        $params = ['id' => $id];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = $field . ' = :' . $field;
                $params[$field] = $field === 'is_notifications_enabled' ? ((bool) $data[$field] ? 1 : 0) : $data[$field];
            }
        }

        if (empty($updates)) {
            return false;
        }

        $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);

        return $stmt->execute($params);
    }
}
