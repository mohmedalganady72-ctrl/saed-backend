<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Models\Session;
use App\Models\User;
use App\Utils\Jwt;
use App\Utils\Response;
use App\Utils\Validator;
use PDO;
use Throwable;

class AuthController
{
    private PDO $db;

    private User $users;
    private ?Session $sessions = null;

    public function __construct()
    {
        $this->db = Database::connection();
        $this->users = new User($this->db);
    }

    public function register(): void
    {
        $data = Validator::body();
        $errors = Validator::required($data, ['academic_number', 'password', 'full_name', 'device_id', 'platform']);

        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        if (strlen((string) $data['password']) < 6) {
            Response::error('Password must be at least 6 characters', 422);
        }

        $academicNumber = trim((string) $data['academic_number']);
        $fullName = trim((string) $data['full_name']);
        $deviceContext = $this->extractDeviceContext($data);

        if ($academicNumber === '' || $fullName === '') {
            Response::error('academic_number and full_name cannot be empty', 422);
        }

        $existing = $this->users->findByAcademicNumber($academicNumber);
        if ($existing !== null) {
            Response::error('Academic number already exists', 409);
        }

        $userId = $this->users->create([
            'academic_number' => $academicNumber,
            'password_hash' => password_hash((string) $data['password'], PASSWORD_DEFAULT),
            'full_name' => $fullName,
            'bio' => Validator::sanitizeNullableString($data['bio'] ?? null),
            'department' => Validator::sanitizeNullableString($data['department'] ?? null),
            'academic_level' => Validator::sanitizeNullableString($data['academic_level'] ?? null),
            'is_notifications_enabled' => Validator::toBool($data['is_notifications_enabled'] ?? true, true),
        ]);

        $user = $this->users->findById($userId);
        if ($user === null) {
            Response::error('Failed to load created account', 500);
        }

        try {
            $tokens = $this->issueTokenPairForDevice($userId, $academicNumber, $deviceContext);
        } catch (Throwable) {
            Response::error('Failed to issue auth tokens', 500);
        }

        Response::success(array_merge($tokens, [
            'user' => $user,
        ]), 'Account created successfully', 201);
    }

    public function login(): void
    {
        $data = Validator::body();
        $errors = Validator::required($data, ['academic_number', 'password', 'device_id', 'platform']);

        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        $academicNumber = trim((string) $data['academic_number']);
        $password = (string) $data['password'];
        $deviceContext = $this->extractDeviceContext($data);
        $user = $this->users->findByAcademicNumber($academicNumber);

        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            Response::error('Invalid academic number or password', 401);
        }

        $safeUser = $this->users->findById((int) $user['id']);
        if ($safeUser === null) {
            Response::error('Failed to load account', 500);
        }

        try {
            $tokens = $this->issueTokenPairForDevice((int) $user['id'], $academicNumber, $deviceContext);
        } catch (Throwable) {
            Response::error('Failed to issue auth tokens', 500);
        }

