<?php

namespace App\Jobs;

use App\Mail\WelcomeMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendOtpMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying.
     */
    public $backoff = [10, 30, 60];

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 30;

    /**
     * The name of the queue the job should be sent to.
     */
    public string $queue = 'emails';

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected string $email,
        protected string $name,
        protected int $code
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Mail::to($this->email)->send(new WelcomeMail([
                'code' => $this->code,
                'name' => $this->name,
            ]));
        } catch (\Exception $e) {
            Log::error('Failed to send OTP email', [
                'email' => $this->email,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
