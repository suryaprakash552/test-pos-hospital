<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendSmsMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [15, 60, 180];

    public int $timeout = 30;

    public function __construct(
        private readonly string $phone,
        private readonly string $message,
    ) {
        $this->onQueue(config('pos.queues.notifications', 'default'));
    }

    public function handle(): void
    {
        sendMessage($this->phone, $this->message);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SMS message job failed', [
            'phone' => $this->phone,
            'error' => $exception->getMessage(),
        ]);
    }
}
