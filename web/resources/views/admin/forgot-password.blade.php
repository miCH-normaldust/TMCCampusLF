<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin password recovery - TMC CampusLF</title>
    @vite('resources/css/app.css')
    <style>
        body.admin-login { min-height:100vh; display:grid; place-items:center; padding:24px; background:radial-gradient(ellipse at 16% 18%,#1d687822,transparent 36%),radial-gradient(ellipse at 82% 80%,#ca8b3b22,transparent 38%),linear-gradient(135deg,#f3f8f6,#e8f2ee 55%,#fff8ec); color:var(--campus-text); }
        .login-card { width:min(100%,460px); padding:34px; border:1px solid var(--campus-border); border-radius:18px; background:#fff; box-shadow:0 22px 60px #123c2a1c,0 2px 8px #123c2a10; }
        .login-card h1 { margin:0 0 8px; color:var(--campus-primary-dark); font-size:24px; }
        .login-card p { color:var(--campus-muted); line-height:1.55; }
        .login-card label { display:block; margin:16px 0 7px; font-weight:600; }
        .login-card input { width:100%; height:48px; padding:0 12px; border:1px solid var(--campus-border); border-radius:10px; background:#fff; color:var(--campus-text); font:inherit; }
        .login-card input:focus { outline:3px solid #1d687844; border-color:var(--campus-primary); }
        .login-card .login-submit { width:100%; height:48px; margin-top:22px; border:0; border-radius:10px; background:var(--campus-primary-dark); color:#fff; font:inherit; font-weight:600; cursor:pointer; }
        .login-brand { display:flex; align-items:center; gap:12px; margin-bottom:24px; color:var(--campus-primary-dark); }
        .brand-emblems { position:relative; display:block; flex:none; width:55px; height:55px; min-height:55px; }
        .tmc-brand-logo { position:absolute; top:0; left:0; z-index:1; width:40px; height:40px; object-fit:contain; }
        .campuslf-brand-logo { position:absolute; right:0; bottom:0; z-index:2; width:29px; height:29px; object-fit:contain; }
        .login-brand strong { font-size:16px; }
        .login-recovery { display:block; margin-top:16px; color:var(--campus-primary-dark); text-align:center; }
        .login-error { color:var(--status-lost); }
        @media (max-width:480px) { .login-card { padding:26px 22px; } }
    </style>
</head>
<body class="admin-login">
<main class="login-card">
    <div class="login-brand"><span class="brand-emblems" aria-hidden="true"><img class="tmc-brand-logo" src="{{ asset('images/tmc-logo.png') }}" alt=""><img class="campuslf-brand-logo" src="{{ asset('images/campuslf-logo.png') }}" alt=""></span><strong>TMC CampusLF</strong></div>
    <h1>Reset admin password</h1>
    <p>Request a one-time recovery code sent to your administrator email. Codes expire after 10 minutes.</p>
    @if (session('status'))<div class="flash" role="status">{{ session('status') }}</div>@endif
    @if ($errors->any())<p class="login-error" role="alert">{{ $errors->first() }}</p>@endif

    @if ($showResetForm)
        <form method="post" action="{{ route('admin.password.update') }}">
            @csrf
            <input type="hidden" name="email" value="{{ $email }}">
            <label for="code">Recovery code</label>
            <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
            <label for="password">New password</label>
            <input id="password" name="password" type="password" autocomplete="new-password" minlength="10" required>
            <label for="password_confirmation">Confirm new password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="10" required>
            <button class="login-submit" type="submit">Reset password</button>
        </form>
        <form method="post" action="{{ route('admin.password.email') }}">
            @csrf
            <input type="hidden" name="email" value="{{ $email }}">
            <button class="login-recovery" type="submit">Send a new recovery code</button>
        </form>
    @else
        <form method="post" action="{{ route('admin.password.email') }}">
            @csrf
            <label for="email">Administrator email</label>
            <input id="email" name="email" type="email" value="{{ $email }}" autocomplete="username" required>
            <button class="login-submit" type="submit">Send recovery code</button>
        </form>
    @endif
    <a class="login-recovery" href="{{ route('admin.login') }}">Back to sign in</a>
</main>
</body>
</html>
