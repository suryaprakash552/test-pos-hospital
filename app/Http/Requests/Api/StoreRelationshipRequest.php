<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreRelationshipRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $business_id = auth()->user()->business_id;

        return [
            'relationship' => [
                'required',
                'string',
                'max:255',
                'unique:relationships,relationship,NULL,id,business_id,' . $business_id,
            ],
        ];
    }

    public function messages()
    {
        return [
            'relationship.required' => __('Relationship name is required.'),
            'relationship.unique' => __('Relationship name already exists.'),
            'relationship.max' => __('Relationship name must not exceed 255 characters.'),
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
