<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Exceptions\OtpDeliveryException;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResendOtpRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Requests\VerifyOtpRequest;
use App\Http\Requests\VerifyPasswordResetOtpRequest;
use App\Models\ActivityLog;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\ImageReencoder;
use App\Services\OtpCodeService;
use App\Support\PublicMediaUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function captcha(): JsonResponse
    {
        $left = random_int(2, 9);
        $right = random_int(1, 9);
        $token = Str::random(64);
        DB::table('captcha_challenges')->where('expires_at', '<', now())->delete();
        DB::table('captcha_challenges')->insert([
            'token_hash' => hash('sha256', $token),
            'answer_hash' => Hash::make((string) ($left + $right)),
            'expires_at' => now()->addMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'question' => "{$left} + {$right} = ?",
            'token' => $token,
        ]);
    }

    public function register(RegisterRequest $request, OtpCodeService $codes): JsonResponse
    {
        $matched = DB::table('tmc_masterlist')
            ->where('tmc_id_no', $request->string('tmc_id_no')->toString())
            ->whereRaw('LOWER(email) = ?', [strtolower($request->string('email')->toString())])
            ->exists();

        try {
            [$user, $localFallback] = DB::transaction(function () use ($request, $matched, $codes): array {
                $user = User::create([
                    ...$request->safe()->except(['terms_accepted', 'captcha_answer', 'captcha_token']),
                    'email' => strtolower($request->string('email')->toString()),
                    'role' => 'user',
                    'status' => $matched ? 'active' : 'pending',
                    'terms_accepted_at' => now(),
                ]);

                $localFallback = $codes->send($user, 'registration');

                return [$user, $localFallback];
            });
        } catch (OtpDeliveryException) {
            return $this->otpDeliveryUnavailable();
        }

        return response()->json([
            'message' => $this->registrationCodeMessage($localFallback),
            'email' => $user->email,
            'status' => $user->status,
        ], 201);
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $user = User::where('email', strtolower($request->string('email')->toString()))->firstOrFail();
        $otp = $user->otpCodes()
            ->where('purpose', 'registration')
            ->whereNull('verified_at')
            ->latest('id')
            ->first();

        if (! $otp || $otp->expires_at->isPast() || $otp->attempts >= 5) {
            return response()->json(['message' => 'The code is invalid or expired. Request a new code.'], 422);
        }

        if (! Hash::check($request->string('code')->toString(), $otp->code_hash)) {
            $otp->increment('attempts');

            return response()->json(['message' => 'The code is incorrect.'], 422);
        }

        $otp->forceFill(['verified_at' => now()])->save();
        $user->forceFill(['email_verified_at' => now()])->save();

        return response()->json([
            'message' => $user->status === 'pending'
                ? 'Email verified. Your registration is waiting for TMC approval.'
                : 'Email verified. You can now sign in.',
            'status' => $user->status,
        ]);
    }

    public function resendOtp(ResendOtpRequest $request, OtpCodeService $codes): JsonResponse
    {
        $user = User::where('email', strtolower($request->string('email')->toString()))->firstOrFail();

        if ($user->email_verified_at) {
            return response()->json(['message' => 'This email address is already verified.'], 422);
        }

        try {
            $localFallback = DB::transaction(fn (): bool => $codes->send($user, 'registration'));
        } catch (OtpDeliveryException) {
            return $this->otpDeliveryUnavailable();
        }

        return response()->json(['message' => $this->registrationCodeMessage($localFallback)]);
    }

    public function requestPasswordReset(ForgotPasswordRequest $request, OtpCodeService $codes): JsonResponse
    {
        $email = strtolower($request->string('email')->toString());
        $user = User::where('email', $email)
            ->where('role', 'user')
            ->whereNotNull('email_verified_at')
            ->first();

        if (! $user) {
            return response()->json([
                'message' => 'No verified user account is connected to this Gmail address.',
            ], 404);
        }

        try {
            $localFallback = DB::transaction(function () use ($user, $codes): bool {
                DB::table('password_reset_tokens')->where('email', $user->email)->delete();
                return $codes->send($user, 'password_reset');
            });
        } catch (OtpDeliveryException) {
            return $this->otpDeliveryUnavailable();
        }

        return response()->json(['message' => $this->passwordResetCodeMessage($localFallback)]);
    }

    public function verifyPasswordResetOtp(VerifyPasswordResetOtpRequest $request): JsonResponse
    {
        $email = strtolower($request->string('email')->toString());
        $code = $request->string('code')->toString();

        $resetToken = DB::transaction(function () use ($email, $code): ?string {
            $user = User::where('email', $email)
                ->where('role', 'user')
                ->whereNotNull('email_verified_at')
                ->first();

            if (! $user) {
                return null;
            }

            $otp = $user->otpCodes()
                ->where('purpose', 'password_reset')
                ->whereNull('verified_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $otp || $otp->expires_at->isPast() || $otp->attempts >= 5) {
                return null;
            }

            if (! Hash::check($code, $otp->code_hash)) {
                $otp->increment('attempts');

                return null;
            }

            $otp->forceFill(['verified_at' => now()])->save();
            $token = Str::random(64);
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                ['token' => Hash::make($token), 'created_at' => now()],
            );

            return $token;
        });

        if (! $resetToken) {
            return response()->json([
                'message' => 'The code is incorrect, invalid, or expired. Request a new code.',
            ], 422);
        }

        return response()->json([
            'message' => 'Code verified. You can now set a new password.',
            'reset_token' => $resetToken,
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $email = strtolower($request->string('email')->toString());
        $resetToken = $request->string('reset_token')->toString();

        $updated = DB::transaction(function () use ($email, $resetToken, $request): bool {
            $record = DB::table('password_reset_tokens')
                ->where('email', $email)
                ->lockForUpdate()
                ->first();

            if (! $record
                || ! $record->created_at
                || now()->greaterThanOrEqualTo(Carbon::parse($record->created_at)->addMinutes(10))
                || ! Hash::check($resetToken, $record->token)) {
                return false;
            }

            $user = User::where('email', $email)->where('role', 'user')->first();
            if (! $user) {
                return false;
            }

            $user->update(['password' => $request->string('password')->toString()]);
            $user->tokens()->delete();
            $user->otpCodes()->where('purpose', 'password_reset')->delete();
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            return true;
        });

        if (! $updated) {
            return response()->json([
                'message' => 'The password reset session is invalid or expired. Request a new code.',
            ], 422);
        }

        return response()->json(['message' => 'Password changed. You can now sign in.']);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', strtolower($request->string('email')->toString()))->first();

        if ($user?->role === 'admin') {
            return response()->json(['message' => 'Admins use the web portal.'], 403);
        }

        if (! $user || ! Hash::check($request->string('password')->toString(), $user->password)) {
            return response()->json(['message' => 'The supplied credentials are incorrect.'], 422);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'message' => $user->status === 'pending'
                    ? 'Your account is awaiting TMC approval.'
                    : 'This account is not active. Contact a TMC administrator.',
            ], 403);
        }

        if (! $user->email_verified_at) {
            return response()->json(['message' => 'Verify your TMC email before signing in.'], 403);
        }

        $token = $user->createToken('mobile')->plainTextToken;
        ActivityLog::create([
            'user_id' => $user->id,
            'event' => 'login',
            'ip_address' => $request->ip(),
            'device' => Str::limit((string) $request->userAgent(), 255, ''),
        ]);

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);
    }

    public function logout(): JsonResponse
    {
        $user = request()->user();
        ActivityLog::create([
            'user_id' => $user->id,
            'event' => 'logout',
            'ip_address' => request()->ip(),
            'device' => Str::limit((string) request()->userAgent(), 255, ''),
        ]);
        $user->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out successfully.']);
    }

    public function profile(): JsonResponse
    {
        $user = request()->user();

        return response()->json([
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'user_type' => $user->user_type,
            'user_type_other' => $user->user_type_other,
            'department' => $user->department,
            'status' => $user->status,
            'profile_status' => $user->profile_status,
            'bio' => $user->bio,
            'profile_picture_url' => $user->profile_picture_path
                ? PublicMediaUrl::forPath($user->profile_picture_path)
                : null,
            'current_address' => $user->current_address,
            'permanent_address' => $user->permanent_address,
            'contact_number' => $user->contact_number,
            'contact_email' => $user->contact_email,
        ]);
    }

    public function updateProfile(UpdateProfileRequest $request, ImageReencoder $images): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $picture = $data['profile_picture'] ?? null;
        unset($data['profile_picture']);

        if ($picture) {
            $newPath = $images->store($picture, 'profiles');
            $oldPath = $user->profile_picture_path;
            try {
                $user->update([...$data, 'profile_picture_path' => $newPath]);
            } catch (\Throwable $error) {
                Storage::disk('public')->delete($newPath);
                throw $error;
            }
            if ($oldPath) {
                Storage::disk('public')->delete($oldPath);
            }
        } else {
            $user->update($data);
        }

        return $this->profile();
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! Hash::check($request->string('current_password')->toString(), $user->password)) {
            return response()->json(['message' => 'The current password is incorrect.'], 422);
        }

        $currentTokenId = $user->currentAccessToken()?->getKey();
        $user->tokens()->when($currentTokenId, fn ($query) => $query->where('id', '!=', $currentTokenId))->delete();
        $user->update(['password' => $request->string('password')->toString()]);

        return response()->json(['message' => 'Password changed. Other signed-in devices were logged out.']);
    }

    private function registrationCodeMessage(bool $localFallback = false): string
    {
        if ($localFallback) {
            return 'Gmail delivery is unavailable. For local testing, find the verification code in web/storage/logs/laravel.log.';
        }

        return config('mail.default') === 'log'
            ? 'Local mail mode is enabled. Find the verification code in web/storage/logs/laravel.log.'
            : 'A verification code was sent to your TMC email.';
    }

    private function passwordResetCodeMessage(bool $localFallback = false): string
    {
        if ($localFallback) {
            return 'Gmail delivery is unavailable. For local testing, find the password reset code in web/storage/logs/laravel.log.';
        }

        return config('mail.default') === 'log'
            ? 'Local mail mode is enabled. Find the password reset code in web/storage/logs/laravel.log.'
            : 'A password reset code was sent to your Gmail address.';
    }

    private function otpDeliveryUnavailable(): JsonResponse
    {
        return response()->json([
            'message' => 'Email delivery is unavailable. Please contact the administrator or try again later.',
        ], 503);
    }
}
