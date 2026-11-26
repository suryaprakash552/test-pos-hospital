<?php

namespace App\Http\Controllers\Api\Customer;

use App\Models\CustomerUser;
use App\Models\CustomerProfile;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class CustomerAuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'unique:customer_users,phone'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'full_name' => ['required', 'string'],
            'address' => ['nullable', 'string'],
            'occupation' => ['nullable', 'string'],
            'alternative_phone' => ['nullable', 'string'],
            'monthly_income' => ['nullable', 'numeric'],
            'email' => ['nullable', 'email'],
            'business_id' => ['nullable', 'integer', 'exists:businesses,id'],
        ]);

        $customerUser = CustomerUser::create([
            'phone' => $validated['phone'],
            'password' => Hash::make($validated['password']),
            'business_id' => $validated['business_id'] ?? null,
        ]);

        CustomerProfile::create([
            'customer_user_id' => $customerUser->id,
            'full_name' => $validated['full_name'],
            'address' => $validated['address'] ?? null,
            'occupation' => $validated['occupation'] ?? null,
            'alternative_phone' => $validated['alternative_phone'] ?? null,
            'monthly_income' => $validated['monthly_income'] ?? null,
            'email' => $validated['email'] ?? null,
        ]);

        $token = $customerUser->createToken('customer-app', [], null)->plainTextToken;

        return response()->json([
            'message' => 'Registration successful',
            'token' => $token,
            'data' => [
                'customer' => $customerUser->load('profile'),
            ],
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $customerUser = CustomerUser::where('phone', $validated['phone'])->first();

        if (!$customerUser || !Hash::check($validated['password'], $customerUser->password)) {
            throw ValidationException::withMessages([
                'phone' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $customerUser->createToken('customer-app', [], null)->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'token' => $token,
            'data' => [
                'customer' => $customerUser->load('profile'),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout successful',
        ]);
    }

    public function profile(Request $request): JsonResponse
    {
        $customerUser = $request->user()->load('profile', 'business:id,companyName,address');

        return response()->json([
            'message' => 'Profile fetched successfully',
            'data' => [
                'customer' => $customerUser,
            ],
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $customerUser = $request->user();

        $validated = $request->validate([
            'full_name' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'occupation' => ['nullable', 'string'],
            'alternative_phone' => ['nullable', 'string'],
            'monthly_income' => ['nullable', 'numeric'],
            'email' => ['nullable', 'email'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif', 'max:2048'],
            'business_id' => ['nullable', 'integer', 'exists:businesses,id'],
        ]);

        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $imageName = time() . '.' . $image->getClientOriginalExtension();
            $image->move(public_path('uploads/customer'), $imageName);
            $validated['image'] = 'uploads/customer/' . $imageName;
        }

        // Update business_id on customer_user if provided
        if (isset($validated['business_id'])) {
            $customerUser->update(['business_id' => $validated['business_id']]);
            unset($validated['business_id']);
        }

        $customerUser->profile()->update($validated);

        return response()->json([
            'message' => 'Profile updated successfully',
            'data' => [
                'customer' => $customerUser->load('profile'),
            ],
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        $customerUser = $request->user();

        if (!Hash::check($validated['current_password'], $customerUser->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $customerUser->update([
            'password' => Hash::make($validated['password']),
        ]);

        return response()->json([
            'message' => 'Password changed successfully',
        ]);
    }

    public function businesses(Request $request): JsonResponse
    {
        $query = \App\Models\Business::select('id', 'companyName', 'address');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('companyName', 'LIKE', "%{$search}%")
                    ->orWhere('address', 'LIKE', "%{$search}%");
            });
        }

        $businesses = $query->get();

        return response()->json([
            'message' => 'Businesses fetched successfully',
            'data' => $businesses,
        ]);
    }
}
