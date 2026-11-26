<?php

namespace App\DTOs\Auth;

use App\DTOs\BaseDTO;

class LoginDTO extends BaseDTO
{
    public string $email;
    public string $password;
    public ?string $remember;

    public function __construct(array $data)
    {
        $this->email = $data['email'] ?? '';
        $this->password = $data['password'] ?? '';
        $this->remember = $data['remember'] ?? null;
    }
}
