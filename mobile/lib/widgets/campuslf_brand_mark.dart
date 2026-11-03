import 'package:flutter/material.dart';

class CampusLfBrandMark extends StatelessWidget {
  const CampusLfBrandMark({this.size = 32, super.key});

  final double size;

  @override
  Widget build(BuildContext context) {
    final sealSize = size * 0.78;
    final overlap = size * 0.23;

    return SizedBox(
      width: size + 8,
      height: size + sealSize - overlap,
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          Positioned(
            top: 0,
            left: 0,
            child: Container(
              width: size,
              height: size,
              decoration: BoxDecoration(
                color: const Color(0xFFFDB713),
                borderRadius: BorderRadius.circular(size * 0.25),
              ),
              alignment: Alignment.center,
              child: Text(
                'LF',
                style: TextStyle(
                  color: const Color(0xFF082F4F),
                  fontSize: size * 0.42,
                  fontWeight: FontWeight.w800,
                ),
              ),
            ),
          ),
          Positioned(
            right: 0,
            bottom: 0,
            child: Container(
              width: sealSize,
              height: sealSize,
              decoration: const BoxDecoration(
                color: Colors.white,
                shape: BoxShape.circle,
              ),
              padding: const EdgeInsets.all(2),
              child: ClipOval(
                child: Image.asset('assets/tmc_logo.png', fit: BoxFit.contain),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
