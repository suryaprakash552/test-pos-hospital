<?php

namespace App\DTOs\Auth;

use App\DTOs\BaseDTO;

class ResetPasswordDTO extends BaseDTO
{
    public string $email;
    public int $code;
    public string $password;
    public ?string $password_confirmation;

    public function __construct(array $data)
    {
        $this->email = $data['email'] ?? '';
        $this->code = (int) ($data['code'] ?? 0);
        $this->password = $data['password'] ?? '';
        $this->password_confirmation = $data['password_confirmation'] ?? null;
    }
}
