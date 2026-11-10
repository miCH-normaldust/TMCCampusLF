<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\OtpDeliveryException;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\OtpCodeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AdminAuthController extends Controller
{
    public function create(Request $request): View
    {
        return view('admin.login');
    }

    public function forgotPassword(Request $request): View
    {
        return view('admin.forgot-password', [
            'email' => $request->query('email', old('email', 'campuslflostfound@gmail.com')),
            'showResetForm' => $request->boolean('reset'),
        ]);
    }

    public function sendPasswordResetCode(Request $request, OtpCodeService $codes): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $email = strtolower($data['email']);
        $user = User::query()
            ->where('email', $email)
            ->where('role', 'admin')
            ->where('status', 'active')
            ->whereNotNull('email_verified_at')
            ->first();

        if ($user) {
            try {
                $localFallback = DB::transaction(function () use ($user, $codes): bool {
                    DB::table('password_reset_tokens')->where('email', $user->email)->delete();

                    return $codes->send($user, 'password_reset');
                });
            } catch (OtpDeliveryException) {
                return back()->withErrors([
                    'email' => 'Password recovery email delivery is unavailable. Please try again later.',
                ])->onlyInput('email');
            }

            if ($localFallback) {
                return redirect()->route('admin.password.request', ['reset' => 1, 'email' => $email])
                    ->with('status', 'For local development, the recovery code was written to the mail log.');
            }
        }

        return redirect()->route('admin.password.request', ['reset' => 1, 'email' => $email])
            ->with('status', 'If an active administrator account matches that email, a recovery code has been sent.');
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);
        $email = strtolower($data['email']);
        $reset = DB::transaction(function () use ($email, $data): string {
            $user = User::query()
                ->where('email', $email)
                ->where('role', 'admin')
                ->where('status', 'active')
                ->whereNotNull('email_verified_at')
                ->lockForUpdate()
                ->first();
            if (! $user) {
                return 'invalid';
            }

            $otp = $user->otpCodes()
                ->where('purpose', 'password_reset')
                ->whereNull('verified_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();
            if (! $otp || $otp->expires_at->isPast() || $otp->attempts >= 5) {
                return 'invalid';
            }
            if (! Hash::check($data['code'], $otp->code_hash)) {
                $otp->increment('attempts');

                return 'invalid';
            }

            $otp->forceFill(['verified_at' => now()])->save();
            $user->forceFill(['password' => $data['password']])->save();

            return 'success';
        });

        if ($reset !== 'success') {
            return back()->withErrors([
                'code' => 'The recovery code is incorrect, invalid, or expired. Request a new code.',
            ])->onlyInput('email');
        }

        return redirect()->route('admin.login')
            ->with('status', 'Your administrator password has been reset. Sign in with your new password.');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('admin')->attempt($request->only('email', 'password'))) {
            return back()->withErrors(['email' => 'These credentials do not match an administrator account.'])->onlyInput('email');
        }

        $admin = Auth::guard('admin')->user();
        if ($admin->role !== 'admin' || $admin->status !== 'active') {
            Auth::guard('admin')->logout();

            return back()->withErrors(['email' => 'Users must use the mobile app.'])->onlyInput('email');
        }

        $request->session()->regenerate();
        ActivityLog::create([
            'user_id' => $admin->id,
            'event' => 'admin_login',
            'ip_address' => $request->ip(),
            'device' => Str::limit((string) $request->userAgent(), 255, ''),
        ]);

        return redirect()->route('admin.dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $admin = Auth::guard('admin')->user();
        ActivityLog::create([
            'user_id' => $admin?->id,
            'event' => 'admin_logout',
            'ip_address' => $request->ip(),
            'device' => Str::limit((string) $request->userAgent(), 255, ''),
        ]);
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
