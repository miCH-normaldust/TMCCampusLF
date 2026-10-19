<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin sign in - TMC CampusLF</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root {
          --primary: #a31621;
          --primary-dark: #7a0f18;
          --gold: #f2b705;
          --background: #faf7f5;
          --border: #e6dfdc;
          --text: #1f1a1a;
          --error: #c62828;
          --success: #2e7d32;
        }
        * { box-sizing: border-box; }
        body {
          margin: 0;
          min-height: 100vh;
          display: grid;
          place-items: center;
          background: var(--background);
          font: 15px Inter, sans-serif;
          color: var(--text);
          padding: 24px;
        }
        main {
          width: min(100%, 420px);
          background: var(--background);
          border-radius: 16px;
          padding: 36px;
          box-shadow: 0 18px 50px rgba(0, 0, 0, 0.3);
        }
        h1 {
          font: 700 24px Poppins, sans-serif;
          margin: 0 0 8px;
          color: var(--primary);
        }
        .muted {
          color: #6b6262;
          margin: 0 0 26px;
        }
        label {
          display: block;
          font-weight: 600;
          margin: 16px 0 7px;
        }
        input {
          width: 100%;
          height: 48px;
          border: 1px solid var(--border);
          border-radius: 10px;
          padding: 0 12px;
          font: inherit;
        }
        input:focus {
          outline: 3px solid var(--primary);
          border-color: var(--primary);
        }
        .button {
          margin-top: 22px;
          background: var(--primary);
          color: white;
          border: 0;
          border-radius: 10px;
          height: 48px;
          width: 100%;
          font: 600 15px Inter, sans-serif;
          cursor: pointer;
        }
        .error {
          color: var(--error);
          margin-top: 7px;
        }
        .brand {
          display: flex;
          align-items: center;
          gap: 12px;
          margin-bottom: 24px;
        }
        .mark {
          width: 44px;
          height: 44px;
          border-radius: 12px;
          background: var(--gold);
          display: grid;
          place-items: center;
          font: 700 18px Poppins;
          color: var(--primary-dark);
        }
        *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#7a0f18;font:15px Inter,sans-serif;color:#1f1a1a;padding:24px}
        main{width:min(100%,420px);background:#fff;border-radius:16px;padding:36px;box-shadow:0 18px 50px #30070b55}
        h1{font:700 24px Poppins,sans-serif;margin:0 0 8px;color:#a31621}.muted{color:#6b6262;margin:0 0 26px}
        label{display:block;font-weight:600;margin:16px 0 7px}input{width:100%;height:48px;border:1px solid #e6dfdc;border-radius:10px;padding:0 12px;font:inherit}
        input:focus{outline:3px solid #f2b70566;border-color:#a31621}.button{margin-top:22px;background:#a31621;color:white;border:0;border-radius:10px;height:48px;width:100%;font:600 15px Inter,sans-serif;cursor:pointer}
        .error{color:#c62828;margin-top:7px}.brand{display:flex;align-items:center;gap:12px;margin-bottom:24px}.mark{width:44px;height:44px;border-radius:12px;background:#f2b705;display:grid;place-items:center;font:700 18px Poppins;color:#7a0f18}
    </style>
</head>
<body>
<main>
    <div class="brand"><span class="mark" aria-hidden="true">LF</span><strong>TMC CampusLF</strong></div>
    <h1>Admin portal</h1>
    <p class="muted">Sign in with your administrator account.</p>
    @if ($errors->any())
        <div class="error" role="alert">{{ $errors->first() }}</div>
    @endif
    <form method="post" action="{{ route('admin.login.store') }}">
        @csrf
        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required>
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <label for="captcha_answer">Security check: {{ $captchaQuestion }}</label>
        <input id="captcha_answer" name="captcha_answer" inputmode="numeric" autocomplete="off" required>
        <button class="button" type="submit">Sign in</button>
    </form>
</main>
</body>
</html>
