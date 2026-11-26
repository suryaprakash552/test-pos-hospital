<?php

namespace App\DTOs\Auth;

use App\DTOs\BaseDTO;

class VerifyResetCodeDTO extends BaseDTO
{
    public string $email;
    public int $code;

    public function __construct(array $data)
    {
        $this->email = $data['email'] ?? '';
        $this->code = (int) ($data['code'] ?? 0);
    }
}
