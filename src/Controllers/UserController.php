<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\AuthMiddleware;
use App\Models\Notification;
use App\Models\User;
use App\Utils\Response;
use App\Utils\Validator;
use Throwable;

class UserController
{
    private User $users;
    private Notification $notifications;

    public function __construct()
    {
        $db = Database::connection();
        $this->users = new User($db);
        $this->notifications = new Notification($db);
    }

    public function me(): void
    {
        $userId = AuthMiddleware::userId();
        $user = $this->users->findById($userId);

        if ($user === null) {
            Response::error('User not found', 404);
        }

        Response::success($user);
    }

    public function updateMe(): void
    {
        $userId = AuthMiddleware::userId();
        $input = Validator::body();

        $updates = [];

        if (array_key_exists('full_name', $input)) {
            $fullName = trim((string) $input['full_name']);
            if ($fullName === '') {
                Response::error('full_name cannot be empty', 422);
            }
            $updates['full_name'] = $fullName;
        }

        if (array_key_exists('bio', $input)) {
            $updates['bio'] = Validator::sanitizeNullableString($input['bio']);
        }

        if (array_key_exists('department', $input)) {
            $updates['department'] = Validator::sanitizeNullableString($input['department']);
        }

        if (array_key_exists('academic_level', $input)) {
            $updates['academic_level'] = Validator::sanitizeNullableString($input['academic_level']);
        }

        if (array_key_exists('is_notifications_enabled', $input)) {
            $updates['is_notifications_enabled'] = Validator::toBool($input['is_notifications_enabled'], true);
        }

        if (isset($_FILES['profile_image']) && is_array($_FILES['profile_image'])) {
            $updates['profile_image'] = $this->storeImage($_FILES['profile_image'], 'profile');
        }

        if (empty($updates)) {
            Response::error('No updatable fields provided', 422);
        }

        $updated = $this->users->updateProfile($userId, $updates);
        if (!$updated) {
            Response::error('Failed to update profile', 500);
        }

        $user = $this->users->findById($userId);
        Response::success($user, 'Profile updated successfully');
    }

    public function notifications(): void
    {
        $userId = AuthMiddleware::userId();
        $limit = Validator::toInt($_GET['limit'] ?? null) ?? 50;
        $rows = $this->notifications->allForUser($userId, $limit);
        Response::success($rows);
    }

    public function markNotificationRead(int $notificationId): void
    {
        $userId = AuthMiddleware::userId();
        $ok = $this->notifications->markAsRead($notificationId, $userId);

        if (!$ok) {
            Response::error('Notification not found', 404);
        }

        Response::success(null, 'Notification marked as read');
    }

    public function markAllNotificationsRead(): void
    {
        $userId = AuthMiddleware::userId();
        $this->notifications->markAllAsRead($userId);
        Response::success(null, 'All notifications marked as read');
    }

    private function storeImage(array $file, string $prefix): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::error('Image upload failed', 422);
        }

        $mime = $this->detectMimeType((string) ($file['tmp_name'] ?? ''));
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        if (!isset($allowed[$mime])) {
            Response::error('Only JPG, PNG, and WEBP images are allowed', 422);
        }

        if (!is_dir(\UPLOADS_PATH) && !mkdir(\UPLOADS_PATH, 0775, true) && !is_dir(\UPLOADS_PATH)) {
            Response::error('Failed to create uploads directory', 500);
        }

        try {
            $filename = $prefix . '_' . bin2hex(random_bytes(10)) . '.' . $allowed[$mime];
        } catch (Throwable) {
            $filename = $prefix . '_' . uniqid('', true) . '.' . $allowed[$mime];
        }

        $target = \UPLOADS_PATH . '/' . $filename;
        if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
            Response::error('Failed to store uploaded image', 500);
        }

        return '/uploads/' . $filename;
    }

    private function detectMimeType(string $filePath): string
    {
        if ($filePath === '' || !is_file($filePath)) {
            return '';
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return '';
        }

        $mime = finfo_file($finfo, $filePath) ?: '';
        finfo_close($finfo);

        return $mime;
    }
}
