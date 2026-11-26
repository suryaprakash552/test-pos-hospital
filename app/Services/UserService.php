<?php

namespace App\Services;

use App\Models\User;
use App\DTOs\UserDTO;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Storage;
use App\Helpers\HasUploader;

/**
 * NOT WIRED UP. Nothing references this class - the equivalent logic currently
 * lives in the controllers. Wire it up or delete it; editing it alone changes
 * no behaviour.
 */
class UserService extends BaseService
{
    use HasUploader;

    /**
     * Get all users with filtering and pagination.
     */
    public function getUsers(array $filters = [], int $perPage = 20)
    {
        $query = User::query();

        // Exclude certain roles if specified
        if (!empty($filters['exclude_roles'])) {
            $query->whereNotIn('role', $filters['exclude_roles']);
        }

        // Apply search filter
        if (!empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('name', 'like', '%' . $filters['search'] . '%')
                  ->orWhere('email', 'like', '%' . $filters['search'] . '%')
                  ->orWhere('role', 'like', '%' . $filters['search'] . '%')
                  ->orWhere('phone', 'like', '%' . $filters['search'] . '%');
            });
        }

        // Apply business filter
        if (!empty($filters['business_id'])) {
            $query->where('business_id', $filters['business_id']);
        }

        return $query->latest()->paginate($perPage);
    }

    /**
     * Create a new user.
     */
    public function createUser(UserDTO $dto): User
    {
        return $this->transaction(function () use ($dto) {
            $user = User::create([
                'name' => $dto->name,
                'email' => $dto->email,
                'phone' => $dto->phone,
                'role' => $dto->role,
                'password' => $dto->password ? Hash::make($dto->password) : null,
                'image' => $dto->image,
                'lang' => $dto->lang,
                'business_id' => $dto->business_id ?? $this->businessId(),
                'branch_id' => $dto->branch_id ?? $this->branchId(),
                'visibility' => $dto->visibility,
            ]);

            // Assign role
            if ($dto->role) {
                $role = Role::where('name', $dto->role)->first();
                if ($role) {
                    $user->roles()->sync($role->id);
                }
            }

            return $user;
        });
    }

    /**
     * Update an existing user.
     */
    public function updateUser(User $user, UserDTO $dto): User
    {
        return $this->transaction(function () use ($user, $dto) {
            $updateData = [
                'name' => $dto->name,
                'email' => $dto->email,
                'phone' => $dto->phone,
                'role' => $dto->role,
                'lang' => $dto->lang,
                'visibility' => $dto->visibility,
            ];

            // Update password if provided
            if ($dto->password) {
                $updateData['password'] = Hash::make($dto->password);
            }

            // Update image if provided
            if ($dto->image) {
                $updateData['image'] = $this->uploadImage($dto->image, $user->image);
            }

            $user->update($updateData);

            // Update role
            if ($dto->role) {
                $role = Role::where('name', $dto->role)->first();
                if ($role) {
                    $user->roles()->sync($role->id);
                }
            }

            return $user->fresh();
        });
    }

    /**
     * Delete a user.
     */
    public function deleteUser(User $user): bool
    {
        return $this->transaction(function () use ($user) {
            // Delete user image
            if ($user->image && Storage::exists($user->image)) {
                Storage::delete($user->image);
            }

            return $user->delete();
        });
    }

    /**
     * Delete multiple users.
     */
    public function deleteMultipleUsers(array $userIds): bool
    {
        return $this->transaction(function () use ($userIds) {
            $users = User::whereIn('id', $userIds)->get();

            foreach ($users as $user) {
                if ($user->image && Storage::exists($user->image)) {
                    Storage::delete($user->image);
                }
            }

            return User::whereIn('id', $userIds)->delete() > 0;
        });
    }

    /**
     * Upload user image.
     */
    protected function uploadImage($image, $existingImage = null)
    {
        // Implementation would go here
        // This is a placeholder for the actual upload logic
        return $existingImage;
    }
}
