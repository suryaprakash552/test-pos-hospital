<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\SendNotification;
use Illuminate\Support\Facades\Notification;

/**
 * NOT WIRED UP. Nothing references this class - the equivalent logic currently
 * lives in app/Helpers/Helper.php. Wire it up or delete it; editing it alone
 * changes no behaviour.
 */
class NotificationService
{
    /**
     * Send notification to superadmin.
     */
    public function sendToSuperadmin(int $id, string $url, string $message, ?string $user = null): void
    {
        $notify = [
            'id' => $id,
            'url' => $url,
            'user' => $user,
            'message' => $message,
        ];

        $notifyUser = User::where('role', 'superadmin')->first();
        if ($notifyUser) {
            Notification::send($notifyUser, new SendNotification($notify));
        }
    }

    /**
     * Send notification to business user.
     */
    public function sendToBusinessUser(int $businessId, string $url, string $message, string $user): void
    {
        $notify = [
            'id' => $businessId,
            'url' => $url,
            'user' => $user,
            'message' => $message,
        ];

        $notifyUser = User::where('business_id', $businessId)->first();
        if ($notifyUser) {
            Notification::send($notifyUser, new SendNotification($notify));
        }
    }

    /**
     * Send notification to specific user.
     */
    public function sendToUser(int $userId, string $url, string $message): void
    {
        $notify = [
            'id' => $userId,
            'url' => $url,
            'user' => null,
            'message' => $message,
        ];

        $notifyUser = User::find($userId);
        if ($notifyUser) {
            Notification::send($notifyUser, new SendNotification($notify));
        }
    }
}
