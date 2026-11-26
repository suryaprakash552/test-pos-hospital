<?php

namespace App\Exceptions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            if ($this->shouldReport($e)) {
                $this->logException($e);
            }
        });

        // Route model binding failures reached the app as
        // "No query results for model [App\Models\Product] 8885" - a framework
        // internal that names a class and an id the user cannot act on. It also
        // says nothing about the usual cause, which is a list that has gone stale
        // against the record actually being pointed at.
        $this->renderable(function (ModelNotFoundException $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            $model = class_basename($e->getModel());

            return response()->json([
                'message' => __('This :record no longer exists. It may have been deleted already - please refresh and try again.', [
                    'record' => strtolower(preg_replace('/(?<!^)[A-Z]/', ' $0', $model)),
                ]),
                // Kept so the real cause stays diagnosable from the client without
                // the user-facing string having to carry it.
                'error' => [
                    'type' => 'model_not_found',
                    'model' => $model,
                    'ids' => $e->getIds(),
                ],
            ], 404);
        });
    }

    /**
     * Log exception with context.
     */
    protected function logException(Throwable $e): void
    {
        $context = [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'code' => $e->getCode(),
            'url' => request()->fullUrl(),
            'method' => request()->method(),
            'user_id' => auth()->id(),
            'business_id' => auth()->user()?->business_id,
        ];

        if (config('app.debug')) {
            $context['trace'] = $e->getTraceAsString();
        }

        \Log::error('Exception occurred', $context);
    }
}
