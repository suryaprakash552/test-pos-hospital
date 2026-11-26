<?php

namespace App\Http\Resources\Auth;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\BaseJsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'business_id' => $this->business_id,
            'branch_id' => $this->branch_id,
            'is_verified' => $this->is_verified,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
