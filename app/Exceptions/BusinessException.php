<?php

namespace App\Exceptions;

use Exception;

class BusinessException extends Exception
{
    protected $message;
    protected $code;
    protected $errors;

    public function __construct(string $message = "Business logic error", int $code = 400, array $errors = [])
    {
        parent::__construct($message, $code);
        $this->message = $message;
        $this->code = $code;
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function render($request)
    {
        return response()->json([
            'success' => false,
            'message' => __($this->message),
            'data' => null,
            'meta' => [],
            'errors' => $this->errors,
        ], $this->code);
    }
}
