<?php

namespace App\DTOs\Auth;

use App\DTOs\BaseDTO;

class ResendOtpDTO extends BaseDTO
{
    public string $email;

    public function __construct(array $data)
    {
        $this->email = $data['email'] ?? '';
    }
}
