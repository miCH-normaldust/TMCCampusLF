@if ($purpose === 'password_reset')
Use this code to reset your TMC CampusLF password: {{ $code }}.
@else
Your TMC CampusLF email verification code is {{ $code }}.
@endif

It expires in 10 minutes. If you did not request this code, you can ignore this email.
