import 'package:dio/dio.dart';
import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../services/auth_service.dart';

class AuthScreen extends StatefulWidget {
  const AuthScreen({
    required this.api,
    required this.auth,
    required this.onSignedIn,
    super.key,
  });

  final ApiClient api;
  final AuthService auth;
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
  String? _message;
  String? _error;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _login() async {
    if (!_formKey.currentState!.validate() || _busy) return;
    setState(() {
      _busy = true;
      _error = null;
      _message = null;
    });
    try {
      final name = await widget.auth.login(_email.text, _password.text);
      if (mounted) widget.onSignedIn(name);
    } on DioException catch (error) {
      if (mounted) setState(() => _error = widget.api.errorMessage(error));
    } catch (error) {
      if (mounted) setState(() => _error = error.toString());
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _register() async {
    final result = await Navigator.of(context).push<_RegistrationResult>(
      MaterialPageRoute(
        builder: (_) => RegistrationScreen(api: widget.api, auth: widget.auth),
      ),
    );
    if (result == null || !mounted) return;
    setState(() {
      _email.text = result.email;
      _message = result.message;
    });
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
                    const _BrandMark(),
                    const SizedBox(height: 28),
                    Text('Welcome to CampusLF', style: Theme.of(context).textTheme.headlineSmall),
                    const SizedBox(height: 8),
                    const Text('Lost something? Found something? Let’s connect it at TMC.'),
                    const SizedBox(height: 28),
                    if (_message != null) _Notice(text: _message!, success: true),
                    if (_error != null) _Notice(text: _error!, success: false),
                    TextFormField(
                      controller: _email,
                      keyboardType: TextInputType.emailAddress,
                      textInputAction: TextInputAction.next,
                      autofillHints: const [AutofillHints.email],
                      decoration: const InputDecoration(labelText: 'TMC email *'),
                      validator: (value) {
                        if (value == null || !value.contains('@')) return 'Enter a valid email.';
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
                          tooltip: _showPassword ? 'Hide password' : 'Show password',
                          onPressed: () => setState(() => _showPassword = !_showPassword),
                          icon: Icon(_showPassword ? Icons.visibility_off : Icons.visibility),
                        ),
                      ),
                      validator: (value) => value == null || value.isEmpty ? 'Enter your password.' : null,
                    ),
                    const SizedBox(height: 20),
                    FilledButton(
                      onPressed: _busy ? null : _login,
                      child: _busy
                          ? const SizedBox.square(dimension: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                          : const Text('Log in'),
                    ),
                    const SizedBox(height: 10),
                    OutlinedButton(
                      onPressed: _busy ? null : _register,
                      style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(48)),
                      child: const Text('Create account'),
                    ),
                    const SizedBox(height: 18),
                    const Text(
                      'TMC CampusLF helps members connect. We do not keep, guarantee, or insure items. Report valuables to the official TMC office.',
                      textAlign: TextAlign.center,
                      style: TextStyle(fontSize: 12, color: Color(0xFF6B6262)),
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

class RegistrationScreen extends StatefulWidget {
  const RegistrationScreen({required this.api, required this.auth, super.key});

  final ApiClient api;
  final AuthService auth;

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
  String? _error;

  @override
  void initState() {
    super.initState();
    _loadCaptcha();
  }

  @override
  void dispose() {
    for (final controller in [
      _firstName, _lastName, _id, _email, _userTypeOther, _password, _confirm,
      _captchaAnswer, _otp,
    ]) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> _loadCaptcha() async {
    if (_captchaLoading) return;
    setState(() {
      _captchaLoading = true;
      _captchaToken = '';
      _captchaQuestion = 'Loading security check…';
      _captchaAnswer.clear();
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
      if (mounted) setState(() => _error = widget.api.errorMessage(error));
    } finally {
      if (mounted) setState(() => _captchaLoading = false);
    }
  }

  Future<void> _submit() async {
    if (_busy) return;
    if (!_otpSent && (_captchaLoading || _captchaToken.isEmpty)) {
      setState(() => _error = 'The security check is not ready. Tap refresh and try again.');
      return;
    }
    if (!_otpSent && !_formKey.currentState!.validate()) return;
    if (_otpSent && _otp.text.trim().length != 6) {
      setState(() => _error = 'Enter the six-digit code sent to your TMC email.');
      return;
    }
    if (!_otpSent && !_termsAccepted) {
      setState(() => _error = 'Accept the Terms, Privacy Notice, and Disclaimer to continue.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      if (!_otpSent) {
        await widget.auth.register(
          details: {
            'first_name': _firstName.text.trim(),
            'last_name': _lastName.text.trim(),
            'tmc_id_no': _id.text.trim(),
            'email': _email.text.trim(),
            'user_type': _userType,
            'user_type_other': _userType == 'other' ? _userTypeOther.text.trim() : null,
            'department': _department,
            'password': _password.text,
            'password_confirmation': _confirm.text,
          },
          captchaToken: _captchaToken,
          captchaAnswer: _captchaAnswer.text.trim(),
        );
        setState(() => _otpSent = true);
      } else {
        final message = await widget.auth.verifyOtp(_email.text, _otp.text.trim());
        if (mounted) Navigator.of(context).pop(_RegistrationResult(_email.text.trim(), message));
      }
    } on DioException catch (error) {
      if (mounted) setState(() => _error = widget.api.errorMessage(error));
      if (!_otpSent) await _loadCaptcha();
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  String? _required(String? value) => value == null || value.trim().isEmpty ? 'This field is required.' : null;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(_otpSent ? 'Verify your email' : 'Create account')),
      body: SafeArea(
        child: Form(
          key: _formKey,
          child: ListView(
            padding: const EdgeInsets.all(20),
            children: [
              if (_error != null) _Notice(text: _error!, success: false),
              if (_otpSent) ...[
                const Text('Enter the six-digit code sent to your TMC email. Unmatched masterlist records wait for administrator approval.'),
                const SizedBox(height: 20),
                TextFormField(
                  controller: _otp,
                  keyboardType: TextInputType.number,
                  maxLength: 6,
                  decoration: const InputDecoration(labelText: 'Verification code'),
                ),
                TextButton(
                  onPressed: _busy ? null : () async {
                    try {
                      await widget.api.dio.post<void>('/resend-otp', data: {'email': _email.text.trim()});
                    } on DioException catch (error) {
                      if (mounted) setState(() => _error = widget.api.errorMessage(error));
                    }
                  },
                  child: const Text('Resend code'),
                ),
              ] else ...[
                TextFormField(controller: _firstName, decoration: const InputDecoration(labelText: 'First name *'), validator: _required),
                const SizedBox(height: 12),
                TextFormField(controller: _lastName, decoration: const InputDecoration(labelText: 'Last name *'), validator: _required),
                const SizedBox(height: 12),
                TextFormField(controller: _id, decoration: const InputDecoration(labelText: 'TMC ID number *'), validator: _required),
                const SizedBox(height: 12),
                TextFormField(
                  controller: _email,
                  keyboardType: TextInputType.emailAddress,
                  decoration: const InputDecoration(labelText: 'Gmail address *'),
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
                    DropdownMenuItem(value: 'instructor', child: Text('Instructor')),
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
                    decoration: const InputDecoration(labelText: 'Please Specify user type *'),
                    validator: _required,
                  ),
                  const SizedBox(height: 12),
                ],
                DropdownButtonFormField<String>(
                  initialValue: _department,
                  decoration: const InputDecoration(labelText: 'Department / program *'),
                  items: const [
                    DropdownMenuItem(value: 'CCS', child: Text('CCS')),
                    DropdownMenuItem(value: 'CRIM', child: Text('CRIM')),
                    DropdownMenuItem(value: 'POLSCI', child: Text('POLSCI')),
                    DropdownMenuItem(value: 'CAS', child: Text('CAS')),
                    DropdownMenuItem(value: 'BSOA', child: Text('BSOA')),
                    DropdownMenuItem(value: 'BSED', child: Text('BSED')),
                  ],
                  onChanged: (value) => setState(() => _department = value ?? 'CCS'),
                ),
                const SizedBox(height: 12),
                TextFormField(controller: _password, obscureText: true, decoration: const InputDecoration(labelText: 'Password (10+ characters) *'), validator: (value) => value != null && value.length >= 10 ? null : 'Use at least 10 characters.'),
                const SizedBox(height: 12),
                TextFormField(controller: _confirm, obscureText: true, decoration: const InputDecoration(labelText: 'Confirm password *'), validator: (value) => value == _password.text ? null : 'Passwords do not match.'),
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
                  onChanged: (value) => setState(() => _termsAccepted = value ?? false),
                  title: const Text('I accept the Terms and Privacy Notice. I understand CampusLF does not keep or insure items.'),
                  controlAffinity: ListTileControlAffinity.leading,
                ),
              ],
              const SizedBox(height: 12),
              FilledButton(
                onPressed: _busy ? null : _submit,
                child: _busy
                    ? const SizedBox.square(dimension: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                    : Text(_otpSent ? 'Verify email' : 'Continue to email verification'),
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
        Container(
          width: 52,
          height: 52,
          decoration: BoxDecoration(color: const Color(0xFFF2B705), borderRadius: BorderRadius.circular(14)),
          child: const Center(child: Text('LF', style: TextStyle(color: Color(0xFF7A0F18), fontSize: 20, fontWeight: FontWeight.w800))),
        ),
        const SizedBox(width: 12),
        const Text('TMC CampusLF', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
      ],
    );
  }
}

class _Notice extends StatelessWidget {
  const _Notice({required this.text, required this.success});
  final String text;
  final bool success;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 14),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: success ? const Color(0xFFE8F5E9) : const Color(0xFFFFEBEE),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Text(text, style: TextStyle(color: success ? const Color(0xFF2E7D32) : const Color(0xFFC62828))),
    );
  }
}
