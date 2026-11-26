<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendUserNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [15, 60, 180];

    public int $timeout = 30;

    public function __construct(
        private readonly int $id,
        private readonly string $url,
        private readonly string $message,
        private readonly int $businessId,
    ) {
        $this->onQueue(config('pos.queues.notifications', 'default'));
    }

    public function handle(): void
    {
        sendNotifyToUser($this->id, $this->url, $this->message, $this->businessId);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('User notification job failed', [
            'id' => $this->id,
            'business_id' => $this->businessId,
            'error' => $exception->getMessage(),
        ]);
    }
}
