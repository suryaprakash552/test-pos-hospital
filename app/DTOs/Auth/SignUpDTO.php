<?php

namespace App\DTOs\Auth;

use App\DTOs\BaseDTO;

class SignUpDTO extends BaseDTO
{
    public string $name;
    public string $email;
    public string $password;
    public ?string $password_confirmation;

    public function __construct(array $data)
    {
        $this->name = $data['name'] ?? '';
        $this->email = $data['email'] ?? '';
        $this->password = $data['password'] ?? '';
        $this->password_confirmation = $data['password_confirmation'] ?? null;
    }
}
