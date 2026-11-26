<?php

namespace App\DTOs\Auth;

use App\DTOs\BaseDTO;

class SubmitOtpDTO extends BaseDTO
{
    public string $email;
    public int $otp;

    public function __construct(array $data)
    {
        $this->email = $data['email'] ?? '';
        $this->otp = (int) ($data['otp'] ?? 0);
    }
}
