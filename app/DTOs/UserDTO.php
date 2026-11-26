<?php

namespace App\DTOs;

class UserDTO extends BaseDTO
{
    public string $name;
    public string $email;
    public ?string $phone;
    public string $role;
    public ?string $password;
    public ?string $image;
    public ?string $lang;
    public ?int $business_id;
    public ?int $branch_id;
    public ?array $visibility;

    public function __construct(array $data)
    {
        $this->name = $data['name'] ?? '';
        $this->email = $data['email'] ?? '';
        $this->phone = $data['phone'] ?? null;
        $this->role = $data['role'] ?? 'staff';
        $this->password = $data['password'] ?? null;
        $this->image = $data['image'] ?? null;
        $this->lang = $data['lang'] ?? null;
        $this->business_id = $data['business_id'] ?? null;
        $this->branch_id = $data['branch_id'] ?? null;
        $this->visibility = $data['visibility'] ?? null;
    }
}
