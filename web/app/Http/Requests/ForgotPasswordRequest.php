<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'email',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $domain = strtolower(substr(strrchr((string) $value, '@') ?: '', 1));
                    if ($domain !== 'gmail.com') {
                        $fail('Use the Gmail address connected to your account.');
                    }
                },
            ],
        ];
    }
}