        Response::success(array_merge($tokens, [
            'user' => $safeUser,
        ]), 'Login successful');
    }

    public function refresh(): void
    {
        $data = Validator::body();
        $errors = Validator::required($data, ['refresh_token']);

        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        $refreshToken = trim((string) $data['refresh_token']);
        if ($refreshToken === '') {
            Response::error('refresh_token cannot be empty', 422);
        }

        $storedSession = $this->sessions()->findActiveByToken($refreshToken);
        if ($storedSession === null) {
            Response::error('Invalid or expired refresh token', 401);
        }

        $user = $this->users->findById((int) $storedSession['user_id']);
        if ($user === null) {
            $this->sessions()->revokeById((int) $storedSession['id']);
            Response::error('Invalid refresh token user', 401);
        }

        try {
            $this->db->beginTransaction();
            $tokens = $this->rotateTokenPair((int) $storedSession['id'], (int) $user['id'], (string) $user['academic_number']);
            if ($tokens === null) {
                $this->db->rollBack();
                Response::error('Invalid or expired refresh token', 401);
            }
            $this->db->commit();
        } catch (Throwable) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            Response::error('Failed to refresh token', 500);
        }

        Response::success(array_merge($tokens, [
            'user' => $user,
        ]), 'Token refreshed successfully');
    }

    private function issueTokenPairForDevice(int $userId, string $academicNumber, array $deviceContext): array
    {
        $accessToken = Jwt::encode([
            'sub' => $userId,
            'academic_number' => $academicNumber,
            'type' => 'access',
        ], \JWT_SECRET, \JWT_ACCESS_TTL_SECONDS);

        $refreshToken = self::generateRefreshToken();
        $sessionMeta = $this->sessions()->upsertForDevice(
            $userId,
            $deviceContext['device_id'],
            $deviceContext['platform'],
            $deviceContext['device_name'],
            $refreshToken,
            $this->clientIpAddress(),
            $this->clientUserAgent(),
            \JWT_REFRESH_TTL_SECONDS
        );

        return $this->buildTokenPayload($accessToken, $refreshToken, $sessionMeta['expires_at']);
    }

    private function rotateTokenPair(int $sessionId, int $userId, string $academicNumber): ?array
    {
        $accessToken = Jwt::encode([
            'sub' => $userId,
            'academic_number' => $academicNumber,
            'type' => 'access',
        ], \JWT_SECRET, \JWT_ACCESS_TTL_SECONDS);

        $refreshToken = self::generateRefreshToken();
        $sessionMeta = $this->sessions()->rotateById(
            $sessionId,
            $refreshToken,
            $this->clientIpAddress(),
            $this->clientUserAgent(),
            \JWT_REFRESH_TTL_SECONDS
        );

        if ($sessionMeta === null) {
            return null;
        }

        return $this->buildTokenPayload($accessToken, $refreshToken, $sessionMeta['expires_at']);
    }

    private function buildTokenPayload(string $accessToken, string $refreshToken, string $refreshTokenExpiresAt): array
    {
        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'access_token_expires_in' => \JWT_ACCESS_TTL_SECONDS,
            'refresh_token_expires_in' => \JWT_REFRESH_TTL_SECONDS,
            'refresh_token_expires_at' => $refreshTokenExpiresAt,
        ];
    }

    private static function generateRefreshToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    }

    private function extractDeviceContext(array $data): array
    {
        $deviceId = trim((string) ($data['device_id'] ?? ''));
        $platform = strtolower(trim((string) ($data['platform'] ?? '')));
        $deviceName = Validator::sanitizeNullableString($data['device_name'] ?? null);

        $errors = [];
        if ($deviceId === '') {
            $errors['device_id'] = 'device_id is required';
        } elseif (strlen($deviceId) > 100) {
            $errors['device_id'] = 'device_id must be at most 100 characters';
        }

        if ($platform === '') {
            $errors['platform'] = 'platform is required';
        } elseif (!Validator::isIn($platform, ['android', 'ios'])) {
            $errors['platform'] = 'platform must be android/ios';
        }

        if ($deviceName !== null && strlen($deviceName) > 150) {
            $errors['device_name'] = 'device_name must be at most 150 characters';
        }

        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        return [
            'device_id' => $deviceId,
            'platform' => $platform,
            'device_name' => $deviceName,
        ];
    }

    private function clientIpAddress(): ?string
    {
        $candidates = [
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
            $_SERVER['HTTP_CLIENT_IP'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $ip = trim(strtok($candidate, ',') ?: '');
            if ($ip === '') {
                continue;
            }

            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                return $ip;
            }
        }

        return null;
    }

    private function clientUserAgent(): ?string
    {
        $userAgent = Validator::sanitizeNullableString($_SERVER['HTTP_USER_AGENT'] ?? null);
        if ($userAgent === null) {
            return null;
        }

        if (strlen($userAgent) <= 512) {
            return $userAgent;
        }

        return substr($userAgent, 0, 512);
    }

    private function sessions(): Session
    {
        if (!$this->sessions instanceof Session) {
            $this->sessions = new Session($this->db);
        }

        return $this->sessions;
    }
}
