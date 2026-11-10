<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class RegisterRequest extends FormRequest
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
            'tmc_id_no' => ['required', 'string', 'max:50', 'unique:users,tmc_id_no'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'user_type' => ['required', 'in:student,staff,instructor,other'],
            'user_type_other' => ['required_if:user_type,other', 'nullable', 'string', 'max:100'],
            'department' => ['required', 'in:CCS,CRIM,POLSCI,CAS,BSOA,BSED'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
            'terms_accepted' => ['accepted'],
        ];
    }

    protected function passedValidation(): void
    {
        $emailDomain = strtolower(substr(strrchr($this->string('email'), '@') ?: '', 1));

        if (! hash_equals('gmail.com', $emailDomain)) {
            throw ValidationException::withMessages([
                'email' => 'Use a Gmail address ending in @gmail.com.',
            ]);
        }
    }
}
