<?php

namespace App\Exceptions;

use Exception;

class UnauthorizedException extends Exception
{
    public function __construct(string $message = "Unauthorized access")
    {
        parent::__construct($message, 401);
    }

    public function render($request)
    {
        return response()->json([
            'success' => false,
            'message' => __($this->message),
            'data' => null,
            'meta' => [],
            'errors' => [],
        ], 401);
    }
}
