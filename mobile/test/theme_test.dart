import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tmc_campuslf_mobile/core/theme.dart';

void main() {
  test('uses the campus lost-and-found light palette and platform fonts', () {
    final theme = CampusTheme.build();

    expect(theme.brightness, Brightness.light);
    expect(theme.appBarTheme.backgroundColor, CampusTheme.header);
    expect(theme.colorScheme.primary, CampusTheme.primary);
    expect(
      theme.textTheme.bodyMedium?.fontFamily,
      defaultTargetPlatform == TargetPlatform.iOS ? '.SF Pro Text' : 'Roboto',
    );
    expect(
      theme.textTheme.bodyMedium?.fontFamilyFallback,
      containsAll(['.SF UI Text', 'SF Pro Text', 'Roboto']),
    );
  });

  test('lost and found surfaces use distinct campus colors', () {
    expect(CampusTheme.sky, const Color(0xFFC9E9FF));
    expect(CampusTheme.blue, const Color(0xFF9AC9E6));
    expect(CampusTheme.sand, const Color(0xFFE9DFCF));
    expect(CampusTheme.cream, const Color(0xFFFFF8EE));
    expect(CampusTheme.slate, const Color(0xFF3E5663));
    expect(CampusTheme.foundSurface, CampusTheme.sky);
    expect(CampusTheme.lostSurface, CampusTheme.cream);
    expect(CampusTheme.otherSurface, CampusTheme.sand);
  });
}
