<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateCategoryRequest extends FormRequest
{
    protected array $booleanFields = [
        'variationCapacity',
        'variationColor',
        'variationSize',
        'variationType',
        'variationWeight',
    ];

    public function authorize()
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $values = [];

        foreach ($this->booleanFields as $field) {
            if ($this->has($field)) {
                $values[$field] = filter_var($this->input($field), FILTER_VALIDATE_BOOLEAN);
            }
        }

        $this->merge($values);
    }

    public function rules()
    {
        $business_id = auth()->user()->business_id;
        $category_id = $this->route('category')->id;

        return [
            'categoryName' => [
                'required',
                'string',
                'max:255',
                'unique:categories,categoryName,' . $category_id . ',id,business_id,' . $business_id,
            ],
            'variationCapacity' => 'nullable|boolean',
            'variationColor' => 'nullable|boolean',
            'variationSize' => 'nullable|boolean',
            'variationType' => 'nullable|boolean',
            'variationWeight' => 'nullable|boolean',
        ];
    }

    public function messages()
    {
        return [
            'categoryName.required' => __('Category name is required.'),
            'categoryName.unique' => __('Category name already exists.'),
            'categoryName.max' => __('Category name must not exceed 255 characters.'),
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
