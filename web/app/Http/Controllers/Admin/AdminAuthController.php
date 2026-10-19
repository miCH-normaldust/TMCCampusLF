<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AdminAuthController extends Controller
{
    public function create(Request $request): View
    {
        $left = random_int(2, 9);
        $right = random_int(1, 9);
        $request->session()->put('admin_login_captcha_answer', (string) ($left + $right));

        return view('admin.login', ['captchaQuestion' => "{$left} + {$right} = ?"]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'captcha_answer' => ['required', 'string', 'max:10'],
        ]);

        $expected = (string) $request->session()->pull('admin_login_captcha_answer', '');
        if ($expected === '' || ! hash_equals($expected, $request->string('captcha_answer')->toString())) {
            return back()->withErrors(['captcha_answer' => 'The security answer is incorrect.'])->onlyInput('email');
        }

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
