import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';

class CampusTheme {
  static const sky = Color(0xFFC9E9FF);
  static const blue = Color(0xFF9AC9E6);
  static const sand = Color(0xFFE9DFCF);
  static const cream = Color(0xFFFFF8EE);
  static const slate = Color(0xFF3E5663);

  static const primary = slate;
  static const primaryDark = slate;
  static const found = slate;
  static const foundDark = slate;
  static const foundSurface = sky;
  static const foundSurfaceDark = sky;
  static const lost = slate;
  static const lostDark = slate;
  static const lostSurface = cream;
  static const lostSurfaceDark = cream;
  static const other = slate;
  static const otherDark = slate;
  static const otherSurface = sand;
  static const otherSurfaceDark = sand;
  static const background = cream;
  static const header = sky;
  static const border = blue;

  static ThemeData build() {
    const surface = cream;
    const foreground = slate;
    const outline = border;
    final colors =
        ColorScheme.fromSeed(
          brightness: Brightness.light,
          seedColor: primary,
          primary: primary,
          secondary: blue,
          tertiary: sand,
          surface: surface,
          error: primaryDark,
        ).copyWith(
          onPrimary: Colors.white,
          primaryContainer: sky,
          onPrimaryContainer: primaryDark,
          secondaryContainer: foundSurface,
          onSecondaryContainer: slate,
          onSurface: foreground,
          outline: outline,
          error: primaryDark,
          surfaceContainerLowest: cream,
          surfaceContainerLow: cream,
          surfaceContainer: const Color(0xFFFFFCF7),
          surfaceContainerHigh: sand,
          surfaceContainerHighest: sky,
        );

    return ThemeData(
      useMaterial3: true,
      colorScheme: colors,
      scaffoldBackgroundColor: background,
      canvasColor: surface,
      fontFamily: defaultTargetPlatform == TargetPlatform.iOS
          ? '.SF Pro Text'
          : 'Roboto',
      fontFamilyFallback: const ['.SF UI Text', 'SF Pro Text', 'Roboto'],
      dividerColor: sand,
      iconTheme: const IconThemeData(color: primary),
      appBarTheme: const AppBarTheme(
        backgroundColor: header,
        foregroundColor: foreground,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        centerTitle: false,
        iconTheme: IconThemeData(color: primary),
        titleTextStyle: TextStyle(
          color: foreground,
          fontSize: 20,
          fontWeight: FontWeight.w700,
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: const Color(0xFFFFFCF7),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(18),
          borderSide: const BorderSide(color: outline),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(18),
          borderSide: const BorderSide(color: outline),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(18),
          borderSide: const BorderSide(color: primary, width: 2),
        ),
        contentPadding: const EdgeInsets.symmetric(
          horizontal: 16,
          vertical: 15,
        ),
      ),
      cardTheme: CardThemeData(
        color: surface,
        elevation: 2,
        shadowColor: const Color(0x223E5663),
        surfaceTintColor: Colors.transparent,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(22),
          side: const BorderSide(color: border),
        ),
        margin: EdgeInsets.zero,
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: cream,
        indicatorColor: sky,
        elevation: 6,
        height: 72,
        shadowColor: const Color(0x223E5663),
        surfaceTintColor: Colors.transparent,
        labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
        indicatorShape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(18),
        ),
        labelTextStyle: WidgetStateProperty.resolveWith(
          (states) => TextStyle(
            fontWeight: states.contains(WidgetState.selected)
                ? FontWeight.w700
                : FontWeight.w500,
            color: states.contains(WidgetState.selected)
                ? primary
                : colors.onSurfaceVariant,
          ),
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size.fromHeight(50),
          backgroundColor: primary,
          foregroundColor: Colors.white,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(18),
          ),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: primary,
          side: const BorderSide(color: border),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(18),
          ),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: primary,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(14),
          ),
        ),
      ),
      snackBarTheme: SnackBarThemeData(
        backgroundColor: slate,
        contentTextStyle: const TextStyle(color: Colors.white),
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      ),
    );
  }
}
