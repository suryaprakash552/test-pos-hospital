<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class LoggingService
{
    /**
     * Log payment failure.
     */
    public function logPaymentFailure(array $data): void
    {
        Log::warning('Payment failure', [
            'type' => 'payment_failure',
            'amount' => $data['amount'] ?? null,
            'gateway' => $data['gateway'] ?? null,
            'user_id' => auth()->id(),
            'business_id' => auth()->user()?->business_id,
            'error' => $data['error'] ?? null,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Log authentication failure.
     */
    public function logAuthFailure(array $data): void
    {
        Log::warning('Authentication failure', [
            'type' => 'auth_failure',
            'email' => $data['email'] ?? null,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Log critical business event.
     */
    public function logBusinessEvent(string $event, array $data): void
    {
        Log::info('Business event', [
            'type' => 'business_event',
            'event' => $event,
            'user_id' => auth()->id(),
            'business_id' => auth()->user()?->business_id,
            'data' => $data,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Log system failure.
     */
    public function logSystemFailure(array $data): void
    {
        Log::critical('System failure', [
            'type' => 'system_failure',
            'component' => $data['component'] ?? null,
            'error' => $data['error'] ?? null,
            'url' => request()->fullUrl(),
            'user_id' => auth()->id(),
            'business_id' => auth()->user()?->business_id,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Log external API failure.
     */
    public function logExternalApiFailure(array $data): void
    {
        Log::warning('External API failure', [
            'type' => 'external_api_failure',
            'service' => $data['service'] ?? null,
            'endpoint' => $data['endpoint'] ?? null,
            'status_code' => $data['status_code'] ?? null,
            'response' => $data['response'] ?? null,
            'user_id' => auth()->id(),
            'business_id' => auth()->user()?->business_id,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Log data modification.
     */
    public function logDataModification(string $model, int $id, string $action, array $changes = []): void
    {
        Log::info('Data modification', [
            'type' => 'data_modification',
            'model' => $model,
            'id' => $id,
            'action' => $action,
            'changes' => $changes,
            'user_id' => auth()->id(),
            'business_id' => auth()->user()?->business_id,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Log security event.
     */
    public function logSecurityEvent(string $event, array $data = []): void
    {
        Log::warning('Security event', [
            'type' => 'security_event',
            'event' => $event,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'user_id' => auth()->id(),
            'business_id' => auth()->user()?->business_id,
            'data' => $data,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Log performance metric.
     */
    public function logPerformanceMetric(string $operation, float $duration, array $metadata = []): void
    {
        Log::info('Performance metric', [
            'type' => 'performance',
            'operation' => $operation,
            'duration_ms' => $duration,
            'user_id' => auth()->id(),
            'business_id' => auth()->user()?->business_id,
            'metadata' => $metadata,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
