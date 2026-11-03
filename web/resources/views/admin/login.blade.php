<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin sign in - TMC CampusLF</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@600;700&display=swap" rel="stylesheet">
    @vite('resources/css/app.css')
    <style>
        body.admin-login {
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            background: linear-gradient(135deg, var(--campus-primary), var(--campus-surface) 52%, var(--campus-accent));
            color: var(--campus-text);
            font: 15px Inter, sans-serif;
        }
        .login-card {
            width: min(100%, 420px);
            padding: 34px;
            border: 1px solid var(--campus-border);
            border-radius: 18px;
            background: var(--campus-raised);
            box-shadow: 0 18px 50px #26323d20;
        }
        .login-card h1 {
            margin: 0 0 8px;
            color: var(--campus-primary-dark);
            font: 700 24px Poppins, sans-serif;
        }
        .login-card .muted {
            margin: 0 0 26px;
            color: var(--campus-muted);
        }
        .login-card label {
            display: block;
            margin: 16px 0 7px;
            font-weight: 600;
        }
        .login-card input {
            width: 100%;
            height: 48px;
            padding: 0 12px;
            border: 1px solid var(--campus-border);
            border-radius: 10px;
            background: var(--campus-raised);
            color: var(--campus-text);
            font: inherit;
        }
        .login-card input:focus {
            outline: 3px solid color-mix(in srgb, var(--campus-accent) 70%, transparent);
            border-color: var(--campus-primary-dark);
        }
        .login-card .login-submit {
            width: 100%;
            height: 48px;
            margin-top: 22px;
            border: 0;
            border-radius: 10px;
            background: var(--campus-primary-dark);
            color: #fff;
            font: 600 15px Inter, sans-serif;
            cursor: pointer;
        }
        .login-card .login-submit:hover {
            background: var(--campus-primary);
            color: var(--campus-text);
        }
        .login-error {
            margin-top: 7px;
            color: var(--status-lost);
        }
        .login-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
            color: var(--campus-primary-dark);
        }
        .campuslf-brand-mark {
            position: relative;
            display: block;
            flex: none;
            width: 52px;
            height: 62px;
        }
        .campuslf-brand-mark .brand-lf {
            position: absolute;
            top: 0;
            left: 0;
            z-index: 1;
            display: grid;
            place-items: center;
            width: 40px;
            height: 40px;
            border-radius: 11px;
            background: var(--campus-primary);
            color: var(--campus-primary-dark);
            font: 700 17px Poppins, sans-serif;
        }
        .campuslf-brand-mark img {
            position: absolute;
            right: 0;
            bottom: 0;
            z-index: 2;
            width: 34px;
            height: 34px;
            padding: 2px;
            border: 1px solid var(--campus-raised);
            border-radius: 50%;
            background: var(--campus-raised);
            object-fit: contain;
        }
        .login-theme-toggle {
            position: fixed;
            top: 18px;
            right: 18px;
        }
        body[data-theme="dark"] .login-card {
            box-shadow: 0 18px 50px #00000055;
        }
        @media (max-width: 480px) {
            .login-card { padding: 26px 22px; }
            .login-theme-toggle { top: 10px; right: 10px; }
        }
    </style>
</head>
<body class="admin-login">
<button class="button secondary login-theme-toggle" type="button" aria-pressed="false">Dark mode</button>
<main class="login-card">
    <div class="login-brand"><span class="campuslf-brand-mark" aria-hidden="true"><span class="brand-lf">LF</span><img src="{{ asset('images/tmc-logo.png') }}" alt=""></span><strong>TMC CampusLF</strong></div>
    <h1>Admin portal</h1>
    <p class="muted">Sign in with your administrator account.</p>
    @if ($errors->any())
        <div class="login-error" role="alert">{{ $errors->first() }}</div>
    @endif
    <form method="post" action="{{ route('admin.login.store') }}">
        @csrf
        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required>
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <label for="captcha_answer">Security check: {{ $captchaQuestion }}</label>
        <input id="captcha_answer" name="captcha_answer" inputmode="numeric" autocomplete="off" required>
        <button class="login-submit" type="submit">Sign in</button>
    </form>
</main>
<script>
    (() => {
        const toggle = document.querySelector('.login-theme-toggle');
        if (!toggle) return;
        const applyTheme = dark => {
            document.body.dataset.theme = dark ? 'dark' : 'light';
            toggle.textContent = dark ? 'Light mode' : 'Dark mode';
            toggle.setAttribute('aria-pressed', String(dark));
        };
        applyTheme(localStorage.getItem('campuslf-admin-theme') === 'dark');
        toggle.addEventListener('click', () => {
            const dark = document.body.dataset.theme !== 'dark';
            localStorage.setItem('campuslf-admin-theme', dark ? 'dark' : 'light');
            applyTheme(dark);
        });
    })();
</script>
</body>
</html>
