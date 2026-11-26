<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Helpers\HasUploader;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;

class AcnooProfileController extends Controller
{
    use HasUploader;

    public function index()
    {
        $user = User::with('business')->findOrFail(auth()->id());
        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $user
        ]);
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'name'  => 'required|max:250',
                'email' => ['required', 'email', Rule::unique('users')->ignore(auth()->id())],
                'image' => 'nullable|image|mimes:jpeg,png,gif|dimensions:max_width=2000,max_height=2000|max:1048',
            ]);

            $user = User::findOrFail(auth()->id());

            $user->update([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'image' => $request->image ? $this->upload($request, 'image', $user->image) : $user->image,
            ]);

            $data = [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'image' => $user->image,
            ];

            return response()->json([
                'message' => __('Profile updated successfully.'),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to update profile.')
            ], 500);
        }
    }

    public function changePassword(Request $request)
    {
        try {
            $request->validate([
                'current_password' => 'required',
                'password' => 'required|string|min:6',
            ]);

            $user = auth()->user();

            if ($request->current_password == $request->password){
                return response()->json([
                    'message' => __('You have already used this password.')
                ], 422);
            }

            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'message' => __('Current password does not match with old password.')
                ], 422);
            }

            $user->update([
                'password' => Hash::make($request->password),
            ]);

            return response()->json([
                'message'   => __('Password changed successfully.'),
            ]);
        } catch (ValidationException $e) {
            // Let the framework render it.
            //
            // `ValidationException` is an `\Exception`, so the catch-all below
            // was swallowing it and answering 500 with "Failed to change
            // password" — for a password that was merely too short. The user
            // was told the server had broken, and the app had no field errors
            // to show, so there was no way to learn what was actually wrong.
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to change password.')
            ], 500);
        }
    }
}
