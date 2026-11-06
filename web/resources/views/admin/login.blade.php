<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin sign in - TMC CampusLF</title>
    @vite('resources/css/app.css')
    <style>
        body.admin-login {
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            background:
                radial-gradient(ellipse at 18% 18%, color-mix(in srgb, var(--campus-primary) 26%, transparent), transparent 34%),
                radial-gradient(ellipse at 82% 78%, #64B4C433, transparent 36%),
                linear-gradient(135deg, #E9ECFC, #DDE1FA 54%, #EEF5FF);
            color: var(--campus-text);
            font: 15px Georgia, serif;
        }
        .login-card {
            width: min(100%, 420px);
            padding: 34px;
            border: 1px solid var(--campus-border);
            border-radius: 18px;
            background: linear-gradient(150deg, #F8F8FF, #E4E5F8);
            box-shadow: 0 22px 60px #1C1D6F25, 0 2px 8px #3F278A12;
        }
        .login-card h1 {
            margin: 0 0 8px;
            color: var(--campus-primary-dark);
            font: 700 24px Georgia, serif;
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
            background: linear-gradient(110deg, #3F278A, #5C50B1);
            color: #fff;
            font: 600 15px Georgia, serif;
            cursor: pointer;
        }
        .login-card .login-submit:hover {
            background: linear-gradient(110deg, #7C44BC, #5C50B1);
            color: #fff;
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
        .brand-emblems {
            position: relative;
            display: block;
            flex: none;
            width: 55px;
            height: 55px;
            min-height: 55px;
        }
        .tmc-brand-logo {
            position: absolute;
            top: 0;
            left: 0;
            z-index: 1;
            width: 40px;
            height: 40px;
            object-fit: contain;
        }
        .campuslf-brand-logo {
            position: absolute;
            right: 0;
            bottom: 0;
            z-index: 2;
            width: 29px;
            height: 29px;
            object-fit: contain;
            filter: drop-shadow(0 2px 3px #1C1D6F24);
        }
        .login-brand strong {
            font: 700 16px Georgia, serif;
        }
        .login-card input {
            background: #FCFCFF;
        }
        .login-card .login-submit:focus-visible,
        .login-theme-toggle:focus-visible {
            outline: 3px solid #64B4C4;
            outline-offset: 3px;
        }
        .login-card .flash {
            color: #1C1D6F;
            background: #C8DEF8;
        }
        body[data-theme="dark"].admin-login {
            background:
                radial-gradient(ellipse at 18% 18%, #7C44BC55, transparent 36%),
                radial-gradient(ellipse at 82% 78%, #3539A433, transparent 38%),
                linear-gradient(135deg, #060941, #1C1D6F 60%, #10134B);
        }
        body[data-theme="dark"] .login-card {
            border-color: #3F56AE;
            background: linear-gradient(150deg, #222879, #11144C);
            box-shadow: 0 22px 60px #00000066;
        }
        body[data-theme="dark"] .login-card h1,
        body[data-theme="dark"] .login-brand {
            color: #C8DEF8;
        }
        body[data-theme="dark"] .login-card input {
            background: #10134B;
        }
        body[data-theme="dark"] .login-card .login-submit {
            background: linear-gradient(110deg, #7C44BC, #5C50B1);
            color: #fff;
        }
        body[data-theme="dark"] .login-card .login-submit:hover {
            background: linear-gradient(110deg, #B48CF2, #5F84DB);
            color: #060941;
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
