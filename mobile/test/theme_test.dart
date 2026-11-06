import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tmc_campuslf_mobile/core/theme.dart';

void main() {
  test('light and dark themes use the requested mode and colored headers', () {
    final light = CampusTheme.build();
    final dark = CampusTheme.build(dark: true);

    expect(light.brightness, Brightness.light);
    expect(light.appBarTheme.backgroundColor, CampusTheme.header);
    expect(dark.brightness, Brightness.dark);
    expect(dark.appBarTheme.backgroundColor, const Color(0xFF10134C));
    expect(light.textTheme.bodyMedium?.fontFamily, 'Georgia');
    expect(dark.textTheme.bodyMedium?.fontFamily, 'Georgia');
  });

  test('lost and found surfaces use warm and cool palette colors', () {
    expect(CampusTheme.lostSurface, const Color(0xFFF4E2F2));
    expect(CampusTheme.foundSurface, const Color(0xFFD8F1F5));
    expect(CampusTheme.otherSurface, const Color(0xFFE0E7FC));
  });
}
