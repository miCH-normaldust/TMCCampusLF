import 'package:flutter/material.dart';

class CampusTheme {
  static const primary = Color(0xFF47749A);
  static const primaryDark = Color(0xFF2C4E6A);
  static const found = Color(0xFF39778A);
  static const foundSurface = Color(0xFFC0E4EC);
  static const foundSurfaceDark = Color(0xFF29444D);
  static const lost = Color(0xFFA74E58);
  static const lostSurface = Color(0xFFF9E1E2);
  static const lostSurfaceDark = Color(0xFF4A3036);
  static const other = Color(0xFF4C715A);
  static const otherSurface = Color(0xFFD3EFDD);
  static const otherSurfaceDark = Color(0xFF2C4034);
  static const background = Color(0xFFF4F4ED);
  static const header = Color(0xFFBEDBFB);
  static const border = Color(0xFFC0E4EC);

  static ThemeData build({bool dark = false}) {
    final surface = dark ? const Color(0xFF202A35) : Colors.white;
    final pageBackground = dark ? const Color(0xFF151B24) : background;
    final foreground = dark ? const Color(0xFFF4F4ED) : const Color(0xFF26323D);
    final outline = dark ? const Color(0xFF3A4A59) : border;
    final colors = ColorScheme.fromSeed(
      brightness: dark ? Brightness.dark : Brightness.light,
      seedColor: primary,
      primary: dark ? const Color(0xFFBEDBFB) : primary,
      secondary: dark ? const Color(0xFFF6CBE1) : const Color(0xFF8A617F),
      surface: surface,
      error: lost,
    );
    return ThemeData(
      useMaterial3: true,
      colorScheme: colors,
      scaffoldBackgroundColor: pageBackground,
      textTheme: (dark ? ThemeData.dark() : ThemeData.light()).textTheme
          .apply(fontFamily: 'sans-serif')
          .copyWith(
            headlineSmall: TextStyle(
              fontFamily: 'sans-serif',
              fontWeight: FontWeight.w600,
              color: foreground,
            ),
            titleLarge: TextStyle(
              fontFamily: 'sans-serif',
              fontWeight: FontWeight.w600,
              color: foreground,
            ),
          ),
      appBarTheme: AppBarTheme(
        backgroundColor: dark ? const Color(0xFF263B50) : header,
        foregroundColor: dark ? foreground : const Color(0xFF26323D),
        surfaceTintColor: Colors.transparent,
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: surface,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: BorderSide(color: outline),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: BorderSide(color: outline),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: BorderSide(color: colors.primary, width: 2),
        ),
        contentPadding: const EdgeInsets.symmetric(
          horizontal: 16,
          vertical: 15,
        ),
      ),
      cardTheme: CardThemeData(
        color: surface,
        elevation: 1,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
        margin: EdgeInsets.zero,
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: surface,
        indicatorColor: dark
            ? const Color(0xFF36516C)
            : const Color(0xFFD3EFDD),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size.fromHeight(48),
          backgroundColor: colors.primary,
          foregroundColor: dark ? const Color(0xFF17222D) : Colors.white,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
          ),
        ),
      ),
    );
  }
}
