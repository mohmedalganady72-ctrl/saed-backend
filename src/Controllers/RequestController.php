<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\AuthMiddleware;
use App\Models\Listing;
use App\Models\Notification;
use App\Models\Request as RequestModel;
use App\Utils\Response;
use App\Utils\Validator;
use Throwable;

class RequestController
{
    private RequestModel $requests;
    private Listing $listings;
    private Notification $notifications;

    public function __construct()
    {
        $db = Database::connection();
        $this->requests = new RequestModel($db);
        $this->listings = new Listing($db);
        $this->notifications = new Notification($db);
    }

    public function create(): void
    {
        $userId = AuthMiddleware::userId();
        $input = Validator::body();
        $listingId = Validator::toInt($input['listing_id'] ?? null);

        if ($listingId === null) {
            Response::error('listing_id is required and must be integer', 422);
        }

        $listing = $this->listings->findRawById($listingId);
        if ($listing === null) {
            Response::error('Listing not found', 404);
        }

        if ((int) $listing['publisher_id'] === $userId) {
            Response::error('You cannot request your own listing', 400);
        }

        if (($listing['status'] ?? '') !== 'available') {
            Response::error('Listing is not available', 409);
        }

        if ($this->requests->hasActiveForListing($listingId, $userId)) {
            Response::error('You already have an active request for this listing', 409);
        }

        $requestId = $this->requests->create($listingId, $userId, (int) $listing['publisher_id']);
        $this->notifications->create(
            (int) $listing['publisher_id'],
            'طلب جديد',
            'لديك طلب جديد على الإعلان: ' . $listing['title']
        );

        $request = $this->requests->findById($requestId);
        Response::success($request, 'Request submitted successfully', 201);
    }

    public function incoming(): void
    {
        $userId = AuthMiddleware::userId();
        Response::success($this->requests->incoming($userId));
    }

    public function outgoing(): void
    {
        $userId = AuthMiddleware::userId();
        Response::success($this->requests->outgoing($userId));
    }

    public function updateStatus(int $requestId): void
    {
        $userId = AuthMiddleware::userId();
        $input = Validator::body();

        $statusRaw = strtolower((string) ($input['status'] ?? $input['action'] ?? ''));
        $status = match ($statusRaw) {
            'accept', 'accepted' => 'accepted',
            'reject', 'rejected' => 'rejected',
            default => null,
        };

        if ($status === null) {
            Response::error('status must be accepted/rejected', 422);
        }

        $request = $this->requests->findByIdWithListing($requestId);
        if ($request === null) {
            Response::error('Request not found', 404);
        }

        if ((int) $request['publisher_id'] !== $userId) {
            Response::error('Permission denied', 403);
        }

        if (($request['status'] ?? '') !== 'pending') {
            Response::error('Only pending requests can be updated', 409);
        }

        if ($status === 'accepted') {
            if (($request['listing_status'] ?? '') !== 'available') {
                Response::error('Listing is not available anymore', 409);
            }

            $db = Database::connection();
            try {
                $db->beginTransaction();
                $this->requests->updateStatus($requestId, 'accepted');
                $rejectedIds = $this->requests->rejectOtherPending((int) $request['listing_id'], $requestId);
                $this->listings->updateStatus((int) $request['listing_id'], 'reserved');
                $db->commit();
            } catch (Throwable $throwable) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                Response::error('Failed to update request status', 500);
            }

            $this->notifications->create(
                (int) $request['requester_id'],
                'تم قبول طلبك',
                'تم قبول طلبك على الإعلان: ' . $request['listing_title']
            );

            foreach ($rejectedIds as $rejectedId) {
                $this->notifications->create(
                    $rejectedId,
                    'تم رفض الطلب',
                    'تم رفض طلبك على الإعلان: ' . $request['listing_title']
                );
            }

            Response::success(null, 'Request accepted');
        }

        $this->requests->updateStatus($requestId, 'rejected');
        $this->notifications->create(
            (int) $request['requester_id'],
            'تم رفض الطلب',
            'تم رفض طلبك على الإعلان: ' . $request['listing_title']
        );

        Response::success(null, 'Request rejected');
    }

    public function messages(int $requestId): void
    {
        $userId = AuthMiddleware::userId();
        if (!$this->requests->isParticipant($requestId, $userId)) {
            Response::error('Permission denied', 403);
        }

        Response::success($this->requests->getMessages($requestId));
    }

    public function sendMessage(int $requestId): void
    {
        $userId = AuthMiddleware::userId();
        $request = $this->requests->findById($requestId);

        if ($request === null) {
            Response::error('Request not found', 404);
        }

        if (!$this->requests->isParticipant($requestId, $userId)) {
            Response::error('Permission denied', 403);
        }

        $input = Validator::body();
        $text = trim((string) ($input['message_text'] ?? ''));
        if ($text === '') {
            Response::error('message_text is required', 422);
        }

        $receiverId = ((int) $request['requester_id'] === $userId)
            ? (int) $request['publisher_id']
            : (int) $request['requester_id'];

        $messageId = $this->requests->createMessage($requestId, $userId, $receiverId, $text);
        $this->notifications->create($receiverId, 'رسالة جديدة', 'لديك رسالة جديدة بخصوص أحد الطلبات');

        Response::success(['id' => $messageId], 'Message sent', 201);
    }
}
