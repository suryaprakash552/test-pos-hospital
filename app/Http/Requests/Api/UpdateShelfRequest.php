<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateShelfRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $business_id = auth()->user()->business_id;
        $shelf_id = $this->route('shelf')->id;

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                'unique:shelfs,name,' . $shelf_id . ',id,business_id,' . $business_id,
            ],
        ];
    }

    public function messages()
    {
        return [
            'name.required' => __('Shelf name is required.'),
            'name.unique' => __('Shelf name already exists.'),
            'name.max' => __('Shelf name must not exceed 255 characters.'),
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'message' => __('Validation failed.'),
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
