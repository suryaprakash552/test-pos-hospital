<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes a write endpoint safe to retry.
 *
 * A till that loses its connection mid-checkout does not know whether the sale was
 * created. It retries. Without this the retry creates a second sale and deducts the
 * stock again. With an `Idempotency-Key`, the retry is recognised as the same
 * attempt and given the original answer back.
 *
 * The key is claimed with a unique insert before the request runs, so two
 * simultaneous retries race at the database and exactly one proceeds.
 *
 * Applying this without a key is a no-op, which keeps older clients working.
 */
class EnsureIdempotentRequest
{
    /**
     * Only these are replayable; a GET has nothing to protect.
     */
    private const GUARDED_METHODS = ['POST', 'PUT', 'PATCH'];

    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->keyFrom($request);

        if ($key === null || ! in_array($request->method(), self::GUARDED_METHODS, true)) {
            return $next($request);
        }

        $businessId = (int) (auth()->user()?->business_id ?? 0);
        $endpoint = $request->method().' '.$request->path();
        $fingerprint = $this->fingerprint($request);

        $existing = $this->claim($businessId, $endpoint, $key, $fingerprint);

        if ($existing !== null) {
            return $this->replay($existing, $fingerprint);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            // A failed attempt must not burn the key: the caller has to be able to
            // retry the same checkout once the fault is fixed.
            $this->release($businessId, $endpoint, $key);

            throw $e;
        }

        $this->complete($businessId, $endpoint, $key, $response);

        return $response;
    }

    /**
     * The header is the standard carrier; the body field is accepted because the
     * app posts multipart forms where setting a header is awkward.
     */
    private function keyFrom(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key')
            ?? $request->header('X-Idempotency-Key')
            ?? $request->input('idempotency_key');

        $key = is_string($key) ? trim($key) : '';

        // Bounded so a client cannot use this as arbitrary storage.
        return $key !== '' && strlen($key) <= 191 ? $key : null;
    }

    /**
     * Take the key, or report the row that already holds it.
     *
     * @return object|null The existing row when the key is already claimed.
     */
    private function claim(int $businessId, string $endpoint, string $key, string $fingerprint): ?object
    {
        try {
            DB::table('idempotency_keys')->insert([
                'business_id' => $businessId,
                'user_id' => auth()->id(),
                'idempotency_key' => $key,
                'endpoint' => $endpoint,
                'request_fingerprint' => $fingerprint,
                'status' => 'processing',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return null;
        } catch (QueryException $e) {
            // 23000 is the integrity-constraint class: the unique index rejected
            // this insert, so another attempt with the same key got there first.
            if (($e->errorInfo[0] ?? null) !== '23000') {
                throw $e;
            }

            return $this->find($businessId, $endpoint, $key);
        }
    }

    private function find(int $businessId, string $endpoint, string $key): ?object
    {
        return DB::table('idempotency_keys')
            ->where('business_id', $businessId)
            ->where('endpoint', $endpoint)
            ->where('idempotency_key', $key)
            ->first();
    }

    /**
     * Answer a repeat: the stored response, or a signal that one is still in flight.
     */
    private function replay(object $existing, string $fingerprint): Response
    {
        // Same key, different body. Returning the first response would be wrong -
        // this is a second, genuinely different request wearing the same name.
        if ($existing->request_fingerprint !== null
            && $existing->request_fingerprint !== $fingerprint
        ) {
            return response()->json([
                'message' => 'This idempotency key was already used for a different request.',
                'reason' => 'idempotency_key_reused',
            ], 422);
        }

        if ($existing->status !== 'completed') {
            return response()->json([
                'message' => 'A request with this idempotency key is still being processed.',
                'reason' => 'idempotency_in_progress',
            ], 409);
        }

        return response()->json(
            json_decode((string) $existing->response_body, true),
            (int) ($existing->response_status ?? 200),
        )->header('Idempotent-Replay', 'true');
    }

    private function complete(int $businessId, string $endpoint, string $key, Response $response): void
    {
        // Only a success is worth replaying. A refusal is left un-stored and the
        // key released, so the caller may correct the request and try again.
        if ($response->getStatusCode() >= 400) {
            $this->release($businessId, $endpoint, $key);

            return;
        }

        DB::table('idempotency_keys')
            ->where('business_id', $businessId)
            ->where('endpoint', $endpoint)
            ->where('idempotency_key', $key)
            ->update([
                'status' => 'completed',
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getContent(),
                'updated_at' => now(),
            ]);
    }

    private function release(int $businessId, string $endpoint, string $key): void
    {
        DB::table('idempotency_keys')
            ->where('business_id', $businessId)
            ->where('endpoint', $endpoint)
            ->where('idempotency_key', $key)
            ->where('status', 'processing')
            ->delete();
    }

    /**
     * A hash of the meaningful input, so a reused key with a changed cart is caught.
     * Files are excluded: their transient upload paths differ between retries.
     */
    private function fingerprint(Request $request): string
    {
        $payload = $request->except(['idempotency_key', '_token']);

        ksort($payload);

        return hash('sha256', json_encode($payload));
    }
}
