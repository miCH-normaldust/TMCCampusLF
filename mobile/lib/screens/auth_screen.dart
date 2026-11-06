import 'package:dio/dio.dart';
import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/theme.dart';
import '../services/auth_service.dart';
import '../widgets/campuslf_brand_mark.dart';

class AuthScreen extends StatefulWidget {
  const AuthScreen({
    required this.api,
    required this.auth,
    required this.isDarkMode,
    required this.onToggleTheme,
    required this.onSignedIn,
    super.key,
  });

  final ApiClient api;
  final AuthService auth;
  final bool isDarkMode;
  final VoidCallback onToggleTheme;
  final void Function(String firstName) onSignedIn;

  @override
  State<AuthScreen> createState() => _AuthScreenState();
}

class _AuthScreenState extends State<AuthScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _formKey = GlobalKey<FormState>();
  bool _busy = false;
  bool _showPassword = false;
  bool _showVerifyAction = false;
  String? _message;
  String? _error;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _login() async {
    if (_busy) return;
    final isValid = _formKey.currentState!.validate();
    setState(() {
      _error = null;
      _message = null;
      _showVerifyAction = false;
    });
    if (!isValid) return;
    setState(() => _busy = true);
    try {
      final name = await widget.auth.login(_email.text, _password.text);
      if (mounted) widget.onSignedIn(name);
    } on DioException catch (error) {
      if (mounted) {
        final message = widget.api.errorMessage(error);
        setState(() {
          _error = message;
          _showVerifyAction =
              error.response?.statusCode == 403 &&
              message == 'Verify your TMC email before signing in.';
        });
      }
    } catch (error) {
      if (mounted) setState(() => _error = error.toString());
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _register() async {
    final result = await Navigator.of(context).push<_RegistrationResult>(
      MaterialPageRoute(
        builder: (_) => RegistrationScreen(
          api: widget.api,
          auth: widget.auth,
          isDarkMode: widget.isDarkMode,
          onToggleTheme: widget.onToggleTheme,
        ),
      ),
    );
    if (result == null || !mounted) return;
    setState(() {
      _email.text = result.email;
      _message = result.message;
      _error = null;
    });
  }

  Future<void> _forgotPassword() async {
    final message = await Navigator.of(context).push<String>(
      MaterialPageRoute(
        builder: (_) => ForgotPasswordScreen(
          api: widget.api,
          auth: widget.auth,
          isDarkMode: widget.isDarkMode,
          onToggleTheme: widget.onToggleTheme,
        ),
      ),
    );
    if (message != null && mounted) {
      setState(() {
        _message = message;
        _error = null;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 460),
              child: Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const _BrandMark(),
                        _ThemeToggle(
                          isDarkMode: widget.isDarkMode,
                          onToggle: widget.onToggleTheme,
                        ),
                      ],
                    ),
                    const SizedBox(height: 28),
                    Text(
                      'Welcome to CampusLF',
                      style: Theme.of(context).textTheme.headlineSmall,
                    ),
                    const SizedBox(height: 8),
                    const Text(
                      'Lost something? Found something? Let’s connect it at TMC.',
                    ),
                    const SizedBox(height: 28),
                    if (_message != null)
                      _Notice(text: _message!, success: true),
                    if (_error != null) _Notice(text: _error!, success: false),
                    if (_showVerifyAction)
                      TextButton(
                        onPressed: _busy
                            ? null
                            : () async {
                                final result = await Navigator.of(context)
                                    .push<_RegistrationResult>(
                                      MaterialPageRoute(
                                        builder: (_) => RegistrationScreen(
                                          api: widget.api,
                                          auth: widget.auth,
                                          isDarkMode: widget.isDarkMode,
                                          onToggleTheme: widget.onToggleTheme,
                                          initialEmail: _email.text.trim(),
                                        ),
                                      ),
                                    );
                                if (!mounted || result == null) return;
                                setState(() {
                                  _email.text = result.email;
                                  _error = null;
                                  _showVerifyAction = false;
                                  _message = result.message;
                                });
                              },
                        child: const Text('Verify email or resend code'),
                      ),
                    TextFormField(
                      controller: _email,
                      keyboardType: TextInputType.emailAddress,
                      textInputAction: TextInputAction.next,
                      autofillHints: const [AutofillHints.email],
                      decoration: const InputDecoration(
                        labelText: 'TMC email *',
                      ),
                      validator: (value) {
                        if (value == null || !value.contains('@')) {
                          return 'Enter a valid email.';
                        }
                        return null;
                      },
                    ),
                    const SizedBox(height: 14),
                    TextFormField(
                      controller: _password,
                      obscureText: !_showPassword,
                      autofillHints: const [AutofillHints.password],
                      onFieldSubmitted: (_) => _login(),
                      decoration: InputDecoration(
                        labelText: 'Password *',
                        suffixIcon: IconButton(
                          tooltip: _showPassword
                              ? 'Hide password'
                              : 'Show password',
                          onPressed: () =>
                              setState(() => _showPassword = !_showPassword),
                          icon: Icon(
                            _showPassword
                                ? Icons.visibility_off
                                : Icons.visibility,
                          ),
                        ),
                      ),
                      validator: (value) => value == null || value.isEmpty
                          ? 'Enter your password.'
                          : null,
                    ),
                    Align(
                      alignment: Alignment.centerRight,
                      child: TextButton(
                        onPressed: _busy ? null : _forgotPassword,
                        child: const Text('Forgot password?'),
                      ),
                    ),
                    const SizedBox(height: 20),
                    FilledButton(
                      onPressed: _busy ? null : _login,
                      child: _busy
                          ? const SizedBox.square(
                              dimension: 20,
                              child: CircularProgressIndicator(
                                strokeWidth: 2,
                                color: Colors.white,
                              ),
                            )
                          : const Text('Log in'),
                    ),
                    const SizedBox(height: 10),
                    OutlinedButton(
                      onPressed: _busy ? null : _register,
                      style: OutlinedButton.styleFrom(
                        minimumSize: const Size.fromHeight(48),
                      ),
                      child: const Text('Create account'),
                    ),
                    const SizedBox(height: 18),
                    Text(
                      'TMC CampusLF helps members connect. We do not keep, guarantee, or insure items. Report valuables to the official TMC office.',
                      textAlign: TextAlign.center,
                      style: TextStyle(
                        fontSize: 12,
                        color: Theme.of(context).colorScheme.onSurfaceVariant,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class ForgotPasswordScreen extends StatefulWidget {
  const ForgotPasswordScreen({
    required this.api,
    required this.auth,
    required this.isDarkMode,
    required this.onToggleTheme,
    super.key,
  });

  final ApiClient api;
  final AuthService auth;
  final bool isDarkMode;
  final VoidCallback onToggleTheme;

  @override
  State<ForgotPasswordScreen> createState() => _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState extends State<ForgotPasswordScreen> {
  final _email = TextEditingController();
  final _code = TextEditingController();
  final _password = TextEditingController();
  final _confirm = TextEditingController();
  int _step = 0;
  bool _busy = false;
  bool _localOtpFallback = false;
  String? _resetToken;
  String? _message;
  String? _error;

  @override
  void dispose() {
    _email.dispose();
    _code.dispose();
    _password.dispose();
    _confirm.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_busy) return;
    final email = _email.text.trim();
    if (_step == 0 &&
        !RegExp(
          r'^[^@\s]+@gmail\.com$',
          caseSensitive: false,
        ).hasMatch(email)) {
      setState(
        () => _error = 'Enter the Gmail address connected to your account.',
      );
      return;
    }
    if (_step == 1 && !RegExp(r'^\d{6}$').hasMatch(_code.text.trim())) {
      setState(() => _error = 'Enter the six-digit code sent to your Gmail.');
      return;
    }
    if (_step == 2 && _password.text.length < 10) {
      setState(() => _error = 'Use at least 10 characters for your password.');
      return;
    }
    if (_step == 2 && _password.text != _confirm.text) {
      setState(() => _error = 'Passwords do not match.');
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
      _message = null;
    });
    try {
      if (_step == 0) {
        final message = await widget.auth.requestPasswordReset(email);
        if (mounted) {
          setState(() {
            _step = 1;
            _message = message;
            _localOtpFallback = _usesLocalOtpFallback(message);
          });
        }
      } else if (_step == 1) {
        _resetToken = await widget.auth.verifyPasswordResetOtp(
          email,
          _code.text.trim(),
        );
        if (mounted) {
          setState(() {
            _step = 2;
            _message = 'Code verified. Choose a new password.';
          });
        }
      } else {
        final message = await widget.auth.resetPassword(
          email: email,
          resetToken: _resetToken!,
          password: _password.text,
          passwordConfirmation: _confirm.text,
        );
        if (mounted) Navigator.of(context).pop(message);
      }
    } on DioException catch (error) {
      if (mounted) setState(() => _error = widget.api.errorMessage(error));
    } catch (error) {
      if (mounted) setState(() => _error = error.toString());
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _resendCode() async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _error = null;
      _message = null;
    });
    try {
      final message = await widget.auth.requestPasswordReset(_email.text);
      if (mounted) {
        setState(() {
          _message = message;
          _localOtpFallback = _usesLocalOtpFallback(message);
        });
      }
    } on DioException catch (error) {
      if (mounted) setState(() => _error = widget.api.errorMessage(error));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  bool _usesLocalOtpFallback(String message) =>
      message.contains('Gmail delivery is unavailable') ||
      message.contains('Local mail mode is enabled');

  @override
  Widget build(BuildContext context) {
    final titles = ['Reset password', 'Verify code', 'Choose new password'];
    return Scaffold(
      appBar: AppBar(
        title: Text(titles[_step]),
        actions: [
          _ThemeToggle(
            isDarkMode: widget.isDarkMode,
            onToggle: widget.onToggleTheme,
          ),
        ],
      ),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(20),
          children: [
            if (_error != null) _Notice(text: _error!, success: false),
            if (_message != null)
              _Notice(text: _message!, success: !_localOtpFallback),
            if (_step == 0) ...[
              const Text('Enter the Gmail address connected to your account.'),
              const SizedBox(height: 16),
              TextField(
                controller: _email,
                keyboardType: TextInputType.emailAddress,
                autofillHints: const [AutofillHints.email],
                decoration: const InputDecoration(labelText: 'Gmail address'),
              ),
            ] else if (_step == 1) ...[
              Text(
                _localOtpFallback
                    ? 'No email was sent. For local testing, find the latest password reset code in web/storage/logs/laravel.log.'
                    : 'Enter the six-digit code sent to ${_email.text.trim()}.',
              ),
              const SizedBox(height: 16),
              TextField(
                controller: _code,
                keyboardType: TextInputType.number,
                maxLength: 6,
                decoration: const InputDecoration(
                  labelText: 'Verification code',
                ),
              ),
              TextButton(
                onPressed: _busy ? null : _resendCode,
                child: const Text('Resend code'),
              ),
            ] else ...[
              TextField(
                controller: _password,
                obscureText: true,
                autofillHints: const [AutofillHints.newPassword],
                decoration: const InputDecoration(
                  labelText: 'New password (10+ characters)',
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _confirm,
                obscureText: true,
                decoration: const InputDecoration(
                  labelText: 'Confirm new password',
                ),
              ),
            ],
            const SizedBox(height: 20),
            FilledButton(
              onPressed: _busy ? null : _submit,
              child: _busy
                  ? const SizedBox.square(
                      dimension: 20,
                      child: CircularProgressIndicator(
                        strokeWidth: 2,
                        color: Colors.white,
                      ),
                    )
                  : Text(
                      ['Send code', 'Verify code', 'Save new password'][_step],
                    ),
            ),
          ],
        ),
      ),
    );
  }
}

class RegistrationScreen extends StatefulWidget {
  const RegistrationScreen({
    required this.api,
    required this.auth,
    required this.isDarkMode,
    required this.onToggleTheme,
    this.initialEmail,
    super.key,
  });

  final ApiClient api;
  final AuthService auth;
  final bool isDarkMode;
  final VoidCallback onToggleTheme;
  final String? initialEmail;

  @override
  State<RegistrationScreen> createState() => _RegistrationScreenState();
}

class _RegistrationScreenState extends State<RegistrationScreen> {
  final _formKey = GlobalKey<FormState>();
  final _firstName = TextEditingController();
  final _lastName = TextEditingController();
  final _id = TextEditingController();
  final _email = TextEditingController();
  final _userTypeOther = TextEditingController();
  final _password = TextEditingController();
  final _confirm = TextEditingController();
  final _captchaAnswer = TextEditingController();
  final _otp = TextEditingController();
  String _userType = 'student';
  String _department = 'CCS';
  String _captchaQuestion = 'Loading security check…';
  String _captchaToken = '';
  bool _termsAccepted = false;
  bool _busy = false;
  bool _captchaLoading = false;
  bool _otpSent = false;
  bool _localOtpFallback = false;
  bool _showPassword = false;
  bool _showConfirmPassword = false;
  String? _message;
  String? _error;

  @override
  void initState() {
    super.initState();
    if (widget.initialEmail != null) {
      _email.text = widget.initialEmail!;
      _otpSent = true;
    } else {
      _loadCaptcha();
    }
  }

  @override
  void dispose() {
    for (final controller in [
      _firstName,
      _lastName,
      _id,
      _email,
      _userTypeOther,
      _password,
      _confirm,
      _captchaAnswer,
      _otp,
    ]) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> _loadCaptcha({bool clearError = true}) async {
    if (_captchaLoading) return;
    setState(() {
      _captchaLoading = true;
      _captchaToken = '';
      _captchaQuestion = 'Loading security check…';
      _captchaAnswer.clear();
      if (clearError) _error = null;
    });
    try {
      final challenge = await widget.auth.captcha();
      if (mounted) {
        setState(() {
          _captchaQuestion = challenge['question']!;
          _captchaToken = challenge['token']!;
        });
      }
    } on DioException catch (error) {
      if (mounted) {
        setState(() {
          _captchaQuestion = 'Unavailable — tap refresh';
          _error = widget.api.errorMessage(error);
        });
      }
    } finally {
      if (mounted) setState(() => _captchaLoading = false);
    }
  }

  Future<void> _submit() async {
    if (_busy) return;
    if (!_otpSent && (_captchaLoading || _captchaToken.isEmpty)) {
      setState(
        () => _error =
            'The security check is not ready. Tap refresh and try again.',
      );
      return;
    }
    if (!_otpSent && !_formKey.currentState!.validate()) return;
    if (_otpSent && _otp.text.trim().length != 6) {
      setState(
        () => _error = 'Enter the six-digit code sent to your TMC email.',
      );
      return;
    }
    if (!_otpSent && !_termsAccepted) {
      setState(
        () => _error =
            'Accept the Terms, Privacy Notice, and Disclaimer to continue.',
      );
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
      _message = null;
    });
    var refreshCaptcha = false;
    try {
      if (!_otpSent) {
        final message = await widget.auth.register(
          details: {
            'first_name': _firstName.text.trim(),
            'last_name': _lastName.text.trim(),
            'tmc_id_no': _id.text.trim(),
            'email': _email.text.trim(),
            'user_type': _userType,
            'user_type_other': _userType == 'other'
                ? _userTypeOther.text.trim()
                : null,
            'department': _department,
            'password': _password.text,
            'password_confirmation': _confirm.text,
          },
          captchaToken: _captchaToken,
          captchaAnswer: _captchaAnswer.text.trim(),
        );
        if (!mounted) return;
        setState(() {
          _otpSent = true;
          _message = message;
          _localOtpFallback = _usesLocalOtpFallback(message);
        });
      } else {
        final message = await widget.auth.verifyOtp(
          _email.text,
          _otp.text.trim(),
        );
        if (mounted) {
          Navigator.of(context)
              .pop(_RegistrationResult(_email.text.trim(), message));
        }
      }
    } on DioException catch (error) {
      if (mounted) setState(() => _error = widget.api.errorMessage(error));
      refreshCaptcha = !_otpSent;
    } catch (error) {
      if (mounted) setState(() => _error = error.toString());
      refreshCaptcha = !_otpSent;
    } finally {
      if (mounted) setState(() => _busy = false);
    }
    if (!mounted) return;
    if (refreshCaptcha) await _loadCaptcha(clearError: false);
  }

  String? _required(String? value) =>
      value == null || value.trim().isEmpty ? 'This field is required.' : null;

  bool _usesLocalOtpFallback(String message) =>
      message.contains('Gmail delivery is unavailable') ||
      message.contains('Local mail mode is enabled');

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(_otpSent ? 'Verify your email' : 'Create account'),
        actions: [
          _ThemeToggle(
            isDarkMode: widget.isDarkMode,
            onToggle: widget.onToggleTheme,
          ),
        ],
      ),
      body: SafeArea(
        child: Form(
          key: _formKey,
          child: ListView(
            padding: const EdgeInsets.all(20),
            children: [
              if (_error != null) _Notice(text: _error!, success: false),
              if (_otpSent) ...[
                Text(
                  _localOtpFallback
                      ? 'No email was sent. For local testing, find the latest verification code in web/storage/logs/laravel.log.'
                      : 'Enter the six-digit code sent to your TMC email. Unmatched masterlist records wait for administrator approval.',
                ),
                const SizedBox(height: 20),
                TextFormField(
                  controller: _otp,
                  keyboardType: TextInputType.number,
                  maxLength: 6,
                  decoration: const InputDecoration(
                    labelText: 'Verification code',
                  ),
                ),
                if (_message != null)
                  _Notice(text: _message!, success: !_localOtpFallback),
                TextButton(
                  onPressed: _busy
                      ? null
                      : () async {
                          setState(() {
                            _busy = true;
                            _error = null;
                            _message = null;
                          });
                          try {
                            final message = await widget.auth.resendOtp(
                              _email.text,
                            );
                            if (mounted) {
                              setState(() {
                                _message = message;
                                _localOtpFallback = _usesLocalOtpFallback(
                                  message,
                                );
                              });
                            }
                          } on DioException catch (error) {
                            if (mounted) {
                              setState(
                                () => _error = widget.api.errorMessage(error),
                              );
                            }
                          } finally {
                            if (mounted) setState(() => _busy = false);
                          }
                        },
                  child: const Text('Resend code'),
                ),
              ] else ...[
                TextFormField(
                  controller: _firstName,
                  decoration: const InputDecoration(labelText: 'First name *'),
                  validator: _required,
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: _lastName,
                  decoration: const InputDecoration(labelText: 'Last name *'),
                  validator: _required,
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: _id,
                  decoration: const InputDecoration(
                    labelText: 'TMC ID number *',
                  ),
                  validator: _required,
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: _email,
                  keyboardType: TextInputType.emailAddress,
                  decoration: const InputDecoration(
                    labelText: 'Gmail address *',
                  ),
                  validator: (value) {
                    final email = value?.trim().toLowerCase() ?? '';
                    return email.contains('@') && email.endsWith('@gmail.com')
                        ? null
                        : 'Use a Gmail address ending in @gmail.com.';
                  },
                ),
                const SizedBox(height: 12),
                DropdownButtonFormField<String>(
                  initialValue: _userType,
                  decoration: const InputDecoration(labelText: 'User type *'),
                  items: const [
                    DropdownMenuItem(value: 'student', child: Text('Student')),
                    DropdownMenuItem(value: 'staff', child: Text('Staff')),
                    DropdownMenuItem(
                      value: 'instructor',
                      child: Text('Instructor'),
                    ),
                    DropdownMenuItem(value: 'other', child: Text('Others')),
                  ],
                  onChanged: (value) => setState(() {
                    _userType = value ?? 'student';
                    if (_userType != 'other') _userTypeOther.clear();
                  }),
                ),
                const SizedBox(height: 12),
                if (_userType == 'other') ...[
                  TextFormField(
                    controller: _userTypeOther,
                    decoration: const InputDecoration(
                      labelText: 'Please Specify user type *',
                    ),
                    validator: _required,
                  ),
                  const SizedBox(height: 12),
                ],
                DropdownButtonFormField<String>(
                  initialValue: _department,
                  decoration: const InputDecoration(
                    labelText: 'Department / program *',
                  ),
                  items: const [
                    DropdownMenuItem(value: 'CCS', child: Text('CCS')),
                    DropdownMenuItem(value: 'CRIM', child: Text('CRIM')),
                    DropdownMenuItem(value: 'POLSCI', child: Text('POLSCI')),
                    DropdownMenuItem(value: 'CAS', child: Text('CAS')),
                    DropdownMenuItem(value: 'BSOA', child: Text('BSOA')),
                    DropdownMenuItem(value: 'BSED', child: Text('BSED')),
                  ],
                  onChanged: (value) =>
                      setState(() => _department = value ?? 'CCS'),
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: _password,
                  obscureText: !_showPassword,
                  decoration: InputDecoration(
                    labelText: 'Password (10+ characters) *',
                    suffixIcon: IconButton(
                      tooltip: _showPassword
                          ? 'Hide password'
                          : 'Show password',
                      onPressed: () =>
                          setState(() => _showPassword = !_showPassword),
                      icon: Icon(
                        _showPassword ? Icons.visibility_off : Icons.visibility,
                      ),
                    ),
                  ),
                  validator: (value) => value != null && value.length >= 10
                      ? null
                      : 'Use at least 10 characters.',
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: _confirm,
                  obscureText: !_showConfirmPassword,
                  decoration: InputDecoration(
                    labelText: 'Confirm password *',
                    suffixIcon: IconButton(
                      tooltip: _showConfirmPassword
                          ? 'Hide password'
                          : 'Show password',
                      onPressed: () => setState(
                        () => _showConfirmPassword = !_showConfirmPassword,
                      ),
                      icon: Icon(
                        _showConfirmPassword
                            ? Icons.visibility_off
                            : Icons.visibility,
                      ),
                    ),
                  ),
                  validator: (value) => value == _password.text
                      ? null
                      : 'Passwords do not match.',
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: _captchaAnswer,
                  keyboardType: TextInputType.number,
                  maxLength: 2,
                  decoration: InputDecoration(
                    labelText: 'Security check: $_captchaQuestion *',
                    suffixIcon: IconButton(
                      tooltip: 'Refresh security check',
                      onPressed: _busy || _captchaLoading ? null : _loadCaptcha,
                      icon: _captchaLoading
                          ? const SizedBox.square(
                              dimension: 18,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : const Icon(Icons.refresh),
                    ),
                  ),
                  validator: _required,
                ),
                CheckboxListTile(
                  contentPadding: EdgeInsets.zero,
                  value: _termsAccepted,
                  onChanged: (value) =>
                      setState(() => _termsAccepted = value ?? false),
                  title: const Text(
                    'I accept the Terms and Privacy Notice. I understand CampusLF does not keep or insure items.',
                  ),
                  controlAffinity: ListTileControlAffinity.leading,
                ),
              ],
              const SizedBox(height: 12),
              FilledButton(
                onPressed: _busy ? null : _submit,
                child: _busy
                    ? const SizedBox.square(
                        dimension: 20,
                        child: CircularProgressIndicator(
                          strokeWidth: 2,
                          color: Colors.white,
                        ),
                      )
                    : Text(
                        _otpSent
                            ? 'Verify email'
                            : 'Continue to email verification',
                      ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _RegistrationResult {
  const _RegistrationResult(this.email, this.message);
  final String email;
  final String message;
}

class _BrandMark extends StatelessWidget {
  const _BrandMark();

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        const CampusLfBrandMark(size: 44),
        const SizedBox(width: 12),
        const Text(
          'TMC CampusLF',
          style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
        ),
      ],
    );
  }
}

class _ThemeToggle extends StatelessWidget {
  const _ThemeToggle({required this.isDarkMode, required this.onToggle});

  final bool isDarkMode;
  final VoidCallback onToggle;

  @override
  Widget build(BuildContext context) {
    return IconButton(
      tooltip: isDarkMode ? 'Switch to light mode' : 'Switch to dark mode',
      onPressed: onToggle,
      icon: Icon(isDarkMode ? Icons.light_mode : Icons.dark_mode),
    );
  }
}

class _Notice extends StatelessWidget {
  const _Notice({required this.text, required this.success});
  final String text;
  final bool success;

  @override
  Widget build(BuildContext context) {
    final successSurface = Theme.of(context).brightness == Brightness.dark
        ? CampusTheme.otherSurfaceDark
        : CampusTheme.otherSurface;
    final errorSurface = Theme.of(context).colorScheme.errorContainer;
    return Container(
      margin: const EdgeInsets.only(bottom: 14),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: success ? successSurface : errorSurface,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Text(
        text,
        style: TextStyle(
          color: success
              ? CampusTheme.other
              : Theme.of(context).colorScheme.onErrorContainer,
        ),
      ),
    );
  }
}
