<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'reset_token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ];
    }
}
