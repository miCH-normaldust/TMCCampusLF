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
  late final _api = ApiClient(tokenStore: _tokens, onUnauthorized: _handleUnauthorized);
  late final _auth = AuthService(_api, _tokens);
  late final Future<String?> _savedToken = _auth.readToken();
  String? _firstName;
  bool _signedIn = false;

  void _handleUnauthorized() {
    if (!mounted) return;
    setState(() {
      _signedIn = false;
      _firstName = null;
    });
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'TMC CampusLF',
      theme: CampusTheme.build(),
      debugShowCheckedModeBanner: false,
      home: _signedIn
          ? CampusHomeScreen(
              api: _api,
              auth: _auth,
              firstName: _firstName ?? 'TMC member',
              onSignedOut: () => setState(() {
                _signedIn = false;
                _firstName = null;
              }),
            )
          : FutureBuilder<String?>(
              future: _savedToken,
              builder: (context, snapshot) {
                if (snapshot.connectionState != ConnectionState.done) {
                  return const Scaffold(body: Center(child: CircularProgressIndicator()));
                }
                if (snapshot.hasError) {
                  return const Scaffold(
                    body: Center(child: Text('Secure storage is unavailable on this device.')),
                  );
                }
                if (snapshot.data != null) {
                  _signedIn = true;
                  return CampusHomeScreen(
                    api: _api,
                    auth: _auth,
                    firstName: _firstName ?? 'TMC member',
                    onSignedOut: () => setState(() {
                      _signedIn = false;
                      _firstName = null;
                    }),
                  );
                }
                return AuthScreen(
                  api: _api,
                  auth: _auth,
                  onSignedIn: (name) => setState(() {
                    _firstName = name;
                    _signedIn = true;
                  }),
                );
              },
            ),
    );
  }
}
