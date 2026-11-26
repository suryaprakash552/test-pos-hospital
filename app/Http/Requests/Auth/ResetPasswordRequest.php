<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'email' => 'required|email|exists:users,email',
            'code' => 'required|integer|min:100000|max:999999',
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'email.required' => __('validation.email_required'),
            'email.email' => __('validation.email_email'),
            'email.exists' => __('validation.email_exists'),
            'code.required' => __('validation.code_required'),
            'code.integer' => __('validation.code_integer'),
            'code.min' => __('validation.code_min'),
            'code.max' => __('validation.code_max'),
            'password.required' => __('validation.password_required'),
            'password.confirmed' => __('validation.password_confirmed'),
            'password.min' => __('validation.password_min'),
        ];
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => __('validation.validation_failed'),
                'data' => null,
                'meta' => [],
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
