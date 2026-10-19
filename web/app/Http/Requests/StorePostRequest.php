<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['lost', 'found'])],
            'item_name' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:3000'],
            'category_id' => ['required', 'exists:categories,id'],
            'color' => ['required', 'string', 'max:50'],
            'location_id' => ['required', 'exists:locations,id'],
            'event_date' => ['required', 'date', 'before_or_equal:today'],
            'custody' => [
                Rule::prohibitedIf($this->input('type') === 'lost'),
                'nullable',
                Rule::requiredIf($this->input('type') === 'found'),
                Rule::in(['with_me', 'office']),
            ],
            'photos' => ['sometimes', 'array', 'max:5'],
            'photos.*.file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'photos.*.alt_text' => ['required', 'string', 'max:200'],
            'questions' => [
                Rule::prohibitedIf($this->input('type') === 'lost'),
                Rule::requiredIf($this->input('type') === 'found'),
                'array',
                'min:1',
                'max:3',
            ],
            'questions.*.question' => ['required_with:questions', 'string', 'max:250'],
            'questions.*.answer' => ['required_with:questions', 'string', 'max:250'],
        ];
    }
}
