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
    expect(dark.appBarTheme.backgroundColor, const Color(0xFF263B50));
  });

  test('lost and found surfaces use warm and cool palette colors', () {
    expect(CampusTheme.lostSurface, const Color(0xFFF9E1E2));
    expect(CampusTheme.foundSurface, const Color(0xFFC0E4EC));
    expect(CampusTheme.otherSurface, const Color(0xFFD3EFDD));
  });
}
