import 'package:flutter/material.dart';

import 'core/api_client.dart';
import 'core/secure_token_store.dart';
import 'core/theme.dart';
import 'screens/auth_screen.dart';
import 'screens/campus_home_screen.dart';
import 'services/auth_service.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const CampusLfApp());
}

class CampusLfApp extends StatefulWidget {
  const CampusLfApp({super.key});

  @override
  State<CampusLfApp> createState() => _CampusLfAppState();
}

class _CampusLfAppState extends State<CampusLfApp> {
  final _tokens = const SecureTokenStore();
  late final _api = ApiClient(
    tokenStore: _tokens,
    onUnauthorized: _handleUnauthorized,
  );
  late final _auth = AuthService(_api, _tokens);
  late final Future<String?> _savedToken = _auth.readToken();
  String? _firstName;
  bool _signedIn = false;
  bool _forceSignedOut = false;
  ThemeMode _themeMode = ThemeMode.light;

  void _toggleTheme() {
    setState(() {
      _themeMode = _themeMode == ThemeMode.light
          ? ThemeMode.dark
          : ThemeMode.light;
    });
  }

  void _handleUnauthorized() {
    if (!mounted) return;
    setState(() {
      _signedIn = false;
      _firstName = null;
      _forceSignedOut = true;
    });
  }

  void _handleSignedOut() {
    setState(() {
      _signedIn = false;
      _firstName = null;
      _forceSignedOut = true;
    });
  }

  void _handleSignedIn(String name) {
    setState(() {
      _firstName = name;
      _signedIn = true;
      _forceSignedOut = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'TMC CampusLF',
      theme: CampusTheme.build(),
      darkTheme: CampusTheme.build(dark: true),
      themeMode: _themeMode,
      debugShowCheckedModeBanner: false,
      home: _signedIn
          ? CampusHomeScreen(
              api: _api,
              auth: _auth,
              firstName: _firstName ?? 'TMC member',
              isDarkMode: _themeMode == ThemeMode.dark,
              onToggleTheme: _toggleTheme,
              onSignedOut: _handleSignedOut,
            )
          : _forceSignedOut
          ? AuthScreen(api: _api, auth: _auth, onSignedIn: _handleSignedIn)
          : FutureBuilder<String?>(
              future: _savedToken,
              builder: (context, snapshot) {
                if (snapshot.connectionState != ConnectionState.done) {
                  return const Scaffold(
                    body: Center(child: CircularProgressIndicator()),
                  );
                }
                if (snapshot.hasError) {
                  return const Scaffold(
                    body: Center(
                      child: Text(
                        'Secure storage is unavailable on this device.',
                      ),
                    ),
                  );
                }
                if (snapshot.data != null) {
                  _signedIn = true;
                  return CampusHomeScreen(
                    api: _api,
                    auth: _auth,
                    firstName: _firstName ?? 'TMC member',
                    isDarkMode: _themeMode == ThemeMode.dark,
                    onToggleTheme: _toggleTheme,
                    onSignedOut: _handleSignedOut,
                  );
                }
                return AuthScreen(
                  api: _api,
                  auth: _auth,
                  onSignedIn: _handleSignedIn,
                );
              },
            ),
    );
  }
}
