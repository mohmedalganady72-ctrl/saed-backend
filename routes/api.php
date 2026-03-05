<?php
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\ListingController;
use App\Controllers\RequestController;
use App\Controllers\UserController;
use App\Utils\Response;

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$scriptDir = rtrim($scriptDir, '/');
if ($scriptDir !== '' && $scriptDir !== '/' && str_starts_with($uriPath, $scriptDir)) {
    $uriPath = substr($uriPath, strlen($scriptDir));
}

$uriPath = preg_replace('#^/index\.php#', '', $uriPath) ?: '/';
$uriPath = '/' . trim($uriPath, '/');
if ($uriPath === '//') {
    $uriPath = '/';
}

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$authController = new AuthController();
$userController = new UserController();
$listingController = new ListingController();
$requestController = new RequestController();

$routes = [
    ['GET', '#^/$#', static fn() => Response::success(['service' => 'university_app_api', 'status' => 'ok'])],

    ['POST', '#^/auth/register$#', static fn() => $authController->register()],
    ['POST', '#^/auth/login$#', static fn() => $authController->login()],
    ['POST', '#^/auth/refresh$#', static fn() => $authController->refresh()],

    ['GET', '#^/me$#', static fn() => $userController->me()],
    ['PUT', '#^/me$#', static fn() => $userController->updateMe()],
    ['PATCH', '#^/me$#', static fn() => $userController->updateMe()],
    ['GET', '#^/me/notifications$#', static fn() => $userController->notifications()],
    ['PATCH', '#^/me/notifications/read-all$#', static fn() => $userController->markAllNotificationsRead()],
    ['PATCH', '#^/me/notifications/(\d+)/read$#', static fn($id) => $userController->markNotificationRead((int) $id)],

    ['GET', '#^/categories$#', static fn() => $listingController->categories()],
    ['GET', '#^/listings$#', static fn() => $listingController->index()],
    ['POST', '#^/listings$#', static fn() => $listingController->create()],
    ['GET', '#^/listings/mine$#', static fn() => $listingController->mine()],
    ['GET', '#^/listings/(\d+)$#', static fn($id) => $listingController->show((int) $id)],
    ['DELETE', '#^/listings/(\d+)$#', static fn($id) => $listingController->delete((int) $id)],

    ['POST', '#^/requests$#', static fn() => $requestController->create()],
    ['GET', '#^/requests/incoming$#', static fn() => $requestController->incoming()],
    ['GET', '#^/requests/outgoing$#', static fn() => $requestController->outgoing()],
    ['PATCH', '#^/requests/(\d+)/status$#', static fn($id) => $requestController->updateStatus((int) $id)],
    ['GET', '#^/requests/(\d+)/messages$#', static fn($id) => $requestController->messages((int) $id)],
    ['POST', '#^/requests/(\d+)/messages$#', static fn($id) => $requestController->sendMessage((int) $id)],
];

foreach ($routes as [$routeMethod, $pattern, $handler]) {
    if ($routeMethod !== $method) {
        continue;
    }

    if (preg_match($pattern, $uriPath, $matches) !== 1) {
        continue;
    }

    array_shift($matches);
    $handler(...$matches);
    exit;
}

$pathExists = false;
foreach ($routes as [$routeMethod, $pattern]) {
    if (preg_match($pattern, $uriPath) === 1) {
        $pathExists = true;
        break;
    }
}

if ($pathExists) {
    Response::error('Method not allowed', 405);
}

Response::error('Endpoint not found', 404);
