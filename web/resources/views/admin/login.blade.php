<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin sign in - TMC CampusLF</title>
    @vite('resources/css/app.css')
    <style>
        body.admin-login { min-height:100vh; display:grid; place-items:center; padding:24px; background:radial-gradient(ellipse at 16% 18%,#1d687822,transparent 36%),radial-gradient(ellipse at 82% 80%,#ca8b3b22,transparent 38%),linear-gradient(135deg,#f3f8f6,#e8f2ee 55%,#fff8ec); color:var(--campus-text); }
        .login-card { width:min(100%,420px); padding:34px; border:1px solid var(--campus-border); border-radius:18px; background:#fff; box-shadow:0 22px 60px #123c2a1c,0 2px 8px #123c2a10; }
        .login-card h1 { margin:0 0 8px; color:var(--campus-primary-dark); font-size:24px; }
        .login-card .muted { margin:0 0 26px; color:var(--campus-muted); }
        .login-card label { display:block; margin:16px 0 7px; font-weight:600; }
        .login-card input { width:100%; height:48px; padding:0 12px; border:1px solid var(--campus-border); border-radius:10px; background:#fff; color:var(--campus-text); font:inherit; }
        .login-card input:focus { outline:3px solid #1d687844; border-color:var(--campus-primary); }
        .login-card .login-submit { width:100%; height:48px; margin-top:22px; border:0; border-radius:10px; background:var(--campus-primary-dark); color:#fff; font:inherit; font-size:15px; font-weight:600; cursor:pointer; }
        .login-card .login-submit:hover { background:var(--campus-primary); color:#fff; }
        .login-error { margin-top:7px; color:var(--status-lost); }
        .login-brand { display:flex; align-items:center; gap:12px; margin-bottom:24px; color:var(--campus-primary-dark); }
        .brand-emblems { position:relative; display:block; flex:none; width:55px; height:55px; min-height:55px; }
        .tmc-brand-logo { position:absolute; top:0; left:0; z-index:1; width:40px; height:40px; object-fit:contain; }
        .campuslf-brand-logo { position:absolute; right:0; bottom:0; z-index:2; width:29px; height:29px; object-fit:contain; filter:drop-shadow(0 2px 3px #123c2a24); }
        .login-brand strong { font-size:16px; }
        .login-card .login-submit:focus-visible { outline:3px solid #ca8b3b; outline-offset:3px; }
        .login-card .flash { color:#123c2a; background:#e2f1e9; }
        .login-recovery { display:block; margin-top:14px; color:var(--campus-primary-dark); text-align:center; }
        @media (max-width:480px) { .login-card { padding:26px 22px; } }
    </style>
</head>
<body class="admin-login">
<main class="login-card">
    <div class="login-brand"><span class="brand-emblems" aria-hidden="true"><img class="tmc-brand-logo" src="{{ asset('images/tmc-logo.png') }}" alt=""><img class="campuslf-brand-logo" src="{{ asset('images/campuslf-logo.png') }}" alt=""></span><strong>TMC CampusLF</strong></div>
    <h1>Admin portal</h1>
    <p class="muted">Sign in with your administrator account.</p>
    @if (session('status'))
        <div class="flash" role="status">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="login-error" role="alert">{{ $errors->first() }}</div>
    @endif
    <form method="post" action="{{ route('admin.login.store') }}">
        @csrf
        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email', 'campuslflostfound@gmail.com') }}" autocomplete="username" required>
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <button class="login-submit" type="submit">Sign in</button>
    </form>
    <a class="login-recovery" href="{{ route('admin.password.request') }}">Forgot password?</a>
</main>
</body>
</html>
