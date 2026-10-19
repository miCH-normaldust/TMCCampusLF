import 'package:flutter/material.dart';

class CampusTheme {
  static const primary = Color(0xFFA31621);
  static const primaryDark = Color(0xFF7A0F18);
  static const gold = Color(0xFFF2B705);
  static const background = Color(0xFFFAF7F5);
  static const border = Color(0xFFE6DFDC);

  static ThemeData build() {
    final colors = ColorScheme.fromSeed(
      seedColor: primary,
      primary: primary,
      secondary: gold,
      surface: Colors.white,
      error: const Color(0xFFC62828),
    );
    return ThemeData(
      useMaterial3: true,
      colorScheme: colors,
      scaffoldBackgroundColor: background,
      textTheme: ThemeData.light().textTheme
          .apply(fontFamily: 'sans-serif')
          .copyWith(
            headlineSmall: const TextStyle(
              fontFamily: 'sans-serif',
              fontWeight: FontWeight.w600,
              color: Color(0xFF1F1A1A),
            ),
            titleLarge: const TextStyle(
              fontFamily: 'sans-serif',
              fontWeight: FontWeight.w600,
              color: Color(0xFF1F1A1A),
            ),
          ),
      appBarTheme: const AppBarTheme(
        backgroundColor: background,
        foregroundColor: Color(0xFF1F1A1A),
        surfaceTintColor: Colors.transparent,
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: Colors.white,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: border),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: border),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: primary, width: 2),
        ),
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 15),
      ),
      cardTheme: CardThemeData(
        color: Colors.white,
        elevation: 1,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
        margin: EdgeInsets.zero,
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size.fromHeight(48),
          backgroundColor: primary,
          foregroundColor: Colors.white,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        ),
      ),
    );
  }
}
