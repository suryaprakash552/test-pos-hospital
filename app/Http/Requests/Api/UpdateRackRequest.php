<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateRackRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $business_id = auth()->user()->business_id;
        $rack_id = $this->route('rack')->id;

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                'unique:racks,name,' . $rack_id . ',id,business_id,' . $business_id,
            ],
        ];
    }

    public function messages()
    {
        return [
            'name.required' => __('Rack name is required.'),
            'name.unique' => __('Rack name already exists.'),
            'name.max' => __('Rack name must not exceed 255 characters.'),
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
