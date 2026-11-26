<?php

namespace App\Http\Resources\Auth;

use Illuminate\Http\Resources\Json\JsonResource;

class LoginResponseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        return [
            'is_setup' => $this['is_setup'] ?? false,
            'token' => $this['token'] ?? null,
            'role' => $this['role'] ?? null,
            'business' => $this['business'] ?? null,
            'branch' => $this['branch'] ?? null,
            'currency' => $this['currency'] ? new CurrencyResource($this['currency']) : null,
        ];
    }
}
