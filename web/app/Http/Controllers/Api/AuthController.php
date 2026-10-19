<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResendOtpRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Requests\VerifyOtpRequest;
use App\Mail\RegistrationVerificationCode;
use App\Models\ActivityLog;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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

    public function register(RegisterRequest $request): JsonResponse
    {
        $matched = DB::table('tmc_masterlist')
            ->where('tmc_id_no', $request->string('tmc_id_no')->toString())
            ->whereRaw('LOWER(email) = ?', [strtolower($request->string('email')->toString())])
            ->exists();

        $user = DB::transaction(function () use ($request, $matched): User {
            $user = User::create([
                ...$request->safe()->except(['terms_accepted', 'captcha_answer', 'captcha_token']),
                'email' => strtolower($request->string('email')->toString()),
                'role' => 'user',
                'status' => $matched ? 'active' : 'pending',
                'terms_accepted_at' => now(),
            ]);

            $this->sendRegistrationCode($user);

            return $user;
        });

        return response()->json([
            'message' => $this->registrationCodeMessage(),
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
            ->latest()
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

    public function resendOtp(ResendOtpRequest $request): JsonResponse
    {
        $user = User::where('email', strtolower($request->string('email')->toString()))->firstOrFail();

        if ($user->email_verified_at) {
            return response()->json(['message' => 'This email address is already verified.'], 422);
        }

        DB::transaction(fn () => $this->sendRegistrationCode($user));

        return response()->json(['message' => $this->registrationCodeMessage()]);
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
        ]);
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update($request->validated());

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

    private function sendRegistrationCode(User $user): void
    {
        $code = (string) random_int(100000, 999999);
        $user->otpCodes()->create([
            'code_hash' => Hash::make($code),
            'purpose' => 'registration',
            'expires_at' => now()->addMinutes(10),
        ]);

        Mail::to($user->email)->send(new RegistrationVerificationCode($code));
    }

    private function registrationCodeMessage(): string
    {
        return config('mail.default') === 'log'
            ? 'Local mail mode is enabled. Find the verification code in web/storage/logs/laravel.log.'
            : 'A verification code was sent to your TMC email.';
    }
}
