<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'profile_status' => ['nullable', 'string', 'max:100'],
            'bio' => ['nullable', 'string', 'max:500'],
            'current_address' => ['nullable', 'string', 'max:255'],
            'permanent_address' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:30'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'profile_picture' => [
                'sometimes',
                'nullable',
                'file',
                'max:5120',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (
                        ! $value instanceof UploadedFile
                        || ! $value->isValid()
                        || @getimagesize($value->getRealPath()) === false
                    ) {
                        $fail('The profile picture must be a supported image file.');
                    }
                },
            ],
        ];
    }
}
