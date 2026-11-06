import 'package:flutter/material.dart';

class CampusLfBrandMark extends StatelessWidget {
  const CampusLfBrandMark({this.size = 32, super.key});

  final double size;

  @override
  Widget build(BuildContext context) {
    final logoSize = size * 1.15;
    final campusLogoSize = size * 0.83;
    return SizedBox(
      width: size * 1.55,
      height: size * 1.55,
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          Positioned(
            top: 0,
            left: 0,
            child: Container(
              width: logoSize,
              height: logoSize,
              padding: const EdgeInsets.all(2),
              decoration: BoxDecoration(
                color: Colors.white,
                shape: BoxShape.circle,
                boxShadow: [
                  BoxShadow(
                    color: Theme.of(context).colorScheme.primary
                        .withValues(alpha: 0.2),
                    blurRadius: 8,
                    offset: const Offset(0, 2),
                  ),
                ],
              ),
              child: ClipOval(
                child: Image.asset('assets/tmc_logo.png', fit: BoxFit.contain),
              ),
            ),
          ),
          Positioned(
            right: 0,
            bottom: 0,
            child: Image.asset(
              'assets/campuslf_logo.png',
              width: campusLogoSize,
              height: campusLogoSize,
              fit: BoxFit.contain,
            ),
          ),
        ],
      ),
    );
  }
}
