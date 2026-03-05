<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\AuthMiddleware;
use App\Models\Listing;
use App\Utils\Response;
use App\Utils\Validator;
use Throwable;

class ListingController
{
    private Listing $listings;

    public function __construct()
    {
        $this->listings = new Listing(Database::connection());
    }

    public function categories(): void
    {
        Response::success($this->listings->getCategories());
    }

    public function index(): void
    {
        $filters = [
            'category_id' => $_GET['category_id'] ?? null,
            'status' => $_GET['status'] ?? null,
            'search' => $_GET['search'] ?? null,
            'limit' => $_GET['limit'] ?? null,
            'offset' => $_GET['offset'] ?? null,
            'page' => $_GET['page'] ?? null,
        ];

        Response::success($this->listings->all($filters));
    }

    public function show(int $id): void
    {
        $listing = $this->listings->findById($id);
        if ($listing === null) {
            Response::error('Listing not found', 404);
        }

        Response::success($listing);
    }

    public function mine(): void
    {
        $userId = AuthMiddleware::userId();
        $filters = [
            'category_id' => $_GET['category_id'] ?? null,
            'status' => $_GET['status'] ?? null,
            'search' => $_GET['search'] ?? null,
            'limit' => $_GET['limit'] ?? null,
            'offset' => $_GET['offset'] ?? null,
            'page' => $_GET['page'] ?? null,
        ];

        Response::success($this->listings->mine($userId, $filters));
    }

    public function create(): void
    {
        $userId = AuthMiddleware::userId();
        $input = Validator::body();

        $errors = Validator::required($input, ['category_id', 'title', 'description']);
        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        $categoryId = Validator::toInt($input['category_id']);
        if ($categoryId === null || !$this->listings->categoryExists($categoryId)) {
            Response::error('Invalid category_id', 422);
        }

        $images = $this->extractUploadedImages();
        if (empty($images) && isset($input['images'])) {
            $provided = $input['images'];
            if (is_string($provided)) {
                $decoded = json_decode($provided, true);
                if (is_array($decoded)) {
                    $provided = $decoded;
                }
            }
            if (is_array($provided)) {
                $images = array_values(array_filter($provided, static fn($item): bool => is_string($item) && trim($item) !== ''));
            }
        }

        $listingId = $this->listings->create([
            'publisher_id' => $userId,
            'category_id' => $categoryId,
            'title' => trim((string) $input['title']),
            'description' => trim((string) $input['description']),
            'images' => $images,
            'condition_level' => Validator::sanitizeNullableString($input['condition_level'] ?? null),
            'location' => Validator::sanitizeNullableString($input['location'] ?? null),
            'listing_type' => Validator::sanitizeNullableString($input['listing_type'] ?? null) ?? 'إهداء',
            'status' => 'available',
        ]);

        $listing = $this->listings->findById($listingId);
        Response::success($listing, 'Listing created successfully', 201);
    }

    public function delete(int $id): void
    {
        $userId = AuthMiddleware::userId();
        $deleted = $this->listings->deleteByPublisher($id, $userId);

        if (!$deleted) {
            Response::error('Listing not found or permission denied', 404);
        }

        Response::success(null, 'Listing deleted successfully');
    }

    private function extractUploadedImages(): array
    {
        if (!isset($_FILES['images']) || !is_array($_FILES['images'])) {
            return [];
        }

        $images = [];
        $file = $_FILES['images'];

        if (is_array($file['name'])) {
            $count = count($file['name']);
            for ($i = 0; $i < $count; $i++) {
                $single = [
                    'name' => $file['name'][$i] ?? '',
                    'type' => $file['type'][$i] ?? '',
                    'tmp_name' => $file['tmp_name'][$i] ?? '',
                    'error' => $file['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $file['size'][$i] ?? 0,
                ];

                $stored = $this->storeImage($single, 'listing');
                if ($stored !== null) {
                    $images[] = $stored;
                }
            }
        } else {
            $stored = $this->storeImage($file, 'listing');
            if ($stored !== null) {
                $images[] = $stored;
            }
        }

        return $images;
    }

    private function storeImage(array $file, string $prefix): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::error('One of uploaded images failed', 422);
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
