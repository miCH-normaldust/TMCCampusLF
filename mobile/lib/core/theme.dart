import 'package:flutter/material.dart';

class CampusTheme {
  static const primary = Color(0xFF7C44BC);
  static const primaryDark = Color(0xFF3F278A);
  static const found = Color(0xFF287C91);
  static const foundDark = Color(0xFF96CCF9);
  static const foundSurface = Color(0xFFD8F1F5);
  static const foundSurfaceDark = Color(0xFF173C55);
  static const lost = Color(0xFF9A477F);
  static const lostDark = Color(0xFFB48CF2);
  static const lostSurface = Color(0xFFF4E2F2);
  static const lostSurfaceDark = Color(0xFF48264E);
  static const other = Color(0xFF3F56AE);
  static const otherDark = Color(0xFFC8DEF8);
  static const otherSurface = Color(0xFFE0E7FC);
  static const otherSurfaceDark = Color(0xFF292C69);
  static const background = Color(0xFFF0F1FC);
  static const header = Color(0xFFE4E5FB);
  static const border = Color(0xFFD2D4F2);

  static ThemeData build({bool dark = false}) {
    final surface = dark ? const Color(0xFF141747) : const Color(0xFFFFFEFF);
    final pageBackground = dark ? const Color(0xFF060941) : background;
    final foreground = dark ? const Color(0xFFF3F2FF) : const Color(0xFF22234E);
    final outline = dark ? const Color(0xFF3539A4) : border;
    final colors =
        ColorScheme.fromSeed(
          brightness: dark ? Brightness.dark : Brightness.light,
          seedColor: primary,
          primary: dark ? const Color(0xFFB48CF2) : primary,
          secondary: dark ? const Color(0xFF96CCF9) : const Color(0xFF3F56AE),
          tertiary: const Color(0xFF64B4C4),
          surface: surface,
          error: lost,
        ).copyWith(
          onPrimary: dark ? const Color(0xFF211043) : Colors.white,
          primaryContainer: dark
              ? const Color(0xFF3F278A)
              : const Color(0xFFEBDDFA),
          onPrimaryContainer: dark
              ? const Color(0xFFF0E8FF)
              : const Color(0xFF35205A),
          secondaryContainer: dark
              ? const Color(0xFF1C1D6F)
              : const Color(0xFFE0E7FC),
          onSecondaryContainer: dark
              ? const Color(0xFFE5E7FF)
              : const Color(0xFF252C68),
          surface: surface,
          onSurface: foreground,
          outline: outline,
          error: dark ? const Color(0xFFFFB4C7) : const Color(0xFF9A477F),
        );
    return ThemeData(
      useMaterial3: true,
      colorScheme: colors,
      scaffoldBackgroundColor: pageBackground,
      canvasColor: surface,
      dividerColor: dark ? const Color(0xFF292D72) : const Color(0xFFE1E2F5),
      iconTheme: IconThemeData(color: colors.primary),
      textTheme: (dark ? ThemeData.dark() : ThemeData.light()).textTheme
          .apply(
            fontFamily: 'Georgia',
            bodyColor: foreground,
            displayColor: foreground,
          )
          .copyWith(
            headlineSmall: TextStyle(
              fontFamily: 'Georgia',
              fontWeight: FontWeight.w700,
              color: foreground,
            ),
            titleLarge: TextStyle(
              fontFamily: 'Georgia',
              fontWeight: FontWeight.w700,
              color: foreground,
            ),
          ),
      appBarTheme: AppBarTheme(
        backgroundColor: dark ? const Color(0xFF10134C) : header,
        foregroundColor: foreground,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        centerTitle: false,
        iconTheme: IconThemeData(color: colors.primary),
        titleTextStyle: TextStyle(
          color: foreground,
          fontSize: 20,
          fontWeight: FontWeight.w700,
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: dark ? const Color(0xFF101342) : const Color(0xFFF9F8FF),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(16),
          borderSide: BorderSide(color: outline),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(16),
          borderSide: BorderSide(color: outline),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(16),
          borderSide: BorderSide(color: colors.primary, width: 2),
        ),
        contentPadding: const EdgeInsets.symmetric(
          horizontal: 16,
          vertical: 15,
        ),
      ),
      cardTheme: CardThemeData(
        color: surface,
        elevation: 2,
        shadowColor: dark ? const Color(0x66000020) : const Color(0x222C2480),
        surfaceTintColor: Colors.transparent,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        margin: EdgeInsets.zero,
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: dark
            ? const Color(0xFF101342)
            : const Color(0xFFF9F8FF),
        indicatorColor: dark
            ? const Color(0xFF3F278A)
            : const Color(0xFFEBDDFA),
        elevation: 8,
        shadowColor: dark ? Colors.black54 : const Color(0x223F278A),
        labelTextStyle: WidgetStateProperty.resolveWith(
          (states) => TextStyle(
            fontWeight: states.contains(WidgetState.selected)
                ? FontWeight.w700
                : FontWeight.w500,
            color: states.contains(WidgetState.selected)
                ? colors.primary
                : colors.onSurfaceVariant,
          ),
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size.fromHeight(50),
          backgroundColor: colors.primary,
          foregroundColor: colors.onPrimary,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(16),
          ),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: colors.primary,
          side: BorderSide(color: colors.primary.withValues(alpha: 0.55)),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(16),
          ),
        ),
      ),
    );
  }
}
