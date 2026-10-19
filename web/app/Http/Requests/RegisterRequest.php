<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
            'captcha_token' => ['required', 'string'],
            'captcha_answer' => ['required', 'string', 'max:10'],
        ];
    }

    protected function passedValidation(): void
    {
        $failure = DB::transaction(function (): ?string {
            $challenge = DB::table('captcha_challenges')
                ->where('token_hash', hash('sha256', $this->string('captcha_token')->toString()))
                ->lockForUpdate()
                ->first();

            if (! $challenge) {
                return 'The security check expired. Please request a new challenge.';
            }

            DB::table('captcha_challenges')->where('id', $challenge->id)->delete();

            if (now()->greaterThanOrEqualTo($challenge->expires_at)) {
                return 'The security check expired. Please request a new challenge.';
            }

            if (! Hash::check($this->string('captcha_answer')->toString(), $challenge->answer_hash)) {
                return 'The security answer is incorrect. Please try the new challenge.';
            }

            return null;
        });

        if ($failure !== null) {
            throw ValidationException::withMessages([
                'captcha_answer' => $failure,
            ]);
        }

        $emailDomain = strtolower(substr(strrchr($this->string('email'), '@') ?: '', 1));

        if (! hash_equals('gmail.com', $emailDomain)) {
            throw ValidationException::withMessages([
                'email' => 'Use a Gmail address ending in @gmail.com.',
            ]);
        }
    }
}
