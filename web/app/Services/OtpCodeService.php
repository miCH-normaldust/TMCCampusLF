<?php

namespace App\Services;

use App\Exceptions\OtpDeliveryException;
use App\Mail\OneTimeCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class OtpCodeService
{
    public function send(User $user, string $purpose): bool
    {
        abort_unless(in_array($purpose, ['registration', 'password_reset'], true), 500);

        $code = (string) random_int(100000, 999999);
        $user->otpCodes()->create([
            'code_hash' => Hash::make($code),
            'purpose' => $purpose,
            'expires_at' => now()->addMinutes(10),
        ]);

        try {
            Mail::to($user->email)->send(new OneTimeCode($code, $purpose));
        } catch (TransportExceptionInterface $error) {
            if (config('app.env') === 'local' && config('mail.otp_failure_fallback')) {
                Mail::mailer('log')->to($user->email)->send(new OneTimeCode($code, $purpose));
                Log::warning('OTP email failed; code written to the local mail log.', [
                    'user_id' => $user->id,
                    'purpose' => $purpose,
                ]);

                return true;
            }

            Log::error('OTP email delivery failed.', [
                'user_id' => $user->id,
                'purpose' => $purpose,
                'exception' => $error::class,
            ]);

            throw new OtpDeliveryException('OTP email delivery is unavailable.');
        }

        return false;
    }
}
