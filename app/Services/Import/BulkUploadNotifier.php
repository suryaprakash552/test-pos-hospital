<?php

namespace App\Services\Import;

use App\Models\User;
use App\Notifications\SendNotification;
use Illuminate\Support\Facades\Log;

final class BulkUploadNotifier
{
    public static function send(int $businessId, int $userId, string $kind, bool $success, int $imported = 0, int $rejected = 0): void
    {
        try {
            $user = User::where('business_id', $businessId)->find($userId);
            if (! $user) {
                return;
            }
            $message = $success
                ? ucfirst($kind)." upload completed: {$imported} rows imported, {$rejected} rows rejected. Review the results before uploading again."
                : ucfirst($kind).' upload failed. Review import history and existing records before retrying; do not upload the same file blindly.';
            $user->notify(new SendNotification([
                'id' => $userId,
                'user' => 'Bulk upload',
                'message' => $message,
                'url' => '',
            ]));
        } catch (\Throwable $e) {
            // Notification failure must not turn a committed import into a
            // failed job or cause employees to repeat an already-saved file.
            Log::warning('Bulk upload notification could not be stored', [
                'business_id' => $businessId, 'user_id' => $userId, 'kind' => $kind,
            ]);
        }
    }
}
